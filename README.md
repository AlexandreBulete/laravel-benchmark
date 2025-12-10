# Laravel Benchmark

A comprehensive benchmark system for Laravel applications. Safely test performance with isolated database, automatic cleanup, and production protection.

[![Latest Stable Version](https://poser.pugx.org/alexandrebulete/laravel-benchmark/v/stable)](https://packagist.org/packages/alexandrebulete/laravel-benchmark)
[![License](https://poser.pugx.org/alexandrebulete/laravel-benchmark/license)](https://packagist.org/packages/alexandrebulete/laravel-benchmark)

## Features

- 🔒 **Production Safe** - Automatically disabled in production environment
- 🗄️ **Isolated Database** - Separate benchmark database to avoid data pollution
- 📊 **Detailed Metrics** - Execution time, memory usage, and peak memory tracking
- 🐳 **Docker Ready** - Includes Docker Compose template for benchmark database
- 🛠️ **Artisan Commands** - Easy-to-use CLI for creating and running benchmarks
- 🧹 **Auto Cleanup** - Database is wiped after each benchmark run

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

This will:
- Publish the configuration file
- Create the `tests/Benchmark` directory structure
- Add the benchmark database connection to `config/database.php`
- Add environment variables to `.env`
- Create `compose.benchmark.yml` for Docker (with `--docker` flag)

## Configuration

After installation, you can customize the settings in `config/benchmark.php`:

```php
return [
    // Enable/disable benchmarks (also controlled by BENCHMARK_ENABLED env)
    'enabled' => env('BENCHMARK_ENABLED', false),

    // Database connection for benchmarks
    'database' => [
        'connection' => env('BENCHMARK_DB_CONNECTION', 'benchmark'),
    ],

    // Namespace where benchmark classes are located
    'namespace' => 'Tests\\Benchmark\\Suites',

    // Path to benchmark classes (relative to base_path)
    'path' => 'tests/Benchmark/Suites',
];
```

## Environment Variables

Add these to your `.env` file (automatically added by `benchmark:install`):

```env
# Enable benchmarks (disabled by default for safety)
BENCHMARK_ENABLED=true

# Benchmark database connection
DB_BENCHMARK_HOST=db_benchmark
DB_BENCHMARK_PORT=3306
DB_BENCHMARK_DATABASE=benchmark
DB_BENCHMARK_USERNAME=benchmark
DB_BENCHMARK_PASSWORD=benchmark
```

## Docker Setup

### Starting the benchmark database

```bash
# With the provided compose file
docker compose -f compose.yml -f compose.benchmark.yml up -d

# Or add to your Makefile
up-benchmark:
    docker compose -f compose.yml -f compose.benchmark.yml up -d

down-benchmark:
    docker compose -f compose.yml -f compose.benchmark.yml down
```

### Production Safety

The `compose.benchmark.yml` file should **never** be deployed to production:
- Don't include it in your production Docker Compose command
- Don't deploy this file to your production server
- The benchmark commands are automatically disabled in production environment

## Usage

### Creating a Benchmark

```bash
php artisan make:benchmark UserProcessingBenchmark
```

This creates a new benchmark class in `tests/Benchmark/Suites/`:

```php
<?php

namespace Tests\Benchmark\Suites;

use AlexandreBulete\Benchmark\BenchmarkCase;

class UserProcessingBenchmark extends BenchmarkCase
{
    public function getDescription(): string
    {
        return 'Benchmark user processing performance';
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed your benchmark data
        User::factory()->count(10000)->create();
    }

    public function benchmark(): void
    {
        $this->info('Processing users...');
        
        $service = app(UserService::class);
        $service->processAllUsers();
        
        $this->info('Done!');
    }
}
```

### Creating a Benchmark Seeder

```bash
php artisan make:benchmark-seeder UserBenchmarkSeeder
```

This creates a seeder class in `tests/Benchmark/Seeders/`:

```php
<?php

namespace Tests\Benchmark\Seeders;

class UserBenchmarkSeeder
{
    public function seed(int $count = 10000): void
    {
        // Use chunks to avoid memory issues
        $chunkSize = 1000;
        
        for ($i = 0; $i < $count; $i += $chunkSize) {
            $batchSize = min($chunkSize, $count - $i);
            User::factory()->count($batchSize)->create();
        }
    }
}
```

### Running Benchmarks

```bash
# Run a specific benchmark
php artisan benchmark:run UserProcessingBenchmark

# List all available benchmarks
php artisan benchmark:run --list
```

### Example Output

```
🚀 Running benchmark: UserProcessingBenchmark
   Benchmark user processing performance

Processing users...
Done!

📊 Benchmark Results: UserProcessingBenchmark

+----------------+-----------+
| Metric         | Value     |
+----------------+-----------+
| Execution Time | 12.34 s   |
| Memory Used    | 128.5 MB  |
| Peak Memory    | 256.0 MB  |
+----------------+-----------+

✅ Performance: Excellent (< 1 minute)
```

## Best Practices

### 1. Always use the benchmark database

The `BenchmarkCase` automatically switches to the benchmark database, but if you need to access it manually:

```php
DB::connection('benchmark')->table('users')->get();
```

### 2. Use chunking for large datasets

```php
protected function setUp(): void
{
    parent::setUp();
    
    // Don't do this with large counts:
    // User::factory()->count(1000000)->create();
    
    // Do this instead:
    collect(range(1, 1000000))
        ->chunk(1000)
        ->each(fn ($chunk) => User::factory()->count($chunk->count())->create());
}
```

### 3. Mock external services

Don't call external APIs during benchmarks. Mock them:

```php
protected function setUp(): void
{
    parent::setUp();
    
    // Mock external service
    $this->app->bind(ExternalService::class, fn () => new MockedExternalService());
}
```

### 4. Disable query logging

For large benchmarks, disable query logging to save memory:

```php
public function benchmark(): void
{
    DB::disableQueryLog();
    
    // Your benchmark logic
}
```

## Available Commands

| Command | Description |
|---------|-------------|
| `benchmark:install` | Install the package (config, directories, Docker) |
| `benchmark:run {name}` | Run a specific benchmark |
| `benchmark:run --list` | List all available benchmarks |
| `make:benchmark {name}` | Create a new benchmark class |
| `make:benchmark-seeder {name}` | Create a new benchmark seeder |

## Security

This package includes multiple safety measures:

1. **Environment Check**: Commands refuse to run in production
2. **Config Flag**: `BENCHMARK_ENABLED` must be `true`
3. **Separate Database**: Uses isolated database connection
4. **Auto Cleanup**: Database is wiped after each benchmark
5. **Docker Isolation**: Benchmark database runs in separate container

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Author

- **Alexandre Bulete** - [bulete.alexandre@gmail.com](mailto:bulete.alexandre@gmail.com)
