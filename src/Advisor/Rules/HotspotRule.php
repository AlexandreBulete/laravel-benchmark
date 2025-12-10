<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\Rules;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use AlexandreBulete\Benchmark\Advisor\DTO\CollectedQuery;
use AlexandreBulete\Benchmark\Advisor\QueryCollector;
use Illuminate\Support\Collection;

/**
 * Detects code locations that generate most DB queries (hotspots)
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class HotspotRule implements AdvisorRule
{
    public function getName(): string
    {
        return 'Hotspot Detection';
    }

    public function isEnabled(array $config): bool
    {
        return $config['rules']['hotspot']['enabled'] ?? true;
    }

    public function analyze(QueryCollector $collector, array $config): Collection
    {
        $threshold_percent = $config['rules']['hotspot']['threshold_percent'] ?? 50;
        $min_queries = $config['rules']['hotspot']['min_queries'] ?? 10;

        $suggestions = collect();

        $total_queries = $collector->getQueryCount();
        $total_time = $collector->getTotalTime();

        if ($total_queries < $min_queries) {
            return $suggestions;
        }

        // Group by location
        $by_location = $collector->groupByLocation();

        foreach ($by_location as $location => $queries) {
            $query_count = $queries->count();
            $location_time = $queries->sum('time');

            $query_percent = ($query_count / $total_queries) * 100;
            $time_percent = $total_time > 0 ? ($location_time / $total_time) * 100 : 0;

            // Check if this location is a hotspot
            if ($query_percent < $threshold_percent && $time_percent < $threshold_percent) {
                continue;
            }

            /** @var CollectedQuery $first_query */
            $first_query = $queries->first();

            $severity = ($query_percent >= 80 || $time_percent >= 80)
                ? AdvisorSuggestion::SEVERITY_CRITICAL
                : AdvisorSuggestion::SEVERITY_WARNING;

            $description = sprintf(
                '%d queries (%.1f%% of total), %.2fms (%.1f%% of DB time)',
                $query_count,
                $query_percent,
                $location_time,
                $time_percent
            );

            $suggestions->push(new AdvisorSuggestion(
                type: 'hotspot',
                severity: $severity,
                title: 'Database Hotspot',
                description: $description,
                location: $location,
                suggestion: $this->generateSuggestion($query_percent, $time_percent),
                metadata: [
                    'query_count' => $query_count,
                    'query_percent' => $query_percent,
                    'time_ms' => $location_time,
                    'time_percent' => $time_percent,
                    'file' => $first_query->file,
                    'line' => $first_query->line,
                ]
            ));
        }

        return $suggestions;
    }

    private function generateSuggestion(float $query_percent, float $time_percent): string
    {
        $suggestions = [];

        if ($query_percent >= 50) {
            $suggestions[] = 'This location generates a large number of queries';
            $suggestions[] = 'Consider batching operations or using bulk queries';
        }

        if ($time_percent >= 50) {
            $suggestions[] = 'This location consumes most of the DB time';
            $suggestions[] = 'Review queries for optimization opportunities';
        }

        $suggestions[] = 'Consider caching results if data changes infrequently';
        $suggestions[] = 'Review if all queries are necessary';

        return implode("\n", $suggestions);
    }
}
