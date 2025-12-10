<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\BenchmarkCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Command to list all available benchmarks
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class ListBenchmarksCommand extends Command
{
    protected $signature = 'benchmark:list';

    protected $description = 'List all available benchmark suites';

    public function handle(): int
    {
        $path = base_path(config('benchmark.path', 'tests/Benchmark/Suites'));

        if (! File::isDirectory($path)) {
            $this->warn('No benchmarks directory found.');
            $this->newLine();
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
            $this->newLine();
            $this->line('Create one with: <comment>php artisan make:benchmark MyBenchmark</comment>');

            return Command::SUCCESS;
        }

        $this->info('📋 Available benchmarks:');
        $this->newLine();

        $this->table(
            ['Name', 'Description'],
            array_map(fn ($b) => [$b['name'], Str::limit($b['description'], 60)], $benchmarks)
        );

        $this->newLine();
        $this->line('Run a benchmark with: <comment>php artisan benchmark:run {name}</comment>');

        return Command::SUCCESS;
    }
}

