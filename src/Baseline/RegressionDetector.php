<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Baseline;

/**
 * Detects performance regressions by comparing current results to baseline
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class RegressionDetector
{
    /**
     * Regression thresholds (percentage increase to trigger warning/error)
     */
    private array $thresholds;

    public function __construct(?array $thresholds = null)
    {
        $this->thresholds = $thresholds ?? config('benchmark.baseline.thresholds', [
            'execution_time' => ['warning' => 10, 'critical' => 25],
            'memory' => ['warning' => 15, 'critical' => 30],
            'queries' => ['warning' => 20, 'critical' => 50],
            'score' => ['warning' => 10, 'critical' => 20],
        ]);
    }

    /**
     * Compare current results to baseline and detect regressions
     */
    public function compare(BaselineResult $baseline, BaselineResult $current): ComparisonResult
    {
        $regressions = [];
        $improvements = [];

        // Compare execution time
        $time_diff = $this->calculatePercentageDiff(
            $baseline->execution_time,
            $current->execution_time
        );

        if ($time_diff > 0) {
            $severity = $this->getSeverity($time_diff, 'execution_time');
            if ($severity) {
                $regressions[] = new RegressionItem(
                    metric: 'execution_time',
                    baseline_value: $baseline->execution_time,
                    current_value: $current->execution_time,
                    diff_percent: $time_diff,
                    severity: $severity,
                    formatted_baseline: $this->formatTime($baseline->execution_time),
                    formatted_current: $this->formatTime($current->execution_time),
                );
            }
        } elseif ($time_diff < -10) {
            $improvements[] = new ImprovementItem(
                metric: 'execution_time',
                improvement_percent: abs($time_diff),
                formatted_baseline: $this->formatTime($baseline->execution_time),
                formatted_current: $this->formatTime($current->execution_time),
            );
        }

        // Compare memory usage
        $memory_diff = $this->calculatePercentageDiff(
            $baseline->peak_memory,
            $current->peak_memory
        );

        if ($memory_diff > 0) {
            $severity = $this->getSeverity($memory_diff, 'memory');
            if ($severity) {
                $regressions[] = new RegressionItem(
                    metric: 'peak_memory',
                    baseline_value: $baseline->peak_memory,
                    current_value: $current->peak_memory,
                    diff_percent: $memory_diff,
                    severity: $severity,
                    formatted_baseline: $this->formatMemory($baseline->peak_memory),
                    formatted_current: $this->formatMemory($current->peak_memory),
                );
            }
        } elseif ($memory_diff < -10) {
            $improvements[] = new ImprovementItem(
                metric: 'peak_memory',
                improvement_percent: abs($memory_diff),
                formatted_baseline: $this->formatMemory($baseline->peak_memory),
                formatted_current: $this->formatMemory($current->peak_memory),
            );
        }

        // Compare query count
        $query_diff = $this->calculatePercentageDiff(
            $baseline->total_queries,
            $current->total_queries
        );

        if ($query_diff > 0) {
            $severity = $this->getSeverity($query_diff, 'queries');
            if ($severity) {
                $regressions[] = new RegressionItem(
                    metric: 'total_queries',
                    baseline_value: $baseline->total_queries,
                    current_value: $current->total_queries,
                    diff_percent: $query_diff,
                    severity: $severity,
                    formatted_baseline: number_format($baseline->total_queries),
                    formatted_current: number_format($current->total_queries),
                );
            }
        } elseif ($query_diff < -10) {
            $improvements[] = new ImprovementItem(
                metric: 'total_queries',
                improvement_percent: abs($query_diff),
                formatted_baseline: number_format($baseline->total_queries),
                formatted_current: number_format($current->total_queries),
            );
        }

        // Compare performance score (inverted - lower is worse)
        $score_diff = $baseline->performance_score - $current->performance_score;
        $score_percent = $baseline->performance_score > 0
            ? ($score_diff / $baseline->performance_score) * 100
            : 0;

        if ($score_diff > 0) {
            $severity = $this->getSeverity($score_percent, 'score');
            if ($severity) {
                $regressions[] = new RegressionItem(
                    metric: 'performance_score',
                    baseline_value: $baseline->performance_score,
                    current_value: $current->performance_score,
                    diff_percent: $score_percent,
                    severity: $severity,
                    formatted_baseline: $baseline->performance_score.'/100',
                    formatted_current: $current->performance_score.'/100',
                );
            }
        } elseif ($score_diff < -5) {
            $improvements[] = new ImprovementItem(
                metric: 'performance_score',
                improvement_percent: abs($score_percent),
                formatted_baseline: $baseline->performance_score.'/100',
                formatted_current: $current->performance_score.'/100',
            );
        }

        return new ComparisonResult(
            baseline: $baseline,
            current: $current,
            regressions: $regressions,
            improvements: $improvements,
            has_critical: collect($regressions)->contains(fn ($r) => $r->severity === 'critical'),
            has_warning: collect($regressions)->contains(fn ($r) => $r->severity === 'warning'),
        );
    }

    /**
     * Calculate percentage difference
     */
    private function calculatePercentageDiff(float $baseline, float $current): float
    {
        if ($baseline == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (($current - $baseline) / $baseline) * 100;
    }

    /**
     * Get severity based on threshold
     */
    private function getSeverity(float $diff_percent, string $metric): ?string
    {
        $thresholds = $this->thresholds[$metric] ?? ['warning' => 10, 'critical' => 25];

        if ($diff_percent >= $thresholds['critical']) {
            return 'critical';
        }

        if ($diff_percent >= $thresholds['warning']) {
            return 'warning';
        }

        return null;
    }

    /**
     * Format time for display
     */
    private function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        return round($seconds, 2).'s';
    }

    /**
     * Format memory for display
     */
    private function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, 1).' '.$units[$unit];
    }
}
