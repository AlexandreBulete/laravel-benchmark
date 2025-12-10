# Laravel Benchmark

A comprehensive benchmark system for Laravel applications. Safely test performance with isolated database, automatic cleanup, production protection, **dynamic command generation**, and **intelligent optimization suggestions**.

[![Latest Stable Version](https://poser.pugx.org/alexandrebulete/laravel-benchmark/v/stable)](https://packagist.org/packages/alexandrebulete/laravel-benchmark)
[![License](https://poser.pugx.org/alexandrebulete/laravel-benchmark/license)](https://packagist.org/packages/alexandrebulete/laravel-benchmark)

## Features

- 🔒 **Production Safe** - Automatically disabled in production environment
- 🗄️ **Isolated Database** - Separate benchmark database to avoid data pollution
- 📊 **Detailed Metrics** - Execution time, memory usage, and peak memory tracking
- 🐳 **Docker Ready** - Includes Docker Compose template for benchmark database
- 🛠️ **Artisan Commands** - Easy-to-use CLI for creating and running benchmarks
- 🧹 **Auto Cleanup** - Database is wiped after each benchmark
- ⚡ **Dynamic Commands** - Auto-generate CLI commands with custom options
- 🧠 **Advisor** - Automatic N+1 detection, slow query alerts, and optimization suggestions

## Requirements

- PHP 8.2 or higher
- Laravel 10, 11, or 12

## Installation

```bash
composer require alexandrebulete/laravel-benchmark --dev
```

Then run the installation command:

```bash
php artisan benchmark:install --docker
```

## Quick Start

### 1. Create a benchmark with a command code

```bash
# With --code: auto-creates "benchmark:users" command
php artisan make:benchmark UserProcessingBenchmark --code=users

# Without --code: use benchmark:run ClassName
php artisan make:benchmark SimpleBenchmark
```

### 2. Define CLI options in your benchmark

```php
class UserProcessingBenchmark extends BenchmarkCase
{
    // Auto-set by --code option, creates "benchmark:users"
    protected static ?string $code = 'users';

    // Define CLI options
    protected static array $options = [
        'count' => ['default' => 1000, 'description' => 'Number of users'],
    ];

    protected function applyOptions(array $options): void
    {
        $this->count = $options['count'];
    }

    public function benchmark(): void
    {
        // Your benchmark logic
    }
}
```

### 3. Run your benchmark

```bash
# Using dynamic command with options
php artisan benchmark:users --count=10000

# Or using generic command
php artisan benchmark:run UserProcessingBenchmark
```

## 🧠 Advisor - Intelligent Query Analysis

The Advisor automatically analyzes all SQL queries during your benchmark and provides actionable optimization suggestions.

### What it detects

| Rule | Description |
|------|-------------|
| **N+1 Queries** | Same query pattern executed multiple times |
| **Slow Queries** | Individual queries exceeding time threshold |
| **Hotspots** | Code locations generating most DB activity |
| **Duplicates** | Exact same queries executed multiple times |

### Sample Output

```
╔════════════════════════════════════════════════════════════════╗
║                    📊 ADVISOR REPORT                           ║
╚════════════════════════════════════════════════════════════════╝

Database Statistics:
┌──────────────────┬─────────────┐
│ Total Queries    │ 3,542       │
│ Unique Queries   │ 12          │
│ Total DB Time    │ 4.52s       │
│ DB Time %        │ 78.3%       │
└──────────────────┴─────────────┘

Issues Found:
  🔴 2 critical  ⚠️  5 warnings  ℹ️  3 info

Optimization Suggestions:

🔴 [n_plus_one] Possible N+1 Query
   500 identical queries executed (total: 2450.32ms, avg: 4.90ms)
   📍 App\Services\UserService::loadProfiles()
   💡 Consider eager loading with ->with('profile') or ->load('profile')
   SQL: SELECT * FROM `profiles` WHERE `user_id` = ?...

⚠️  [slow_query] Slow Query Detected
   Query took 892.45ms (threshold: 100ms)
   📍 App\Jobs\ProcessOrders::handle()
   💡 Consider adding an index on column 'created_at'
   💡 Ensure columns in ORDER BY clause are indexed
   SQL: SELECT * FROM `orders` WHERE `status` = ? ORDER BY...

⚠️  [hotspot] Database Hotspot
   2,100 queries (59.3% of total), 3.21s (71.0% of DB time)
   📍 App\Services\NotificationService::processAll()
   💡 This location generates a large number of queries
   💡 Consider batching operations or using bulk queries

Top 5 Locations by Query Count:
┌─────────────────────────────────────────┬─────────┬──────────┐
│ Location                                │ Queries │ Time     │
├─────────────────────────────────────────┼─────────┼──────────┤
│ NotificationService::processAll()       │ 2,100   │ 3.21s    │
│ UserService::loadProfiles()             │ 500     │ 2.45s    │
│ OrderRepository::findByUser()           │ 342     │ 0.89s    │
└─────────────────────────────────────────┴─────────┴──────────┘
```

### Disabling Advisor

```php
class MyBenchmark extends BenchmarkCase
{
    // Disable for this specific benchmark
    protected bool $withAdvisor = false;
    
    // Or disable at runtime
    public function benchmark(): void
    {
        $this->withAdvisor(false);
    }
}
```

Or globally via environment:

```env
BENCHMARK_ADVISOR_ENABLED=false
```

### Configuring Thresholds

In `config/benchmark.php`:

```php
'advisor' => [
    'enabled' => true,
    'rules' => [
        'n_plus_one' => [
            'enabled' => true,
            'threshold' => 10,          // Min similar queries to flag
            'critical_count' => 100,    // Count for critical severity
            'critical_time_ms' => 1000, // Time for critical severity
        ],
        'slow_query' => [
            'enabled' => true,
            'threshold_ms' => 100,      // Warning threshold
            'critical_ms' => 1000,      // Critical threshold
        ],
        'hotspot' => [
            'enabled' => true,
            'threshold_percent' => 50,  // % of queries/time
            'min_queries' => 10,        // Min queries to analyze
        ],
        'duplicate' => [
            'enabled' => true,
            'threshold' => 2,           // Min duplicates to flag
        ],
    ],
],
```

## Dynamic Commands

The killer feature! Define `$code` and `$options` in your benchmark class, and a CLI command is **automatically generated**.

```php
class NotificationBenchmark extends BenchmarkCase
{
    protected static ?string $code = 'notifications';

    protected static array $options = [
        'users' => ['default' => 1000, 'description' => 'Number of users'],
        'rules' => ['default' => 3, 'description' => 'Rules per user'],
    ];
}
```

This auto-creates:

```bash
php artisan benchmark:notifications --users=1000000 --rules=5
```

List all available benchmarks and their codes:

```bash
php artisan benchmark:list
```

```
╔══════════════════════════════════════════════════════════════════╗
│ Class                          │ Code          │ Command         │
╠══════════════════════════════════════════════════════════════════╣
│ NotificationProcessingBenchmark│ notifications │ benchmark:notif │
│ UserProcessingBenchmark        │ users         │ benchmark:users │
│ SimpleBenchmark                │ -             │ benchmark:run   │
╚══════════════════════════════════════════════════════════════════╝
```

## Configuration

After installation, customize settings in `config/benchmark.php`:

```php
return [
    'enabled' => env('BENCHMARK_ENABLED', false),

    'database' => [
        'connection' => env('BENCHMARK_DB_CONNECTION', 'benchmark'),
    ],

    'namespace' => 'Tests\\Benchmark\\Suites',
    'path' => 'tests/Benchmark/Suites',
    
    'advisor' => [
        'enabled' => true,
        // ... rule configurations
    ],
];
```

## Environment Variables

```env
BENCHMARK_ENABLED=true
BENCHMARK_ADVISOR_ENABLED=true
DB_BENCHMARK_HOST=db_benchmark
DB_BENCHMARK_PORT=3306
DB_BENCHMARK_DATABASE=benchmark
DB_BENCHMARK_USERNAME=benchmark
DB_BENCHMARK_PASSWORD=benchmark
```

## Docker Setup

```bash
# Start with benchmark database
docker compose -f compose.yml -f compose.benchmark.yml up -d
```

## Creating Benchmarks

### Full Example

```php
<?php

namespace Tests\Benchmark\Suites;

use AlexandreBulete\Benchmark\BenchmarkCase;
use App\Services\UserService;

class UserProcessingBenchmark extends BenchmarkCase
{
    protected static ?string $code = 'users';

    protected static array $options = [
        'count' => ['default' => 1000, 'description' => 'Number of users to process'],
        'batch' => ['default' => 100, 'description' => 'Batch size'],
    ];

    protected int $count;
    protected int $batchSize;

    public function getDescription(): string
    {
        return "Process {$this->count} users in batches of {$this->batchSize}";
    }

    protected function applyOptions(array $options): void
    {
        $this->count = $options['count'];
        $this->batchSize = $options['batch'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Seed data
        User::factory()->count($this->count)->create();
        $this->info("✅ Seeded {$this->count} users");
    }

    public function benchmark(): void
    {
        $this->info('🚀 Processing users...');

        // The Advisor will automatically track all queries here
        app(UserService::class)->processAll($this->batchSize);

        $this->info('✅ Done!');
    }
}
```

### Running

```bash
# With custom options
php artisan benchmark:users --count=50000 --batch=500

# With defaults
php artisan benchmark:run UserProcessingBenchmark
```

## Available Commands

| Command | Description |
|---------|-------------|
| `benchmark:install` | Install the package (config, directories, Docker) |
| `benchmark:list` | List all available benchmarks with codes |
| `benchmark:run {name}` | Run a benchmark by class name |
| `benchmark:{code}` | Run a benchmark with custom options (auto-generated) |
| `make:benchmark {name}` | Create a new benchmark class |
| `make:benchmark {name} --code={code}` | Create a benchmark with dynamic command |
| `make:benchmark-seeder {name}` | Create a new benchmark seeder |

## Security

Multiple safety measures:

1. **Environment Check**: Commands refuse to run in production
2. **Config Flag**: `BENCHMARK_ENABLED` must be `true`
3. **Separate Database**: Uses isolated database connection
4. **Auto Cleanup**: Database is wiped after each benchmark
5. **Docker Isolation**: Benchmark database runs in separate container

## License

MIT License - see [LICENSE](LICENSE) for details.

## Author

- **Alexandre Bulete** - [bulete.alexandre@gmail.com](mailto:bulete.alexandre@gmail.com)
