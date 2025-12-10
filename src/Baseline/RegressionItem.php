<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Baseline;

/**
 * Represents a single regression item
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class RegressionItem
{
    public function __construct(
        public string $metric,
        public float $baseline_value,
        public float $current_value,
        public float $diff_percent,
        public string $severity,
        public string $formatted_baseline,
        public string $formatted_current,
    ) {}

    /**
     * Get human-readable metric name
     */
    public function getMetricLabel(): string
    {
        return match ($this->metric) {
            'execution_time' => 'Execution Time',
            'peak_memory' => 'Peak Memory',
            'total_queries' => 'Query Count',
            'performance_score' => 'Performance Score',
            default => ucfirst(str_replace('_', ' ', $this->metric)),
        };
    }

    /**
     * Get severity emoji
     */
    public function getSeverityEmoji(): string
    {
        return match ($this->severity) {
            'critical' => '🔴',
            'warning' => '⚠️',
            default => 'ℹ️',
        };
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'baseline_value' => $this->baseline_value,
            'current_value' => $this->current_value,
            'diff_percent' => round($this->diff_percent, 1),
            'severity' => $this->severity,
        ];
    }
}
