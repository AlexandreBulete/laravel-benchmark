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

    /*
    |--------------------------------------------------------------------------
    | Advisor Configuration
    |--------------------------------------------------------------------------
    |
    | The Advisor analyzes SQL queries during benchmark execution and provides
    | optimization suggestions like N+1 detection, slow query alerts, etc.
    |
    */
    'advisor' => [
        // Enable or disable the Advisor globally
        'enabled' => env('BENCHMARK_ADVISOR_ENABLED', true),

        /*
        |----------------------------------------------------------------------
        | Advisor Rules Configuration
        |----------------------------------------------------------------------
        |
        | Configure individual rules and their thresholds.
        |
        */
        'rules' => [
            /*
            | N+1 Query Detection
            | Detects when the same query pattern is executed multiple times
            */
            'n_plus_one' => [
                'enabled' => true,
                // Minimum number of similar queries to trigger warning
                'threshold' => 10,
                // Number of queries considered critical
                'critical_count' => 100,
                // Total time (ms) considered critical
                'critical_time_ms' => 1000,
            ],

            /*
            | Slow Query Detection
            | Flags individual queries that take too long
            */
            'slow_query' => [
                'enabled' => true,
                // Time (ms) to consider a query slow
                'threshold_ms' => 100,
                // Time (ms) to consider a query critically slow
                'critical_ms' => 1000,
            ],

            /*
            | Hotspot Detection
            | Identifies code locations generating most DB activity
            */
            'hotspot' => [
                'enabled' => true,
                // Percentage of queries/time to be considered a hotspot
                'threshold_percent' => 50,
                // Minimum queries before hotspot analysis kicks in
                'min_queries' => 10,
            ],

            /*
            | Duplicate Query Detection
            | Detects exact same queries executed multiple times
            */
            'duplicate' => [
                'enabled' => true,
                // Minimum duplicates to trigger suggestion (set higher to reduce noise)
                'threshold' => 5,
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Display Configuration
        |----------------------------------------------------------------------
        |
        | Configure how many suggestions are displayed in the report.
        |
        */
        'display' => [
            // Maximum suggestions to show per type (n_plus_one, slow_query, etc.)
            'max_per_type' => 3,
            // Maximum total suggestions to display
            'max_total' => 10,
        ],
    ],
];
