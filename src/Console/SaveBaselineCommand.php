<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\Baseline\BaselineResult;
use AlexandreBulete\Benchmark\Baseline\BaselineStorage;
use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\BenchmarkRegistry;
use Illuminate\Console\Command;

/**
 * Command to save benchmark results as baseline
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class SaveBaselineCommand extends Command
{
    protected $signature = 'benchmark:baseline
        {benchmark : The benchmark class name}
        {--force : Overwrite existing baseline}';

    protected $description = 'Run a benchmark and save results as baseline for future comparisons';

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

        // Check if baseline already exists
        if ($storage->exists($benchmark_name) && ! $this->option('force')) {
            $this->warn("Baseline for '{$benchmark_name}' already exists.");

            if (! $this->confirm('Do you want to overwrite it?')) {
                return Command::FAILURE;
            }
        }

        $this->info("Running benchmark: {$benchmark_name}");
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

            // Calculate performance score if available
            if ($advisor_report) {
                $score = new \AlexandreBulete\Benchmark\Advisor\PerformanceScore(
                    $advisor_report,
                    $results['execution_time']
                );
                $advisor_data['performance_score'] = $score->getScore();
            }

            // Create baseline result
            $baseline = BaselineResult::fromResults(
                benchmark_name: $benchmark_name,
                benchmark_class: $benchmark_class,
                results: $results,
                advisor_data: $advisor_data,
                options: []
            );

            // Save baseline
            $filepath = $storage->save($baseline);

            $this->newLine();
            $this->info('╔════════════════════════════════════════════════════════════╗');
            $this->info('║                    BASELINE SAVED                          ║');
            $this->info('╚════════════════════════════════════════════════════════════╝');
            $this->newLine();

            $this->table([], [
                ['Benchmark', $benchmark_name],
                ['Execution Time', $this->formatTime($baseline->execution_time)],
                ['Peak Memory', $this->formatMemory($baseline->peak_memory)],
                ['Total Queries', number_format($baseline->total_queries)],
                ['Performance Score', $baseline->performance_score.'/100'],
                ['Git Branch', $baseline->git_branch ?? 'N/A'],
                ['Git Commit', $baseline->git_commit ?? 'N/A'],
            ]);

            $this->newLine();
            $this->line("<fg=gray>Saved to: {$filepath}</>");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Benchmark failed: '.$e->getMessage());

            return Command::FAILURE;
        }
    }

    private function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).' ms';
        }

        return round($seconds, 2).' s';
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
}
