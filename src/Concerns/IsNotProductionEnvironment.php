<?php 

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Concerns;

use AlexandreBulete\Benchmark\Exceptions\ProductionEnvironmentException;

/**
 * This trait ensures that benchmarks cannot run in production environment
 * 
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
trait IsNotProductionEnvironment
{
    /**
     * Check if the current environment is not production
     *
     * @throws ProductionEnvironmentException
     */
    protected function ensureNotProductionEnvironment(): void
    {
        if (app()->environment('production')) {
            throw new ProductionEnvironmentException(
                'Benchmarks cannot run in production environment. This is a safety measure to prevent performance degradation.'
            );
        }
    }

    /**
     * Check if benchmarks are enabled via configuration
     *
     * @throws ProductionEnvironmentException
     */
    protected function ensureBenchmarkEnabled(): void
    {
        if (! config('benchmark.enabled', false)) {
            throw new ProductionEnvironmentException(
                'Benchmarks are disabled. Set BENCHMARK_ENABLED=true in your .env file to enable.'
            );
    }
}
}
