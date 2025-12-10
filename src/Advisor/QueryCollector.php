<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor;

use AlexandreBulete\Benchmark\Advisor\DTO\CollectedQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Collects SQL queries during benchmark execution
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class QueryCollector
{
    /** @var Collection<int, CollectedQuery> */
    private Collection $queries;

    private bool $listening = false;

    private ?\Closure $listener = null;

    public function __construct()
    {
        $this->queries = collect();
    }

    /**
     * Start collecting queries
     */
    public function start(): void
    {
        if ($this->listening) {
            return;
        }

        $this->queries = collect();
        $this->listening = true;

        $this->listener = function (QueryExecuted $event): void {
            $this->recordQuery($event);
        };

        DB::listen($this->listener);
    }

    /**
     * Stop collecting queries
     */
    public function stop(): void
    {
        if (! $this->listening) {
            return;
        }

        $this->listening = false;

        // Note: DB::listen() doesn't have an "unlisten" method
        // The listener will be garbage collected when this object is destroyed
        // For now, we set a flag to ignore new queries
    }

    /**
     * Record a query
     */
    private function recordQuery(QueryExecuted $event): void
    {
        if (! $this->listening) {
            return;
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);

        $query = CollectedQuery::fromEvent(
            sql: $event->sql,
            bindings: $event->bindings,
            time: $event->time,
            connection: $event->connectionName,
            backtrace: $backtrace
        );

        $this->queries->push($query);
    }

    /**
     * Get all collected queries
     *
     * @return Collection<int, CollectedQuery>
     */
    public function getQueries(): Collection
    {
        return $this->queries;
    }

    /**
     * Get query count
     */
    public function getQueryCount(): int
    {
        return $this->queries->count();
    }

    /**
     * Get total time spent in DB (in milliseconds)
     */
    public function getTotalTime(): float
    {
        return $this->queries->sum('time');
    }

    /**
     * Get unique query count (normalized)
     */
    public function getUniqueQueryCount(): int
    {
        return $this->queries->unique('normalized_sql')->count();
    }

    /**
     * Group queries by normalized SQL
     *
     * @return Collection<string, Collection<int, CollectedQuery>>
     */
    public function groupByNormalizedSql(): Collection
    {
        return $this->queries->groupBy('normalized_sql');
    }

    /**
     * Group queries by location (class::method or file:line)
     *
     * @return Collection<string, Collection<int, CollectedQuery>>
     */
    public function groupByLocation(): Collection
    {
        return $this->queries->groupBy(fn (CollectedQuery $q) => $q->getLocationString());
    }

    /**
     * Get queries slower than threshold (in ms)
     *
     * @return Collection<int, CollectedQuery>
     */
    public function getSlowQueries(float $threshold_ms): Collection
    {
        return $this->queries->filter(fn (CollectedQuery $q) => $q->time > $threshold_ms);
    }

    /**
     * Reset the collector
     */
    public function reset(): void
    {
        $this->queries = collect();
    }

    /**
     * Check if currently collecting
     */
    public function isListening(): bool
    {
        return $this->listening;
    }
}
