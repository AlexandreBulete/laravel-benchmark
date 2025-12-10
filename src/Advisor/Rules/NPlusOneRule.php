<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\Rules;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use AlexandreBulete\Benchmark\Advisor\DTO\CollectedQuery;
use AlexandreBulete\Benchmark\Advisor\QueryCollector;
use Illuminate\Support\Collection;

/**
 * Detects N+1 query problems
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class NPlusOneRule implements AdvisorRule
{
    public function getName(): string
    {
        return 'N+1 Query Detection';
    }

    public function isEnabled(array $config): bool
    {
        return $config['rules']['n_plus_one']['enabled'] ?? true;
    }

    public function analyze(QueryCollector $collector, array $config): Collection
    {
        $threshold = $config['rules']['n_plus_one']['threshold'] ?? 10;
        $suggestions = collect();

        // Group queries by normalized SQL
        $grouped = $collector->groupByNormalizedSql();

        foreach ($grouped as $normalized_sql => $queries) {
            $count = $queries->count();

            if ($count < $threshold) {
                continue;
            }

            /** @var CollectedQuery $first_query */
            $first_query = $queries->first();

            // Get unique locations
            $locations = $queries
                ->map(fn (CollectedQuery $q) => $q->getLocationString())
                ->unique()
                ->values();

            $total_time = $queries->sum('time');
            $avg_time = $total_time / $count;

            $severity = $this->determineSeverity($count, $total_time, $config);

            $description = sprintf(
                '%d identical queries executed (total: %.2fms, avg: %.2fms)',
                $count,
                $total_time,
                $avg_time
            );

            $suggestion = $this->generateSuggestion($first_query->sql, $locations);

            $suggestions->push(new AdvisorSuggestion(
                type: 'n_plus_one',
                severity: $severity,
                title: 'Possible N+1 Query',
                description: $description,
                location: $locations->first(),
                suggestion: $suggestion,
                metadata: [
                    'count' => $count,
                    'total_time_ms' => $total_time,
                    'avg_time_ms' => $avg_time,
                    'normalized_sql' => $normalized_sql,
                    'sample_sql' => $first_query->sql,
                    'locations' => $locations->toArray(),
                ]
            ));
        }

        return $suggestions;
    }

    private function determineSeverity(int $count, float $total_time, array $config): string
    {
        $critical_threshold = $config['rules']['n_plus_one']['critical_count'] ?? 100;
        $critical_time = $config['rules']['n_plus_one']['critical_time_ms'] ?? 1000;

        if ($count >= $critical_threshold || $total_time >= $critical_time) {
            return AdvisorSuggestion::SEVERITY_CRITICAL;
        }

        return AdvisorSuggestion::SEVERITY_WARNING;
    }

    private function generateSuggestion(string $sql, Collection $locations): string
    {
        $suggestions = [];

        // Detect SELECT with WHERE id = ? pattern (typical eager loading candidate)
        if (preg_match('/SELECT .* FROM [`"]?(\w+)[`"]? .* WHERE .* [`"]?(\w+_id|id)[`"]? = /i', $sql, $matches)) {
            $table = $matches[1];
            $suggestions[] = "Consider eager loading with ->with('{$table}') or ->load('{$table}')";
        }

        // Generic suggestions
        if (empty($suggestions)) {
            $suggestions[] = 'Consider using eager loading (with/load) instead of lazy loading';
            $suggestions[] = 'Or use a single query with whereIn() instead of multiple queries';
        }

        return implode("\n", $suggestions);
    }
}
