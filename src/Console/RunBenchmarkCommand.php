<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\BenchmarkRegistry;
use AlexandreBulete\Benchmark\Exceptions\ProductionEnvironmentException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Command to run a benchmark suite
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class RunBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:run {benchmark : The benchmark class name (e.g., MyBenchmark)}';

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

        $benchmarkClass = $benchmarkData['class'];

        /** @var BenchmarkCase $benchmark */
        $benchmark = new $benchmarkClass;
        $benchmark->setCommand($this);

        // Configure with default options
        if (! empty($benchmarkData['options'])) {
            $benchmark->configure([]);
        }

        $this->displayHeader($benchmark);

        $results = $benchmark->run();

        $this->displayResults($benchmark->getName(), $results);

        // Show hint for dynamic command if available
        if ($benchmarkData['code']) {
            $this->newLine();
            $this->line('💡 Tip: Use <comment>php artisan benchmark:'.$benchmarkData['code'].'</comment> for more options.');
        }

        return Command::SUCCESS;
    }

    protected function displayHeader(BenchmarkCase $benchmark): void
    {
        $this->info("🚀 Running benchmark: {$benchmark->getName()}");
        $this->line("   <fg=gray>{$benchmark->getDescription()}</>");
        $this->newLine();
    }

    protected function displayResults(string $name, array $results): void
    {
        $this->newLine();
        $this->info('📊 Benchmark Results: '.$name);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Execution Time', $this->formatTime($results['execution_time'])],
                ['Memory Used', $this->formatMemory($results['memory_used'])],
                ['Peak Memory', $this->formatMemory($results['peak_memory'])],
            ]
        );
    }

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
