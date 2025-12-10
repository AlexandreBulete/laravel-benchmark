<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\Rules;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use AlexandreBulete\Benchmark\Advisor\DTO\CollectedQuery;
use AlexandreBulete\Benchmark\Advisor\QueryCollector;
use Illuminate\Support\Collection;

/**
 * Detects slow queries
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class SlowQueryRule implements AdvisorRule
{
    public function getName(): string
    {
        return 'Slow Query Detection';
    }

    public function isEnabled(array $config): bool
    {
        return $config['rules']['slow_query']['enabled'] ?? true;
    }

    public function analyze(QueryCollector $collector, array $config): Collection
    {
        $threshold_ms = $config['rules']['slow_query']['threshold_ms'] ?? 100;
        $critical_ms = $config['rules']['slow_query']['critical_ms'] ?? 1000;

        $suggestions = collect();

        $slow_queries = $collector->getSlowQueries($threshold_ms);

        foreach ($slow_queries as $query) {
            $severity = $query->time >= $critical_ms
                ? AdvisorSuggestion::SEVERITY_CRITICAL
                : AdvisorSuggestion::SEVERITY_WARNING;

            $description = sprintf(
                'Query took %.2fms (threshold: %.0fms)',
                $query->time,
                $threshold_ms
            );

            $suggestion = $this->generateSuggestion($query);

            $suggestions->push(new AdvisorSuggestion(
                type: 'slow_query',
                severity: $severity,
                title: 'Slow Query Detected',
                description: $description,
                location: $query->getLocationString(),
                suggestion: $suggestion,
                metadata: [
                    'time_ms' => $query->time,
                    'sql' => $query->sql,
                    'file' => $query->file,
                    'line' => $query->line,
                ]
            ));
        }

        return $suggestions;
    }

    private function generateSuggestion(CollectedQuery $query): string
    {
        $suggestions = [];
        $sql = strtoupper($query->sql);

        // Check for missing WHERE clause on SELECT
        if (str_contains($sql, 'SELECT') && ! str_contains($sql, 'WHERE') && ! str_contains($sql, 'LIMIT')) {
            $suggestions[] = 'Query has no WHERE clause - consider adding filters';
        }

        // Check for potential missing index
        if (preg_match('/WHERE\s+[`"]?(\w+)[`"]?\s*=\s*\?/i', $query->sql, $matches)) {
            $column = $matches[1];
            $suggestions[] = "Consider adding an index on column '{$column}'";
        }

        // Check for ORDER BY without index hint
        if (str_contains($sql, 'ORDER BY')) {
            $suggestions[] = 'Ensure columns in ORDER BY clause are indexed';
        }

        // Check for LIKE with leading wildcard
        if (preg_match('/LIKE\s+[\'"]%/i', $query->sql)) {
            $suggestions[] = 'LIKE with leading wildcard (%) cannot use indexes - consider full-text search';
        }

        // Check for SELECT *
        if (str_contains($sql, 'SELECT *')) {
            $suggestions[] = 'Avoid SELECT * - select only needed columns';
        }

        // Generic suggestion if no specific ones
        if (empty($suggestions)) {
            $suggestions[] = 'Review query execution plan with EXPLAIN';
            $suggestions[] = 'Consider adding appropriate indexes';
        }

        return implode("\n", $suggestions);
    }
}
