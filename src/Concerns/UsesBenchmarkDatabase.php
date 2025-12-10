<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * This trait is used to set up and tear down the benchmark database
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
trait UsesBenchmarkDatabase
{
    protected string $originalConnection;

    /**
     * Set up the benchmark database
     * Switches to the benchmark connection and runs fresh migrations
     */
    protected function setUpBenchmarkDatabase(): void
    {
        $this->originalConnection = config('database.default');

        $connection = config('benchmark.database.connection', 'benchmark');

        // Verify the benchmark connection exists
        if (! config("database.connections.{$connection}")) {
            throw new \RuntimeException(
                "Benchmark database connection '{$connection}' is not configured. " .
                'Please run: php artisan benchmark:install'
            );
        }

        // Switch to benchmark connection
        config(['database.default' => $connection]);
        DB::purge();
        DB::reconnect($connection);

        // Fresh migrate the benchmark database
        Artisan::call('migrate:fresh', [
            '--database' => $connection,
            '--force' => true,
        ]);
    }

    /**
     * Tear down the benchmark database
     * Wipes the database and restores the original connection
     */
    protected function tearDownBenchmarkDatabase(): void
    {
        $connection = config('benchmark.database.connection', 'benchmark');

        // Wipe the benchmark database
        Artisan::call('db:wipe', [
            '--database' => $connection,
            '--force' => true,
        ]);

        // Restore original connection
        config(['database.default' => $this->originalConnection]);
        DB::purge();
        DB::reconnect($this->originalConnection);
    }
}
