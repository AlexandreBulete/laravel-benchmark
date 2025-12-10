<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark;

use AlexandreBulete\Benchmark\Baseline\BaselineStorage;
use AlexandreBulete\Benchmark\Console\CompareBaselineCommand;
use AlexandreBulete\Benchmark\Console\DynamicBenchmarkCommand;
use AlexandreBulete\Benchmark\Console\InstallBenchmarkCommand;
use AlexandreBulete\Benchmark\Console\ListBaselinesCommand;
use AlexandreBulete\Benchmark\Console\ListBenchmarksCommand;
use AlexandreBulete\Benchmark\Console\MakeBenchmarkCommand;
use AlexandreBulete\Benchmark\Console\MakeBenchmarkSeederCommand;
use AlexandreBulete\Benchmark\Console\RunBenchmarkCommand;
use AlexandreBulete\Benchmark\Console\SaveBaselineCommand;
use Illuminate\Console\Application as Artisan;
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
        // Register BaselineStorage as singleton
        $this->app->singleton(BaselineStorage::class, function () {
            return new BaselineStorage;
        });

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
                SaveBaselineCommand::class,
                CompareBaselineCommand::class,
                ListBaselinesCommand::class,
            ]);
        }
    }

    public function packageBooted(): void
    {
        // Publish stubs
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/benchmark'),
            ], 'benchmark-stubs');

            // Register dynamic benchmark commands (only if not in production)
            if (! $this->app->environment('production') && config('benchmark.enabled', false)) {
                $this->registerDynamicCommands();
            }
        }
    }

    /**
     * Register dynamic benchmark commands based on benchmark $code property
     */
    protected function registerDynamicCommands(): void
    {
        $benchmarks = BenchmarkRegistry::getWithCodes();

        foreach ($benchmarks as $benchmark) {
            $command = new DynamicBenchmarkCommand(
                $benchmark['class'],
                $benchmark['code'],
                $benchmark['options']
            );

            Artisan::starting(function ($artisan) use ($command) {
                $artisan->add($command);
            });
        }
    }
}
