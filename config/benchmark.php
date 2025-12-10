<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Benchmark Enabled
    |--------------------------------------------------------------------------
    |
    | This option controls whether benchmarks are enabled. When disabled,
    | benchmark commands will refuse to run. This is an additional safety
    | measure on top of the production environment check.
    |
    */
    'enabled' => env('BENCHMARK_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the database connection used for benchmarks. This should be
    | a separate database to avoid any interference with your main database.
    |
    */
    'database' => [
        'connection' => env('BENCHMARK_DB_CONNECTION', 'benchmark'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Benchmark Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace where your benchmark classes are located. This is used
    | by the benchmark:run command to discover available benchmarks.
    |
    */
    'namespace' => 'Tests\\Benchmark\\Suites',

    /*
    |--------------------------------------------------------------------------
    | Benchmark Path
    |--------------------------------------------------------------------------
    |
    | The path where your benchmark classes are located. This is relative
    | to your application's base path.
    |
    */
    'path' => 'tests/Benchmark/Suites',

    /*
    |--------------------------------------------------------------------------
    | Output Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how benchmark results are displayed and stored.
    |
    */
    'output' => [
        // Format time values (seconds, milliseconds, or auto)
        'time_format' => 'auto',

        // Format memory values (bytes, kb, mb, or auto)
        'memory_format' => 'auto',
    ],
];

