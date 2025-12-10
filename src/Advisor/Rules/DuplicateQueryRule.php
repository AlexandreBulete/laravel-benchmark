<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\Rules;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use AlexandreBulete\Benchmark\Advisor\DTO\CollectedQuery;
use AlexandreBulete\Benchmark\Advisor\QueryCollector;
use Illuminate\Support\Collection;

/**
 * Detects duplicate queries (exact same SQL with same bindings)
 * Different from N+1 which detects same structure with different bindings
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class DuplicateQueryRule implements AdvisorRule
{
    public function getName(): string
    {
        return 'Duplicate Query Detection';
    }

    public function isEnabled(array $config): bool
    {
        return $config['rules']['duplicate']['enabled'] ?? true;
    }

    public function analyze(QueryCollector $collector, array $config): Collection
    {
        $threshold = $config['rules']['duplicate']['threshold'] ?? 2;
        $suggestions = collect();

        // Group queries by exact SQL + bindings
        $grouped = $collector->getQueries()->groupBy(function (CollectedQuery $query) {
            return md5($query->sql.serialize($query->bindings));
        });

        foreach ($grouped as $hash => $queries) {
            $count = $queries->count();

            if ($count < $threshold) {
                continue;
            }

            /** @var CollectedQuery $first_query */
            $first_query = $queries->first();

            $locations = $queries
                ->map(fn (CollectedQuery $q) => $q->getLocationString())
                ->unique()
                ->values();

            $total_time = $queries->sum('time');

            $severity = $count >= 5
                ? AdvisorSuggestion::SEVERITY_WARNING
                : AdvisorSuggestion::SEVERITY_INFO;

            $description = sprintf(
                'Exact same query executed %d times (wasted: %.2fms)',
                $count,
                $total_time - ($total_time / $count) // Time that could be saved
            );

            $suggestions->push(new AdvisorSuggestion(
                type: 'duplicate_query',
                severity: $severity,
                title: 'Duplicate Query',
                description: $description,
                location: $locations->first(),
                suggestion: $this->generateSuggestion($count, $locations),
                metadata: [
                    'count' => $count,
                    'total_time_ms' => $total_time,
                    'sql' => $first_query->sql,
                    'locations' => $locations->toArray(),
                ]
            ));
        }

        return $suggestions;
    }

    private function generateSuggestion(int $count, Collection $locations): string
    {
        $suggestions = ['This exact query is executed multiple times with the same data'];

        if ($locations->count() > 1) {
            $suggestions[] = 'Query is called from multiple locations - consider centralizing';
        }

        $suggestions[] = 'Consider caching the result or storing it in a variable';
        $suggestions[] = 'If in a loop, move the query outside the loop';

        return implode("\n", $suggestions);
    }
}
