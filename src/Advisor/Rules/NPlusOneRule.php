<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\Rules;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use AlexandreBulete\Benchmark\Advisor\DTO\CollectedQuery;
use AlexandreBulete\Benchmark\Advisor\QueryCollector;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Detects N+1 query problems with smart suggestions
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

            // Calculate potential time savings (N queries → 1 query)
            $potential_savings = $this->calculatePotentialSavings($count, $total_time, $avg_time);

            $severity = $this->determineSeverity($count, $total_time, $config);

            $description = sprintf(
                '%d identical queries (total: %s, avg: %.2fms)',
                $count,
                $this->formatTime($total_time),
                $avg_time
            );

            // Generate smart suggestion based on SQL analysis
            $suggestion_data = $this->analyzeSqlAndGenerateSuggestion($first_query->sql, $locations);

            $suggestions->push(new AdvisorSuggestion(
                type: 'n_plus_one',
                severity: $severity,
                title: 'Possible N+1 Query',
                description: $description,
                location: $locations->first(),
                suggestion: $suggestion_data['text'],
                metadata: [
                    'count' => $count,
                    'total_time_ms' => $total_time,
                    'avg_time_ms' => $avg_time,
                    'potential_savings_ms' => $potential_savings,
                    'potential_savings_formatted' => $this->formatTime($potential_savings),
                    'normalized_sql' => $normalized_sql,
                    'sample_sql' => $first_query->sql,
                    'locations' => $locations->toArray(),
                    'detected_table' => $suggestion_data['table'],
                    'detected_relation' => $suggestion_data['relation'],
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

    /**
     * Calculate potential time savings if N+1 is fixed
     * Assumes 1 query with IN clause is ~10x faster than N individual queries
     */
    private function calculatePotentialSavings(int $count, float $total_time, float $avg_time): float
    {
        // Optimistic estimate: 1 bulk query takes ~5-10x the time of 1 single query
        // So savings = total_time - (avg_time * 5)
        $estimated_bulk_time = $avg_time * 5;

        return max(0, $total_time - $estimated_bulk_time);
    }

    /**
     * Analyze SQL and generate smart suggestions
     *
     * @return array{text: string, table: ?string, relation: ?string}
     */
    private function analyzeSqlAndGenerateSuggestion(string $sql, Collection $locations): array
    {
        $table = null;
        $relation = null;
        $suggestions = [];

        // Pattern 1: SELECT * FROM table WHERE foreign_key = ?
        // Example: SELECT * FROM user_settings WHERE user_id = ?
        if (preg_match('/SELECT\s+.+\s+FROM\s+[`"\']?(\w+)[`"\']?\s+WHERE\s+[`"\']?(\w+)[`"\']?\s*=\s*\?/i', $sql, $matches)) {
            $table = $matches[1];
            $column = $matches[2];

            // Infer relationship name from table
            $relation = $this->inferRelationName($table, $column);

            if ($relation) {
                $suggestions[] = "→ Add eager loading: ->with('{$relation}')";
                $suggestions[] = "→ Or load after: \$model->load('{$relation}')";
            }
        }

        // Pattern 2: SELECT * FROM table WHERE id = ? (belongs to)
        // Example: SELECT * FROM users WHERE id = ?
        if (preg_match('/SELECT\s+.+\s+FROM\s+[`"\']?(\w+)[`"\']?\s+WHERE\s+[`"\']?id[`"\']?\s*=\s*\?/i', $sql, $matches)) {
            $table = $matches[1];
            $relation = Str::singular($table);

            $suggestions[] = "→ Add eager loading: ->with('{$relation}')";
        }

        // Pattern 3: Check for specific table patterns
        if ($table && empty($suggestions)) {
            $relation = $this->inferRelationName($table, null);
            if ($relation) {
                $suggestions[] = "→ Try: ->with('{$relation}')";
            }
        }

        // Add generic suggestions if nothing specific found
        if (empty($suggestions)) {
            $suggestions[] = '→ Use eager loading: ->with(\'relationName\')';
            $suggestions[] = '→ Or batch with: Model::whereIn(\'id\', $ids)->get()';
        }

        // Add potential savings hint
        $suggestions[] = '→ This could reduce queries from N to 1';

        return [
            'text' => implode("\n", $suggestions),
            'table' => $table,
            'relation' => $relation,
        ];
    }

    /**
     * Infer Laravel relationship name from table name
     */
    private function inferRelationName(string $table, ?string $column): ?string
    {
        // Common patterns:
        // user_settings → settings (hasOne/hasMany)
        // notification_rules → notificationRules
        // device_tokens → deviceTokens

        // If column is like user_id, the relation is probably on the User model
        if ($column && str_ends_with($column, '_id')) {
            // This is a foreign key, relation name is the table name
            return Str::camel(Str::singular($table));
        }

        // Convert table name to camelCase relation name
        // user_settings → userSettings OR settings
        $parts = explode('_', $table);

        if (count($parts) > 1) {
            // Try without prefix (user_settings → settings)
            $without_prefix = Str::camel(implode('_', array_slice($parts, 1)));

            // Also provide full camelCase version
            $full = Str::camel($table);

            return $without_prefix;
        }

        return Str::camel(Str::singular($table));
    }

    /**
     * Format time for display
     */
    private function formatTime(float $ms): string
    {
        if ($ms >= 1000) {
            return sprintf('%.2fs', $ms / 1000);
        }

        return sprintf('%.2fms', $ms);
    }
}
