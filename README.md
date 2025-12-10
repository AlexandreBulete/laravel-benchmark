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
- 📈 **Baseline & Regression** - Save baselines and detect performance regressions
- 🔄 **Multiple Iterations** - Run benchmarks multiple times for stable, statistically meaningful results

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

## 🔄 Multiple Iterations - Stable & Reliable Results

By default, benchmarks run **5 iterations** to provide statistically stable results. This eliminates variance from garbage collection, CPU cache, and other system factors.

### Why Multiple Iterations?

A single benchmark run can be misleading due to:
- ❄️ **Cold cache** on first run
- 🗑️ **Garbage collection** pauses
- 💻 **CPU scheduling** variations
- 🔄 **I/O fluctuations**

Running multiple iterations and using the **median** provides much more reliable results.

### Sample Output

```
╔════════════════════════════════════════════════════════════╗
║        BENCHMARK RESULTS (5 iterations)                    ║
╚════════════════════════════════════════════════════════════╝

Individual Runs:
  3.42s  |  3.61s  |  3.38s  |  3.55s  |  3.41s

Statistics:
+---------------+----------------------------------------+
| Metric        | Value                                  |
+---------------+----------------------------------------+
| Average       | 3.47s                                  |
| Median        | 3.42s (used for baseline)              |
| Min / Max     | 3.38s / 3.61s                          |
| Std Deviation | ±0.09s (2.6%)                          |
| P95           | 3.58s                                  |
| Stability     | Very Stable                            |
+---------------+----------------------------------------+
```

### Controlling Iterations

```bash
# Override default iterations
php artisan benchmark:users --iterations=10

# Add warmup runs (discarded from stats)
php artisan benchmark:users --iterations=5 --warmup=2
```

### Warmup Runs

Warmup runs execute the benchmark but discard results - useful for:
- Filling CPU caches
- JIT compilation warmup
- Database connection pooling

```bash
# 5 measured runs + 2 warmup (7 total runs, first 2 discarded)
php artisan benchmark:users --iterations=5 --warmup=2
```

```
  [WARMUP] Running iteration W1...
       ○ 4.12s (discarded)
  [WARMUP] Running iteration W2...
       ○ 3.89s (discarded)
  [RUN] Running iteration 1...
       ✓ 3.42s | Memory: 35.1 MB | Score: 72/100
  [RUN] Running iteration 2...
       ✓ 3.38s | Memory: 34.8 MB | Score: 74/100
  ...
```

### Statistics Provided

| Metric | Description |
|--------|-------------|
| **Average** | Mean of all runs |
| **Median** | Middle value (used for baselines) |
| **Min / Max** | Range of results |
| **Std Deviation** | Variance measure (±value and %) |
| **P95 / P99** | 95th/99th percentile |
| **Stability** | Assessment based on variance |

**Stability Grades:**
- `Very Stable` (< 5% variance)
- `Stable` (< 10% variance)
- `Moderate Variance` (< 20% variance)
- `High Variance` (> 20% variance)

### Configuration

In `config/benchmark.php`:

```php
'iterations' => [
    // Default number of iterations
    'default' => env('BENCHMARK_ITERATIONS', 5),
    
    // Bounds
    'min' => 1,
    'max' => 100,
    
    // Default warmup runs
    'warmup' => env('BENCHMARK_WARMUP', 0),
    
    // Show individual run times
    'show_individual' => true,
    
    // Warn if variance exceeds this %
    'variance_warning_threshold' => 15,
],
```

### High Variance Warning

When results are unstable, you'll see a warning:

```
⚠️  High variance detected (18.3%). Results may be unstable.
   Consider running with more iterations: --iterations=10
```

This helps you know when your benchmark environment may be affecting results.

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

  🏆 Performance Score: 72/100 Acceptable
  [██████████████░░░░░░]

Database Statistics:
┌──────────────────┬─────────────┐
│ Total Queries    │ 1,401       │
│ Unique Queries   │ 7           │
│ Total DB Time    │ 2.86s       │
│ DB Time %        │ 79.1%       │
└──────────────────┴─────────────┘

Issues Found:
  🔴 6 critical

Potential Optimization:
  💰 Estimated time savings: ~2.34s if all N+1 issues are fixed
  📈 Potential score: 95/100 (currently 72)

Optimization Suggestions:

🔴 [n_plus_one] Possible N+1 Query
   100 identical queries (total: 100.58ms, avg: 1.01ms)
   💰 Potential savings: ~80.46ms
   📍 App\Models\User::hasEnabledRemindersNotifications()
   → Add eager loading: ->with('settings')
   → Or load after: $model->load('settings')
   SQL: select * from `user_settings` where `user_id` = ?...

🔴 [n_plus_one] Possible N+1 Query
   300 identical queries (total: 260.77ms, avg: 0.87ms)
   💰 Potential savings: ~208.62ms
   📍 App\DTOs\Notification\CloudMessageDTO::fromModel()
   → Add eager loading: ->with('user')
   → This could reduce queries from N to 1
   SQL: select * from `users` where `id` = ? limit 1

Top 5 Locations by Query Count:
┌──────────────────────────────────────────────────────┬─────────┬──────────┐
│ Location                                             │ Queries │ Time     │
├──────────────────────────────────────────────────────┼─────────┼──────────┤
│ CloudMessageDTO::fromModel()                         │ 600     │ 520.55ms │
│ RuleService::processRule()                           │ 300     │ 1.09s    │
│ NotificationRepository::create()                     │ 300     │ 1.04s    │
└──────────────────────────────────────────────────────┴─────────┴──────────┘

Score Breakdown:
  -30 N+1 query issues
  -10 High DB time (79.1%)
  -15 Low query uniqueness (0.5%)
  +5 No critical issues

Analysis completed in 31.16ms
```

### Performance Score

The Advisor calculates a **Performance Score (0-100)** based on:

| Factor | Impact |
|--------|--------|
| N+1 queries | -8 to -15 per issue |
| Slow queries | -10 to -20 per issue |
| High DB time (>70%) | -10 to -15 |
| Low query uniqueness | -5 to -15 |
| **Bonuses** | +5 to +10 for clean code |

**Grades:**
- 🏆 **A (90-100)**: Excellent
- ✅ **B (80-89)**: Good
- ⚠️ **C (70-79)**: Acceptable
- 🔧 **D (60-69)**: Needs Work
- ❌ **E (50-59)**: Poor
- 🔴 **F (0-49)**: Critical

### Smart Suggestions

The Advisor analyzes your SQL to provide **specific** eager loading suggestions:

```
SQL: SELECT * FROM user_settings WHERE user_id = ?

→ Add eager loading: ->with('settings')
→ Or load after: $model->load('settings')
```

Instead of generic advice, it detects the table and suggests the exact relationship name.

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

## 📈 Baseline & Regression Detection

Track performance over time and detect regressions before they reach production.

### Save a Baseline

Save current benchmark results as a reference point using `--baseline`:

```bash
# Using dynamic command (recommended)
php artisan benchmark:notifications --users=100 --baseline

# With more iterations for better accuracy
php artisan benchmark:notifications --users=100 --iterations=10 --baseline
```

```
╔════════════════════════════════════════════════════════════╗
║                    BASELINE SAVED                          ║
╚════════════════════════════════════════════════════════════╝

+-------------------------+------------------------------------------+
| Benchmark               | NotificationProcessingBenchmark          |
| Iterations              | 5                                        |
| Execution Time (median) | 3.42 s                                   |
| Std Deviation           | ±0.09s (2.6%)                            |
| Peak Memory             | 35.1 MB                                  |
| Total Queries           | 1,401                                    |
| Performance Score       | 45/100                                   |
| Git Branch              | feature/notifications                    |
| Git Commit              | a1b2c3d                                  |
+-------------------------+------------------------------------------+

Saved to: tests/Benchmark/baselines/notificationprocessingbenchmark.baseline.json
```

**Note:** Baselines store the **median** execution time from all iterations - the most stable metric for comparisons.

### Compare Against Baseline

Run benchmark and compare to saved baseline using `--compare`:

```bash
# Using dynamic command (recommended)
php artisan benchmark:notifications --users=100 --compare
```

```
╔════════════════════════════════════════════════════════════╗
║                 BASELINE COMPARISON                        ║
╚════════════════════════════════════════════════════════════╝

  🚀 Performance Improved

Metrics Comparison (median values):
+-------------------+----------+----------+--------+
| Metric            | Baseline | Current  | Change |
+-------------------+----------+----------+--------+
| Execution Time    | 3.61s    | 2.45s    | -32.1% |
| Peak Memory       | 35.1 MB  | 34.8 MB  | ~      |
| Query Count       | 1,401    | 542      | -61.3% |
| Performance Score | 45/100   | 78/100   | +33    |
+-------------------+----------+----------+--------+

Improvements:
  🚀 Execution Time: 3.61s → 2.45s (-32.1%)
  🚀 Query Count: 1,401 → 542 (-61.3%)
  🚀 Performance Score: 45/100 → 78/100 (+33)

Baseline: feature/notifications@a1b2c3d
Current:  main@d4e5f6g
```

### Regression Detection

When performance degrades:

```
  🔴 REGRESSION DETECTED

Regressions Detected:
  🔴 Execution Time: 2.45s → 4.12s (+68.2%)
  ⚠️  Query Count: 542 → 890 (+64.2%)
```

### CI/CD Integration

Export results as JSON and fail builds on critical regressions:

```bash
# Using dynamic command (recommended)
php artisan benchmark:notifications \
    --compare \
    --export=benchmark-results.json \
    --fail-on-regression

# Or using generic command
php artisan benchmark:compare NotificationProcessingBenchmark \
    --export=benchmark-results.json \
    --fail-on-regression
```

In your CI pipeline (GitHub Actions example):

```yaml
- name: Run Performance Check
  run: php artisan benchmark:notifications --compare --fail-on-regression
```

### List Saved Baselines

```bash
php artisan benchmark:baselines
```

```
+--------------------------------+---------------+------------+--------+---------+----------------------+------------+
| Benchmark                      | Time (median) | Iterations | Score  | Queries | Branch               | Created    |
+--------------------------------+---------------+------------+--------+---------+----------------------+------------+
| NotificationProcessingBenchmark| 3.42s         | 5x         | 45/100 | 1,401   | feature/notifications| 2 days ago |
| UserProcessingBenchmark        | 1.23s         | 10x        | 82/100 | 234     | main                 | 1 week ago |
+--------------------------------+---------------+------------+--------+---------+----------------------+------------+
```

### Configure Thresholds

In `config/benchmark.php`:

```php
'baseline' => [
    'path' => 'tests/Benchmark/baselines',
    'thresholds' => [
        'execution_time' => ['warning' => 10, 'critical' => 25], // %
        'memory' => ['warning' => 15, 'critical' => 30],
        'queries' => ['warning' => 20, 'critical' => 50],
        'score' => ['warning' => 10, 'critical' => 20],
    ],
],
```

## Available Commands

| Command | Description |
|---------|-------------|
| `benchmark:install` | Install the package (config, directories, Docker) |
| `benchmark:list` | List all available benchmarks with codes |
| `benchmark:run {name}` | Run a benchmark by class name |
| `benchmark:{code}` | Run a benchmark with custom options (auto-generated) |
| `benchmark:{code} --iterations=N` | Run N iterations (default: 5) |
| `benchmark:{code} --warmup=N` | Discard first N runs as warmup |
| `benchmark:{code} --baseline` | Run and save results as baseline |
| `benchmark:{code} --compare` | Run and compare against baseline |
| `benchmark:{code} --fail-on-regression` | Fail on critical regression (CI) |
| `benchmark:baselines` | List all saved baselines |
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
