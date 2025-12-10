<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorReport;
use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use AlexandreBulete\Benchmark\Advisor\Rules\AdvisorRule;
use AlexandreBulete\Benchmark\Advisor\Rules\DuplicateQueryRule;
use AlexandreBulete\Benchmark\Advisor\Rules\HotspotRule;
use AlexandreBulete\Benchmark\Advisor\Rules\NPlusOneRule;
use AlexandreBulete\Benchmark\Advisor\Rules\SlowQueryRule;
use Illuminate\Support\Collection;

/**
 * Main Advisor class that analyzes benchmark queries and provides suggestions
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class Advisor
{
    private QueryCollector $collector;

    private bool $enabled = true;

    private array $config;

    /** @var array<AdvisorRule> */
    private array $rules = [];

    public function __construct(?QueryCollector $collector = null)
    {
        $this->collector = $collector ?? new QueryCollector;
        $this->config = $this->getDefaultConfig();
        $this->registerDefaultRules();
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return config('benchmark.advisor', [
            'enabled' => true,
            'rules' => [
                'n_plus_one' => [
                    'enabled' => true,
                    'threshold' => 10,
                    'critical_count' => 100,
                    'critical_time_ms' => 1000,
                ],
                'slow_query' => [
                    'enabled' => true,
                    'threshold_ms' => 100,
                    'critical_ms' => 1000,
                ],
                'hotspot' => [
                    'enabled' => true,
                    'threshold_percent' => 50,
                    'min_queries' => 10,
                ],
                'duplicate' => [
                    'enabled' => true,
                    'threshold' => 2,
                ],
            ],
        ]);
    }

    /**
     * Register default rules
     */
    private function registerDefaultRules(): void
    {
        $this->rules = [
            new NPlusOneRule,
            new SlowQueryRule,
            new HotspotRule,
            new DuplicateQueryRule,
        ];
    }

    /**
     * Add a custom rule
     */
    public function addRule(AdvisorRule $rule): self
    {
        $this->rules[] = $rule;

        return $this;
    }

    /**
     * Set configuration
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    /**
     * Enable or disable the Advisor
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Check if Advisor is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && ($this->config['enabled'] ?? true);
    }

    /**
     * Start collecting queries
     */
    public function start(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $this->collector->start();
    }

    /**
     * Stop collecting and return the report
     */
    public function stop(): ?AdvisorReport
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $this->collector->stop();

        return $this->analyze();
    }

    /**
     * Analyze collected queries and generate report
     */
    public function analyze(): AdvisorReport
    {
        $start_time = microtime(true);

        $suggestions = collect();

        // Run all enabled rules
        foreach ($this->rules as $rule) {
            if (! $rule->isEnabled($this->config)) {
                continue;
            }

            $rule_suggestions = $rule->analyze($this->collector, $this->config);
            $suggestions = $suggestions->merge($rule_suggestions);
        }

        // Sort suggestions by severity (critical first)
        $suggestions = $this->sortSuggestions($suggestions);

        // Calculate statistics
        $queries_by_location = $this->getQueriesByLocation();
        $time_by_location = $this->getTimeByLocation();

        return new AdvisorReport(
            total_queries: $this->collector->getQueryCount(),
            total_db_time: $this->collector->getTotalTime(),
            unique_queries: $this->collector->getUniqueQueryCount(),
            suggestions: $suggestions,
            queries_by_location: $queries_by_location,
            time_by_location: $time_by_location,
            analysis_time: (microtime(true) - $start_time) * 1000,
        );
    }

    /**
     * Sort suggestions by severity
     *
     * @param  Collection<int, AdvisorSuggestion>  $suggestions
     * @return Collection<int, AdvisorSuggestion>
     */
    private function sortSuggestions(Collection $suggestions): Collection
    {
        $severity_order = [
            AdvisorSuggestion::SEVERITY_CRITICAL => 0,
            AdvisorSuggestion::SEVERITY_WARNING => 1,
            AdvisorSuggestion::SEVERITY_INFO => 2,
        ];

        return $suggestions->sort(function (AdvisorSuggestion $a, AdvisorSuggestion $b) use ($severity_order) {
            return $severity_order[$a->severity] <=> $severity_order[$b->severity];
        })->values();
    }

    /**
     * Get query count by location
     *
     * @return array<string, int>
     */
    private function getQueriesByLocation(): array
    {
        return $this->collector->groupByLocation()
            ->map(fn (Collection $queries) => $queries->count())
            ->sortDesc()
            ->toArray();
    }

    /**
     * Get time spent by location
     *
     * @return array<string, float>
     */
    private function getTimeByLocation(): array
    {
        return $this->collector->groupByLocation()
            ->map(fn (Collection $queries) => $queries->sum('time'))
            ->sortDesc()
            ->toArray();
    }

    /**
     * Get the query collector
     */
    public function getCollector(): QueryCollector
    {
        return $this->collector;
    }

    /**
     * Reset the Advisor for a new benchmark
     */
    public function reset(): void
    {
        $this->collector->reset();
    }
}
