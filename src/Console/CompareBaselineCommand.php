<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\Advisor\PerformanceScore;
use AlexandreBulete\Benchmark\Baseline\BaselineResult;
use AlexandreBulete\Benchmark\Baseline\BaselineStorage;
use AlexandreBulete\Benchmark\Baseline\ComparisonResult;
use AlexandreBulete\Benchmark\Baseline\RegressionDetector;
use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\BenchmarkRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Command to compare benchmark results against baseline
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class CompareBaselineCommand extends Command
{
    protected $signature = 'benchmark:compare
        {benchmark : The benchmark class name}
        {--export= : Export results to JSON file}
        {--fail-on-regression : Return non-zero exit code on critical regression}';

    protected $description = 'Run a benchmark and compare results against saved baseline';

    public function handle(BenchmarkRegistry $registry, BaselineStorage $storage): int
    {
        $benchmark_name = $this->argument('benchmark');

        // Find the benchmark class
        $benchmarks = $registry->discoverBenchmarks();
        $benchmark_class = null;

        foreach ($benchmarks as $name => $data) {
            if ($name === $benchmark_name || class_basename($data['class']) === $benchmark_name) {
                $benchmark_class = $data['class'];
                break;
            }
        }

        if (! $benchmark_class) {
            $this->error("Benchmark '{$benchmark_name}' not found.");

            return Command::FAILURE;
        }

        // Check if baseline exists
        $baseline = $storage->load($benchmark_name);

        if (! $baseline) {
            $this->error("No baseline found for '{$benchmark_name}'.");
            $this->line('Run `php artisan benchmark:baseline '.$benchmark_name.'` first.');

            return Command::FAILURE;
        }

        $this->info("Running benchmark: {$benchmark_name}");
        $this->line("<fg=gray>Comparing against baseline from {$baseline->created_at}</>");
        $this->newLine();

        try {
            /** @var BenchmarkCase $benchmark */
            $benchmark = new $benchmark_class;
            $benchmark->setCommand($this);

            $results = $benchmark->run();
            $advisor_report = $benchmark->getAdvisorReport();

            // Prepare advisor data
            $advisor_data = [
                'total_queries' => $advisor_report?->total_queries ?? 0,
                'total_db_time' => $advisor_report?->total_db_time ?? 0,
                'performance_score' => 0,
            ];

            if ($advisor_report) {
                $score = new PerformanceScore($advisor_report, $results['execution_time']);
                $advisor_data['performance_score'] = $score->getScore();
            }

            // Create current result
            $current = BaselineResult::fromResults(
                benchmark_name: $benchmark_name,
                benchmark_class: $benchmark_class,
                results: $results,
                advisor_data: $advisor_data,
                options: []
            );

            // Compare results
            $detector = new RegressionDetector;
            $comparison = $detector->compare($baseline, $current);

            // Display comparison results
            $this->displayComparison($comparison);

            // Export if requested
            if ($export_path = $this->option('export')) {
                $this->exportResults($comparison, $export_path);
            }

            // Return appropriate exit code
            if ($this->option('fail-on-regression') && $comparison->shouldFailCI()) {
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Benchmark failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Display comparison results
     */
    private function displayComparison(ComparisonResult $comparison): void
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                 BASELINE COMPARISON                        ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Status header
        $status = $comparison->getStatus();
        $emoji = $comparison->getStatusEmoji();
        $label = $comparison->getStatusLabel();

        $color = match ($status) {
            'critical' => 'red',
            'warning' => 'yellow',
            'improved' => 'green',
            default => 'white',
        };

        $this->line("  <fg={$color}>{$emoji} {$label}</>");
        $this->newLine();

        // Comparison table
        $this->line('<fg=cyan>Metrics Comparison:</>');

        $this->table(
            ['Metric', 'Baseline', 'Current', 'Change'],
            [
                [
                    'Execution Time',
                    $this->formatTime($comparison->baseline->execution_time),
                    $this->formatTime($comparison->current->execution_time),
                    $this->formatChange(
                        $comparison->baseline->execution_time,
                        $comparison->current->execution_time
                    ),
                ],
                [
                    'Peak Memory',
                    $this->formatMemory($comparison->baseline->peak_memory),
                    $this->formatMemory($comparison->current->peak_memory),
                    $this->formatChange(
                        $comparison->baseline->peak_memory,
                        $comparison->current->peak_memory
                    ),
                ],
                [
                    'Query Count',
                    number_format($comparison->baseline->total_queries),
                    number_format($comparison->current->total_queries),
                    $this->formatChange(
                        $comparison->baseline->total_queries,
                        $comparison->current->total_queries
                    ),
                ],
                [
                    'Performance Score',
                    $comparison->baseline->performance_score.'/100',
                    $comparison->current->performance_score.'/100',
                    $this->formatScoreChange(
                        $comparison->baseline->performance_score,
                        $comparison->current->performance_score
                    ),
                ],
            ]
        );

        // Show regressions
        if ($comparison->hasRegressions()) {
            $this->newLine();
            $this->line('<fg=red>Regressions Detected:</>');

            foreach ($comparison->regressions as $regression) {
                $this->line(sprintf(
                    '  %s <fg=%s>%s</>: %s → %s (+%.1f%%)',
                    $regression->getSeverityEmoji(),
                    $regression->severity === 'critical' ? 'red' : 'yellow',
                    $regression->getMetricLabel(),
                    $regression->formatted_baseline,
                    $regression->formatted_current,
                    $regression->diff_percent
                ));
            }
        }

        // Show improvements
        if ($comparison->hasImprovements()) {
            $this->newLine();
            $this->line('<fg=green>Improvements:</>');

            foreach ($comparison->improvements as $improvement) {
                $this->line(sprintf(
                    '  🚀 <fg=green>%s</>: %s → %s (-%.1f%%)',
                    $improvement->getMetricLabel(),
                    $improvement->formatted_baseline,
                    $improvement->formatted_current,
                    $improvement->improvement_percent
                ));
            }
        }

        // Git info
        $this->newLine();
        $this->line('<fg=gray>Baseline: '.($comparison->baseline->git_branch ?? 'unknown').'@'.($comparison->baseline->git_commit ?? 'unknown').'</>');
        $this->line('<fg=gray>Current:  '.($comparison->current->git_branch ?? 'unknown').'@'.($comparison->current->git_commit ?? 'unknown').'</>');
    }

    /**
     * Export results to JSON file
     */
    private function exportResults(ComparisonResult $comparison, string $path): void
    {
        $data = $comparison->toArray();
        $json = json_encode($data, JSON_PRETTY_PRINT);

        File::put($path, $json);

        $this->newLine();
        $this->info("Results exported to: {$path}");
    }

    private function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        return round($seconds, 2).'s';
    }

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

    private function formatChange(float $baseline, float $current): string
    {
        if ($baseline == 0) {
            return 'N/A';
        }

        $diff = (($current - $baseline) / $baseline) * 100;

        if (abs($diff) < 1) {
            return '<fg=gray>~</>';
        }

        if ($diff > 0) {
            return sprintf('<fg=red>+%.1f%%</>', $diff);
        }

        return sprintf('<fg=green>%.1f%%</>', $diff);
    }

    private function formatScoreChange(int $baseline, int $current): string
    {
        $diff = $current - $baseline;

        if ($diff == 0) {
            return '<fg=gray>~</>';
        }

        if ($diff > 0) {
            return sprintf('<fg=green>+%d</>', $diff);
        }

        return sprintf('<fg=red>%d</>', $diff);
    }
}
