<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\Advisor\PerformanceScore;
use AlexandreBulete\Benchmark\Baseline\BaselineResult;
use AlexandreBulete\Benchmark\Baseline\BaselineStorage;
use AlexandreBulete\Benchmark\Baseline\RegressionDetector;
use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\Exceptions\ProductionEnvironmentException;
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
     * Execute the command
     */
    public function handle(BaselineStorage $storage): int
    {
        try {
            /** @var BenchmarkCase $benchmark */
            $benchmark = new $this->benchmarkClass;
            $benchmark->setCommand($this);

            // Collect options from CLI
            $options = $this->collectOptions();
            $benchmark->configure($options);

            $this->displayHeader($benchmark, $options);

            $results = $benchmark->run();
            $advisor_report = $benchmark->getAdvisorReport();

            $this->displayResults($benchmark->getName(), $results, $options);

            // Handle baseline operations
            return $this->handleBaselineOperations($benchmark, $results, $advisor_report, $options, $storage);
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
        array $results,
        $advisor_report,
        array $options,
        BaselineStorage $storage
    ): int {
        $benchmark_name = $benchmark->getName();

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
            benchmark_class: $this->benchmarkClass,
            results: $results,
            advisor_data: $advisor_data,
            options: $options
        );

        // Save as baseline
        if ($this->option('baseline')) {
            return $this->saveBaseline($current, $storage);
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
    protected function saveBaseline(BaselineResult $result, BaselineStorage $storage): int
    {
        $filepath = $storage->save($result);

        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                    BASELINE SAVED                          ║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->table([], [
            ['Benchmark', $result->benchmark_name],
            ['Execution Time', $this->formatTime($result->execution_time)],
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
        $this->line('<fg=cyan>Metrics Comparison:</>');

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
    protected function displayHeader(BenchmarkCase $benchmark, array $options): void
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  BENCHMARK: '.str_pad($benchmark->getName(), 46).'║');
        $this->info('╠════════════════════════════════════════════════════════════╣');

        foreach ($options as $name => $value) {
            $displayValue = is_array($value) ? json_encode($value) : (string) $value;
            $this->info('║  '.str_pad($name.': '.$displayValue, 58).'║');
        }

        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Display benchmark results
     */
    protected function displayResults(string $name, array $results, array $options): void
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                    BENCHMARK RESULTS                       ║');
        $this->info('╠════════════════════════════════════════════════════════════╣');
        $this->info('║  Execution Time:     '.str_pad($this->formatTime($results['execution_time']), 37).'║');
        $this->info('║  Memory Used:        '.str_pad($this->formatMemory($results['memory_used']), 37).'║');
        $this->info('║  Peak Memory:        '.str_pad($this->formatMemory($results['peak_memory']), 37).'║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
    }

    /**
     * Format execution time
     */
    protected function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).' ms';
        }

        if ($seconds < 60) {
            return round($seconds, 2).' s';
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
