<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\Advisor\PerformanceScore;
use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\BenchmarkRegistry;
use AlexandreBulete\Benchmark\Exceptions\ProductionEnvironmentException;
use AlexandreBulete\Benchmark\Stats\IterationResult;
use Illuminate\Console\Command;
use Throwable;

/**
 * Command to run a benchmark suite
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class RunBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:run
        {benchmark : The benchmark class name (e.g., MyBenchmark)}
        {--iterations= : Number of iterations to run (default from config)}
        {--warmup= : Number of warmup runs to discard (default from config)}';

    protected $description = 'Run a benchmark suite by class name';

    public function handle(): int
    {
        try {
            return $this->runBenchmark();
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

    protected function runBenchmark(): int
    {
        $benchmarkName = $this->argument('benchmark');
        $benchmarkData = BenchmarkRegistry::findByName($benchmarkName);

        if (! $benchmarkData) {
            $this->error("❌ Benchmark '{$benchmarkName}' not found.");
            $this->newLine();
            $this->line('List available benchmarks with: <comment>php artisan benchmark:list</comment>');

            return Command::FAILURE;
        }

        $iterations = $this->getIterations();
        $warmup = $this->getWarmupRuns();
        $total_runs = $iterations + $warmup;

        $benchmarkClass = $benchmarkData['class'];

        /** @var BenchmarkCase $benchmark */
        $benchmark = new $benchmarkClass;
        $benchmark->setCommand($this);

        // Configure with default options
        if (! empty($benchmarkData['options'])) {
            $benchmark->configure([]);
        }

        $this->displayHeader($benchmark, $iterations, $warmup);

        // Run multiple iterations
        $iteration_results = [];

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
                    '       <fg=green>✓</> %s | Memory: %s',
                    $this->formatTime($results['execution_time']),
                    $this->formatMemory($results['peak_memory'])
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

        $this->displayResults($benchmark->getName(), $stats);

        // Show hint for dynamic command if available
        if ($benchmarkData['code']) {
            $this->newLine();
            $this->line('💡 Tip: Use <comment>php artisan benchmark:'.$benchmarkData['code'].'</comment> for more options.');
        }

        return Command::SUCCESS;
    }

    protected function displayHeader(BenchmarkCase $benchmark, int $iterations, int $warmup): void
    {
        $this->info("🚀 Running benchmark: {$benchmark->getName()}");
        $this->line("   <fg=gray>{$benchmark->getDescription()}</>");

        $iterations_info = "   iterations: {$iterations}";
        if ($warmup > 0) {
            $iterations_info .= " (+ {$warmup} warmup)";
        }
        $this->line("<fg=gray>{$iterations_info}</>");
        $this->newLine();
    }

    protected function displayResults(string $name, IterationResult $stats): void
    {
        $this->newLine();
        $this->info('📊 Benchmark Results: '.$name.' ('.$stats->execution_time->iterations.' iterations)');
        $this->newLine();

        // Show individual runs if configured and not too many
        if (config('benchmark.iterations.show_individual', true) && $stats->execution_time->iterations <= 10) {
            $run_times = array_map(
                fn ($t) => $this->formatTime($t),
                $stats->execution_time->all_values
            );
            $this->line('<fg=gray>Runs: '.implode(' | ', $run_times).'</>');
            $this->newLine();
        }

        $variance_color = $stats->execution_time->std_deviation_percent > config('benchmark.iterations.variance_warning_threshold', 15)
            ? 'yellow'
            : 'white';

        $this->table(
            ['Metric', 'Value'],
            [
                ['Execution Time (median)', $this->formatTime($stats->execution_time->median)],
                ['Execution Time (avg)', $this->formatTime($stats->execution_time->average)],
                ['Min / Max', $this->formatTime($stats->execution_time->min).' / '.$this->formatTime($stats->execution_time->max)],
                ['Std Deviation', sprintf('<fg=%s>±%s (%.1f%%)</>', $variance_color, $this->formatTime($stats->execution_time->std_deviation), $stats->execution_time->std_deviation_percent)],
                ['Peak Memory (median)', $this->formatMemory((int) $stats->peak_memory->median)],
                ['Stability', $stats->execution_time->getStabilityAssessment()],
            ]
        );

        // Variance warning
        if ($stats->execution_time->std_deviation_percent > config('benchmark.iterations.variance_warning_threshold', 15)) {
            $this->newLine();
            $this->warn('⚠️  High variance detected. Results may be unstable.');
        }
    }

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
