<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Baseline;

/**
 * Represents a single improvement item
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class ImprovementItem
{
    public function __construct(
        public string $metric,
        public float $improvement_percent,
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
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'improvement_percent' => round($this->improvement_percent, 1),
        ];
    }
}
