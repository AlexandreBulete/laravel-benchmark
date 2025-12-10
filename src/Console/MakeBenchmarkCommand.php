<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

/**
 * Command to generate a new benchmark class
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class MakeBenchmarkCommand extends GeneratorCommand
{
    protected $signature = 'make:benchmark {name : The name of the benchmark class}';

    protected $description = 'Create a new benchmark class';

    protected $type = 'Benchmark';

    protected function getStub(): string
    {
        $customStub = base_path('stubs/benchmark/benchmark.stub');

        if (file_exists($customStub)) {
            return $customStub;
        }

        return __DIR__ . '/../../stubs/benchmark.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return config('benchmark.namespace', 'Tests\\Benchmark\\Suites');
    }

    protected function getPath($name): string
    {
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        $path = config('benchmark.path', 'tests/Benchmark/Suites');

        return base_path($path) . '/' . str_replace('\\', '/', $name) . '.php';
    }

    protected function rootNamespace(): string
    {
        return config('benchmark.namespace', 'Tests\\Benchmark\\Suites') . '\\';
    }

    protected function buildClass($name): string
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceNamespace($stub, $name)
            ->replaceClass($stub, $name);
    }

    public function handle(): ?bool
    {
        $result = parent::handle();

        if ($result !== false) {
            $this->components->info('Benchmark created successfully.');
            $this->newLine();
            $this->line('  Run your benchmark with:');
            $this->line('  <comment>php artisan benchmark:run ' . $this->argument('name') . '</comment>');
        }

        return $result;
    }
}

