<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\Exceptions\ProductionEnvironmentException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Command to run a benchmark suite
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class RunBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:run
        {benchmark : The benchmark class to run (e.g., MyBenchmark)}
        {--list : List all available benchmarks}';

    protected $description = 'Run a benchmark suite';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listBenchmarks();
        }

        try {
            return $this->runBenchmark();
        } catch (ProductionEnvironmentException $e) {
            $this->error('❌ ' . $e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->error('❌ Benchmark failed: ' . $e->getMessage());
            $this->newLine();
            $this->line('<fg=gray>' . $e->getTraceAsString() . '</>');

            return Command::FAILURE;
        }
    }

    protected function runBenchmark(): int
    {
        $benchmarkName = $this->argument('benchmark');
        $benchmarkClass = $this->resolveBenchmarkClass($benchmarkName);

        if (! $benchmarkClass) {
            $this->error("❌ Benchmark '{$benchmarkName}' not found.");
            $this->newLine();
            $this->info('Available benchmarks:');
            $this->listBenchmarks();

            return Command::FAILURE;
        }

        $benchmark = app($benchmarkClass);

        if (! $benchmark instanceof BenchmarkCase) {
            $this->error("❌ Class '{$benchmarkClass}' must extend " . BenchmarkCase::class);

            return Command::FAILURE;
        }

        $benchmark->setCommand($this);

        $this->info("🚀 Running benchmark: {$benchmark->getName()}");
        $this->line("   <fg=gray>{$benchmark->getDescription()}</>");
        $this->newLine();

        $results = $benchmark->run();

        $this->displayResults($benchmark->getName(), $results);

        return Command::SUCCESS;
    }

    protected function resolveBenchmarkClass(string $name): ?string
    {
        $namespace = config('benchmark.namespace', 'Tests\\Benchmark\\Suites');

        // Try with the exact name first
        $class = $namespace . '\\' . $name;
        if (class_exists($class)) {
            return $class;
        }

        // Try with 'Benchmark' suffix
        $class = $namespace . '\\' . $name . 'Benchmark';
        if (class_exists($class)) {
            return $class;
        }

        return null;
    }

    protected function listBenchmarks(): int
    {
        $path = base_path(config('benchmark.path', 'tests/Benchmark/Suites'));

        if (! File::isDirectory($path)) {
            $this->warn('No benchmarks directory found.');
            $this->line('Run <comment>php artisan benchmark:install</comment> to set up the package.');

            return Command::SUCCESS;
        }

        $files = File::files($path);
        $benchmarks = [];

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $className = $file->getFilenameWithoutExtension();
                $fullClass = config('benchmark.namespace', 'Tests\\Benchmark\\Suites') . '\\' . $className;

                if (class_exists($fullClass) && is_subclass_of($fullClass, BenchmarkCase::class)) {
                    $instance = app($fullClass);
                    $benchmarks[] = [
                        'name' => $className,
                        'description' => $instance->getDescription(),
                    ];
                }
            }
        }

        if (empty($benchmarks)) {
            $this->warn('No benchmarks found.');
            $this->line('Create one with: <comment>php artisan make:benchmark MyBenchmark</comment>');

            return Command::SUCCESS;
        }

        $this->info('Available benchmarks:');
        $this->newLine();

        $this->table(
            ['Name', 'Description'],
            array_map(fn ($b) => [$b['name'], Str::limit($b['description'], 50)], $benchmarks)
        );

        return Command::SUCCESS;
    }

    protected function displayResults(string $name, array $results): void
    {
        $this->newLine();
        $this->info('📊 Benchmark Results: ' . $name);
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Execution Time', $this->formatTime($results['execution_time'])],
                ['Memory Used', $this->formatMemory($results['memory_used'])],
                ['Peak Memory', $this->formatMemory($results['peak_memory'])],
            ]
        );

        $this->newLine();

        // Performance assessment
        if ($results['execution_time'] < 60) {
            $this->info('✅ Performance: Excellent (< 1 minute)');
        } elseif ($results['execution_time'] < 300) {
            $this->warn('⚠️  Performance: Acceptable (< 5 minutes)');
        } else {
            $this->error('❌ Performance: Needs optimization (> 5 minutes)');
        }
    }

    protected function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2) . ' ms';
        }

        if ($seconds < 60) {
            return round($seconds, 2) . ' s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($seconds < 3600) {
            return "{$minutes}m " . round($remainingSeconds, 2) . 's';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$hours}h {$minutes}m " . round($remainingSeconds, 2) . 's';
    }

    protected function formatMemory(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
