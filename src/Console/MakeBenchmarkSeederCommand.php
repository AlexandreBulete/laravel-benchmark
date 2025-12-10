<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

/**
 * Command to generate a new benchmark seeder class
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class MakeBenchmarkSeederCommand extends GeneratorCommand
{
    protected $signature = 'make:benchmark-seeder {name : The name of the seeder class}';

    protected $description = 'Create a new benchmark seeder class';

    protected $type = 'Benchmark Seeder';

    protected function getStub(): string
    {
        $customStub = base_path('stubs/benchmark/seeder.stub');

        if (file_exists($customStub)) {
            return $customStub;
        }

        return __DIR__ . '/../../stubs/seeder.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return 'Tests\\Benchmark\\Seeders';
    }

    protected function getPath($name): string
    {
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        return base_path('tests/Benchmark/Seeders') . '/' . str_replace('\\', '/', $name) . '.php';
    }

    protected function rootNamespace(): string
    {
        return 'Tests\\Benchmark\\Seeders\\';
    }

    public function handle(): ?bool
    {
        $result = parent::handle();

        if ($result !== false) {
            $this->components->info('Benchmark seeder created successfully.');
        }

        return $result;
    }
}

