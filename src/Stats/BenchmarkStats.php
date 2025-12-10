<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Stats;

/**
 * DTO containing statistics from multiple benchmark iterations
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class BenchmarkStats
{
    public function __construct(
        public int $iterations,
        public int $warmup_runs,
        public float $average,
        public float $median,
        public float $min,
        public float $max,
        public float $std_deviation,
        public float $std_deviation_percent,
        public float $p95,
        public float $p99,
        /** @var array<float> */
        public array $all_values,
    ) {}

    /**
     * Calculate statistics from an array of values
     *
     * @param  array<float>  $values
     */
    public static function fromValues(array $values, int $warmup_runs = 0): self
    {
        if (empty($values)) {
            return new self(
                iterations: 0,
                warmup_runs: $warmup_runs,
                average: 0,
                median: 0,
                min: 0,
                max: 0,
                std_deviation: 0,
                std_deviation_percent: 0,
                p95: 0,
                p99: 0,
                all_values: [],
            );
        }

        $count = count($values);
        $sorted = $values;
        sort($sorted);

        $average = array_sum($values) / $count;
        $median = self::calculateMedian($sorted);
        $min = min($values);
        $max = max($values);
        $std_dev = self::calculateStdDeviation($values, $average);
        $std_dev_percent = $average > 0 ? ($std_dev / $average) * 100 : 0;
        $p95 = self::calculatePercentile($sorted, 95);
        $p99 = self::calculatePercentile($sorted, 99);

        return new self(
            iterations: $count,
            warmup_runs: $warmup_runs,
            average: $average,
            median: $median,
            min: $min,
            max: $max,
            std_deviation: $std_dev,
            std_deviation_percent: $std_dev_percent,
            p95: $p95,
            p99: $p99,
            all_values: $values,
        );
    }

    /**
     * Calculate median from sorted array
     *
     * @param  array<float>  $sorted
     */
    private static function calculateMedian(array $sorted): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0;
        }

        $middle = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return ($sorted[$middle - 1] + $sorted[$middle]) / 2;
        }

        return $sorted[$middle];
    }

    /**
     * Calculate standard deviation
     *
     * @param  array<float>  $values
     */
    private static function calculateStdDeviation(array $values, float $average): float
    {
        $count = count($values);

        if ($count < 2) {
            return 0;
        }

        $sum_squared_diff = 0;

        foreach ($values as $value) {
            $sum_squared_diff += pow($value - $average, 2);
        }

        return sqrt($sum_squared_diff / ($count - 1));
    }

    /**
     * Calculate percentile from sorted array
     *
     * @param  array<float>  $sorted
     */
    private static function calculatePercentile(array $sorted, int $percentile): float
    {
        $count = count($sorted);

        if ($count === 0) {
            return 0;
        }

        if ($count === 1) {
            return $sorted[0];
        }

        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper || $upper >= $count) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * $fraction;
    }

    /**
     * Convert to array for JSON export
     */
    public function toArray(): array
    {
        return [
            'iterations' => $this->iterations,
            'warmup_runs' => $this->warmup_runs,
            'average' => round($this->average, 4),
            'median' => round($this->median, 4),
            'min' => round($this->min, 4),
            'max' => round($this->max, 4),
            'std_deviation' => round($this->std_deviation, 4),
            'std_deviation_percent' => round($this->std_deviation_percent, 2),
            'p95' => round($this->p95, 4),
            'p99' => round($this->p99, 4),
        ];
    }

    /**
     * Create from array (loaded from JSON)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            iterations: $data['iterations'] ?? 0,
            warmup_runs: $data['warmup_runs'] ?? 0,
            average: (float) ($data['average'] ?? 0),
            median: (float) ($data['median'] ?? 0),
            min: (float) ($data['min'] ?? 0),
            max: (float) ($data['max'] ?? 0),
            std_deviation: (float) ($data['std_deviation'] ?? 0),
            std_deviation_percent: (float) ($data['std_deviation_percent'] ?? 0),
            p95: (float) ($data['p95'] ?? 0),
            p99: (float) ($data['p99'] ?? 0),
            all_values: $data['all_values'] ?? [],
        );
    }

    /**
     * Check if results are stable (low variance)
     */
    public function isStable(float $threshold_percent = 10): bool
    {
        return $this->std_deviation_percent <= $threshold_percent;
    }

    /**
     * Get stability assessment
     */
    public function getStabilityAssessment(): string
    {
        if ($this->std_deviation_percent <= 5) {
            return 'Very Stable';
        }

        if ($this->std_deviation_percent <= 10) {
            return 'Stable';
        }

        if ($this->std_deviation_percent <= 20) {
            return 'Moderate Variance';
        }

        return 'High Variance';
    }
}
