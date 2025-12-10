<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark;

use AlexandreBulete\Benchmark\Console\InstallBenchmarkCommand;
use AlexandreBulete\Benchmark\Console\ListBenchmarksCommand;
use AlexandreBulete\Benchmark\Console\MakeBenchmarkCommand;
use AlexandreBulete\Benchmark\Console\MakeBenchmarkSeederCommand;
use AlexandreBulete\Benchmark\Console\RunBenchmarkCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Service provider for the benchmark package
 * This provider registers commands and configuration only in non-production environments
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class BenchmarkServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-benchmark')
            ->hasConfigFile('benchmark');
    }

    public function packageRegistered(): void
    {
        // Always register the install command (needed to set up the package)
        $this->commands([
            InstallBenchmarkCommand::class,
        ]);

        // Only register benchmark commands if not in production
        if (! $this->app->environment('production')) {
            $this->commands([
                RunBenchmarkCommand::class,
                ListBenchmarksCommand::class,
                MakeBenchmarkCommand::class,
                MakeBenchmarkSeederCommand::class,
            ]);
        }
    }

    public function packageBooted(): void
    {
        // Publish stubs
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../stubs' => base_path('stubs/benchmark'),
            ], 'benchmark-stubs');
        }
    }
}
