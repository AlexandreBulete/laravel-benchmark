<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\Advisor\PerformanceScore;
use AlexandreBulete\Benchmark\Baseline\BaselineResult;
use AlexandreBulete\Benchmark\Baseline\BaselineStorage;
use AlexandreBulete\Benchmark\Baseline\RegressionDetector;
use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\Exceptions\ProductionEnvironmentException;
use AlexandreBulete\Benchmark\Stats\IterationResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Dynamic command that runs a specific benchmark
 * This command is instantiated dynamically for each benchmark with a $code
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class DynamicBenchmarkCommand extends Command
{
    /**
     * The benchmark class to run
     *
     * @var class-string<BenchmarkCase>
     */
    protected string $benchmarkClass;

    /**
     * The benchmark code
     */
    protected string $benchmarkCode;

    /**
     * The benchmark options definition
     *
     * @var array<string, array{default: mixed, description: string}>
     */
    protected array $benchmarkOptions;

    /**
     * Create a new dynamic benchmark command
     *
     * @param  class-string<BenchmarkCase>  $benchmarkClass
     * @param  array<string, array{default: mixed, description: string}>  $options
     */
    public function __construct(string $benchmarkClass, string $code, array $options)
    {
        $this->benchmarkClass = $benchmarkClass;
        $this->benchmarkCode = $code;
        $this->benchmarkOptions = $options;

        // Build the signature dynamically
        $this->signature = $this->buildSignature($code, $options);
        $this->description = $this->buildDescription($benchmarkClass);

        parent::__construct();
    }

    /**
     * Build the command signature with options
     */
    protected function buildSignature(string $code, array $options): string
    {
        $signature = "benchmark:{$code}";

        // Add benchmark-specific options
        foreach ($options as $name => $config) {
            $default = $config['default'] ?? null;
            $description = $config['description'] ?? "The {$name} option";

            if ($default !== null) {
                $signature .= " {--{$name}={$default} : {$description}}";
            } else {
                $signature .= " {--{$name}= : {$description}}";
            }
        }

        // Add iteration options
        $signature .= ' {--iterations= : Number of iterations to run (default from config)}';
        $signature .= ' {--warmup= : Number of warmup runs to discard (default from config)}';

        // Add baseline options
        $signature .= ' {--baseline : Save results as baseline for future comparisons}';
        $signature .= ' {--compare : Compare results against saved baseline}';
        $signature .= ' {--fail-on-regression : Exit with error code on critical regression (for CI)}';
        $signature .= ' {--export= : Export comparison results to JSON file}';

        return $signature;
    }

    /**
     * Build the command description
     */
    protected function buildDescription(string $benchmarkClass): string
    {
        $instance = new $benchmarkClass;

        return $instance->getDescription();
    }

    /**
     * Get the number of iterations to run
     */
    protected function getIterations(): int
    {
        $cli_value = $this->option('iterations');

        if ($cli_value !== null) {
            return max(
                config('benchmark.iterations.min', 1),
                min((int) $cli_value, config('benchmark.iterations.max', 100))
            );
        }

        return config('benchmark.iterations.default', 5);
    }

    /**
     * Get the number of warmup runs
     */
    protected function getWarmupRuns(): int
    {
        $cli_value = $this->option('warmup');

        if ($cli_value !== null) {
            return max(0, (int) $cli_value);
        }

        return config('benchmark.iterations.warmup', 0);
    }

    /**
     * Execute the command
     */
    public function handle(BaselineStorage $storage): int
    {
        try {
            $iterations = $this->getIterations();
            $warmup = $this->getWarmupRuns();
            $total_runs = $iterations + $warmup;

            // Collect options from CLI
            $options = $this->collectOptions();

            /** @var BenchmarkCase $benchmark */
            $benchmark = new $this->benchmarkClass;
            $benchmark->setCommand($this);
            $benchmark->configure($options);

            $this->displayHeader($benchmark, $options, $iterations, $warmup);

            // Run multiple iterations
            $iteration_results = [];
            $last_advisor_report = null;

            for ($i = 1; $i <= $total_runs; $i++) {
                $is_warmup = $i <= $warmup;
                $run_number = $is_warmup ? "W{$i}" : ($i - $warmup);

                $this->line(sprintf(
                    '<fg=%s>  [%s] Running iteration %s...</>',
                    $is_warmup ? 'gray' : 'cyan',
                    $is_warmup ? 'WARMUP' : 'RUN',
                    $run_number
                ));

                // Run the benchmark
                $results = $benchmark->run();
                $advisor_report = $benchmark->getAdvisorReport();

                // Calculate performance score
                $performance_score = 0;
                if ($advisor_report) {
                    $score = new PerformanceScore($advisor_report, $results['execution_time']);
                    $performance_score = $score->getScore();
                    $last_advisor_report = $advisor_report;
                }

                // Store results (skip warmup)
                if (! $is_warmup) {
                    $iteration_results[] = [
                        'execution_time' => $results['execution_time'],
                        'memory_used' => $results['memory_used'],
                        'peak_memory' => $results['peak_memory'],
                        'query_count' => $advisor_report?->total_queries ?? 0,
                        'db_time' => $advisor_report?->total_db_time ?? 0,
                        'performance_score' => $performance_score,
                    ];

                    $this->line(sprintf(
                        '       <fg=green>✓</> %s | Memory: %s | Score: %d/100',
                        $this->formatTime($results['execution_time']),
                        $this->formatMemory($results['peak_memory']),
                        $performance_score
                    ));
                } else {
                    $this->line(sprintf(
                        '       <fg=gray>○</> %s (discarded)',
                        $this->formatTime($results['execution_time'])
                    ));
                }
            }

            // Calculate statistics
            $stats = IterationResult::fromIterations($iteration_results, $warmup);

            // Display results
            $this->displayIterationResults($benchmark->getName(), $stats, $last_advisor_report);

            // Handle baseline operations
            return $this->handleBaselineOperations($benchmark, $stats, $last_advisor_report, $options, $storage);
        } catch (ProductionEnvironmentException $e) {
            $this->error('❌ '.$e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->error('❌ Benchmark failed: '.$e->getMessage());
            $this->newLine();
            $this->line('<fg=gray>'.$e->getTraceAsString().'</>');

            return Command::FAILURE;
        }
    }

    /**
     * Handle baseline save/compare operations
     */
    protected function handleBaselineOperations(
        BenchmarkCase $benchmark,
        IterationResult $stats,
        $advisor_report,
        array $options,
        BaselineStorage $storage
    ): int {
        $benchmark_name = $benchmark->getName();

        // Use median values for baseline (most stable)
        $results = [
            'execution_time' => $stats->execution_time->median,
            'memory_used' => $stats->memory_used->median,
            'peak_memory' => $stats->peak_memory->median,
        ];

        $advisor_data = [
            'total_queries' => (int) $stats->query_count->median,
            'total_db_time' => $stats->db_time->median,
            'performance_score' => (int) $stats->performance_score->median,
        ];

        // Create current result
        $current = BaselineResult::fromResults(
            benchmark_name: $benchmark_name,
            benchmark_class: $this->benchmarkClass,
            results: $results,
            advisor_data: $advisor_data,
            options: $options,
            iterations: $stats->execution_time->iterations,
            stats: $stats->toArray()
        );

        // Save as baseline
        if ($this->option('baseline')) {
            return $this->saveBaseline($current, $stats, $storage);
        }

        // Compare to baseline
        if ($this->option('compare')) {
            return $this->compareToBaseline($current, $storage);
        }

        return Command::SUCCESS;
    }

    /**
     * Save current results as baseline
     */
    protected function saveBaseline(BaselineResult $result, IterationResult $stats, BaselineStorage $storage): int
    {
        $filepath = $storage->save($result);

        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                    BASELINE SAVED                          ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->table([], [
            ['Benchmark', $result->benchmark_name],
            ['Iterations', $stats->execution_time->iterations],
            ['Execution Time (median)', $this->formatTime($result->execution_time)],
            ['Std Deviation', sprintf('±%s (%.1f%%)', $this->formatTime($stats->execution_time->std_deviation), $stats->execution_time->std_deviation_percent)],
            ['Peak Memory', $this->formatMemory($result->peak_memory)],
            ['Total Queries', number_format($result->total_queries)],
            ['Performance Score', $result->performance_score.'/100'],
            ['Git Branch', $result->git_branch ?? 'N/A'],
            ['Git Commit', $result->git_commit ?? 'N/A'],
        ]);

        $this->newLine();
        $this->line("<fg=gray>Saved to: {$filepath}</>");

        return Command::SUCCESS;
    }

    /**
     * Compare current results to baseline
     */
    protected function compareToBaseline(BaselineResult $current, BaselineStorage $storage): int
    {
        $baseline = $storage->load($current->benchmark_name);

        if (! $baseline) {
            $this->warn("No baseline found for '{$current->benchmark_name}'.");
            $this->line('Run with --baseline first to create one.');

            return Command::FAILURE;
        }

        $detector = new RegressionDetector;
        $comparison = $detector->compare($baseline, $current);

        $this->displayComparison($comparison, $baseline, $current);

        // Export if requested
        if ($export_path = $this->option('export')) {
            $this->exportResults($comparison, $export_path);
        }

        // Return appropriate exit code
        if ($this->option('fail-on-regression') && $comparison->shouldFailCI()) {
            $this->newLine();
            $this->error('❌ CI failed due to critical performance regression.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Display comparison results
     */
    protected function displayComparison($comparison, BaselineResult $baseline, BaselineResult $current): void
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
        $this->line('<fg=cyan>Metrics Comparison (median values):</>');

        $this->table(
            ['Metric', 'Baseline', 'Current', 'Change'],
            [
                [
                    'Execution Time',
                    $this->formatTime($baseline->execution_time),
                    $this->formatTime($current->execution_time),
                    $this->formatChange($baseline->execution_time, $current->execution_time),
                ],
                [
                    'Peak Memory',
                    $this->formatMemory($baseline->peak_memory),
                    $this->formatMemory($current->peak_memory),
                    $this->formatChange($baseline->peak_memory, $current->peak_memory),
                ],
                [
                    'Query Count',
                    number_format($baseline->total_queries),
                    number_format($current->total_queries),
                    $this->formatChange($baseline->total_queries, $current->total_queries),
                ],
                [
                    'Performance Score',
                    $baseline->performance_score.'/100',
                    $current->performance_score.'/100',
                    $this->formatScoreChange($baseline->performance_score, $current->performance_score),
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
        $this->line('<fg=gray>Baseline: '.($baseline->git_branch ?? 'unknown').'@'.($baseline->git_commit ?? 'unknown').'</>');
        $this->line('<fg=gray>Current:  '.($current->git_branch ?? 'unknown').'@'.($current->git_commit ?? 'unknown').'</>');
    }

    /**
     * Export results to JSON file
     */
    protected function exportResults($comparison, string $path): void
    {
        $data = $comparison->toArray();
        $json = json_encode($data, JSON_PRETTY_PRINT);

        File::put($path, $json);

        $this->newLine();
        $this->info("Results exported to: {$path}");
    }

    /**
     * Format change percentage
     */
    protected function formatChange(float $baseline, float $current): string
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

    /**
     * Format score change
     */
    protected function formatScoreChange(int $baseline, int $current): string
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

    /**
     * Collect options from CLI input
     *
     * @return array<string, mixed>
     */
    protected function collectOptions(): array
    {
        $options = [];

        foreach ($this->benchmarkOptions as $name => $config) {
            $value = $this->option($name);

            // Cast to appropriate type based on default value
            if (isset($config['default'])) {
                $value = $this->castValue($value, $config['default']);
            }

            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * Cast a value to the same type as the default
     */
    protected function castValue(mixed $value, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }

        return match (gettype($default)) {
            'integer' => (int) $value,
            'double' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => is_array($value) ? $value : [$value],
            default => $value,
        };
    }

    /**
     * Display benchmark header
     */
    protected function displayHeader(BenchmarkCase $benchmark, array $options, int $iterations, int $warmup): void
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  BENCHMARK: '.str_pad($benchmark->getName(), 46).'║');
        $this->info('╠════════════════════════════════════════════════════════════╣');

        foreach ($options as $name => $value) {
            $displayValue = is_array($value) ? json_encode($value) : (string) $value;
            $this->info('║  '.str_pad($name.': '.$displayValue, 58).'║');
        }

        $this->info('╠════════════════════════════════════════════════════════════╣');
        $iterations_info = "iterations: {$iterations}";
        if ($warmup > 0) {
            $iterations_info .= " (+ {$warmup} warmup)";
        }
        $this->info('║  '.str_pad($iterations_info, 58).'║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Display iteration results with statistics
     */
    protected function displayIterationResults(string $name, IterationResult $stats, $advisor_report): void
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║        BENCHMARK RESULTS ('.str_pad($stats->execution_time->iterations.' iterations)', 31).'║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Show individual runs if configured
        if (config('benchmark.iterations.show_individual', true) && $stats->execution_time->iterations <= 10) {
            $this->line('<fg=cyan>Individual Runs:</>');
            $run_times = array_map(
                fn ($t) => $this->formatTime($t),
                $stats->execution_time->all_values
            );
            $this->line('  '.implode('  |  ', $run_times));
            $this->newLine();
        }

        // Statistics table
        $this->line('<fg=cyan>Statistics:</>');

        $variance_color = $stats->execution_time->std_deviation_percent > config('benchmark.iterations.variance_warning_threshold', 15)
            ? 'yellow'
            : 'green';

        $this->table(
            ['Metric', 'Value'],
            [
                ['Average', $this->formatTime($stats->execution_time->average)],
                ['Median', '<fg=white;options=bold>'.$this->formatTime($stats->execution_time->median).'</> (used for baseline)'],
                ['Min / Max', $this->formatTime($stats->execution_time->min).' / '.$this->formatTime($stats->execution_time->max)],
                ['Std Deviation', sprintf('<fg=%s>±%s (%.1f%%)</>', $variance_color, $this->formatTime($stats->execution_time->std_deviation), $stats->execution_time->std_deviation_percent)],
                ['P95', $this->formatTime($stats->execution_time->p95)],
                ['Stability', '<fg='.($stats->execution_time->isStable() ? 'green' : 'yellow').'>'.$stats->execution_time->getStabilityAssessment().'</>'],
            ]
        );

        // Memory stats
        $this->newLine();
        $this->line('<fg=cyan>Memory (Peak):</>');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Average', $this->formatMemory((int) $stats->peak_memory->average)],
                ['Median', $this->formatMemory((int) $stats->peak_memory->median)],
                ['Min / Max', $this->formatMemory((int) $stats->peak_memory->min).' / '.$this->formatMemory((int) $stats->peak_memory->max)],
            ]
        );

        // Performance score stats if available
        if ($stats->performance_score->average > 0) {
            $this->newLine();
            $this->line('<fg=cyan>Performance Score:</>');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Average', sprintf('%.0f/100', $stats->performance_score->average)],
                    ['Median', sprintf('%.0f/100', $stats->performance_score->median)],
                    ['Min / Max', sprintf('%.0f / %.0f', $stats->performance_score->min, $stats->performance_score->max)],
                ]
            );
        }

        // Variance warning
        if ($stats->execution_time->std_deviation_percent > config('benchmark.iterations.variance_warning_threshold', 15)) {
            $this->newLine();
            $this->warn('⚠️  High variance detected ('.round($stats->execution_time->std_deviation_percent, 1).'%). Results may be unstable.');
            $this->line('   Consider running with more iterations: --iterations=10');
        }
    }

    /**
     * Format execution time
     */
    protected function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        if ($seconds < 60) {
            return round($seconds, 2).'s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($seconds < 3600) {
            return "{$minutes}m ".round($remainingSeconds, 2).'s';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$hours}h {$minutes}m ".round($remainingSeconds, 2).'s';
    }

    /**
     * Format memory size
     */
    protected function formatMemory(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
