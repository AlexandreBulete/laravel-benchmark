<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Command to install the benchmark package
 * Sets up Docker, database configuration, and directory structure
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class InstallBenchmarkCommand extends Command
{
    protected $signature = 'benchmark:install
        {--force : Overwrite existing files}
        {--docker : Install Docker compose file for benchmark database}';

    protected $description = 'Install the Laravel Benchmark package';

    public function __construct(
        protected Filesystem $files
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🚀 Installing Laravel Benchmark...');
        $this->newLine();

        // Publish configuration
        $this->publishConfiguration();

        // Create benchmark directory structure
        $this->createDirectoryStructure();

        // Add database connection to config
        $this->addDatabaseConnection();

        // Add environment variables
        $this->addEnvironmentVariables();

        // Install Docker compose file
        if ($this->option('docker')) {
            $this->installDockerCompose();
        }

        $this->newLine();
        $this->info('✅ Laravel Benchmark installed successfully!');
        $this->newLine();

        $this->displayNextSteps();

        return Command::SUCCESS;
    }

    protected function publishConfiguration(): void
    {
        $this->components->task('Publishing configuration', function () {
            $this->callSilently('vendor:publish', [
                '--tag' => 'laravel-benchmark-config',
                '--force' => $this->option('force'),
            ]);
        });
    }

    protected function createDirectoryStructure(): void
    {
        $this->components->task('Creating directory structure', function () {
            $directories = [
                base_path('tests/Benchmark'),
                base_path('tests/Benchmark/Suites'),
                base_path('tests/Benchmark/Seeders'),
            ];

            foreach ($directories as $directory) {
                if (! $this->files->isDirectory($directory)) {
                    $this->files->makeDirectory($directory, 0755, true);
                }
            }

            // Create a .gitkeep file in Suites directory
            $gitkeepPath = base_path('tests/Benchmark/Suites/.gitkeep');
            if (! $this->files->exists($gitkeepPath)) {
                $this->files->put($gitkeepPath, '');
            }
        });
    }

    protected function addDatabaseConnection(): void
    {
        $this->components->task('Adding benchmark database connection', function () {
            $configPath = config_path('database.php');

            if (! $this->files->exists($configPath)) {
                return false;
            }

            $config = $this->files->get($configPath);

            // Check if benchmark connection already exists
            if (Str::contains($config, "'benchmark'")) {
                $this->components->warn('Benchmark connection already exists in database.php');

                return true;
            }

            // Find the position to insert the new connection
            $pattern = "/('connections'\s*=>\s*\[\s*)/";
            if (preg_match($pattern, $config, $matches, PREG_OFFSET_CAPTURE)) {
                $insertPosition = $matches[0][1] + strlen($matches[0][0]);

                $benchmarkConnection = $this->getBenchmarkConnectionConfig();

                $newConfig = substr($config, 0, $insertPosition)
                    .$benchmarkConnection
                    .substr($config, $insertPosition);

                $this->files->put($configPath, $newConfig);
            }

            return true;
        });
    }

    protected function getBenchmarkConnectionConfig(): string
    {
        return <<<'CONFIG'

        'benchmark' => [
            'driver' => 'mysql',
            'host' => env('DB_BENCHMARK_HOST', '127.0.0.1'),
            'port' => env('DB_BENCHMARK_PORT', '3307'),
            'database' => env('DB_BENCHMARK_DATABASE', 'benchmark'),
            'username' => env('DB_BENCHMARK_USERNAME', 'benchmark'),
            'password' => env('DB_BENCHMARK_PASSWORD', 'benchmark'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ],

        
CONFIG;
    }

    protected function addEnvironmentVariables(): void
    {
        $this->components->task('Adding environment variables to .env', function () {
            $envPath = base_path('.env');

            if (! $this->files->exists($envPath)) {
                return false;
            }

            $env = $this->files->get($envPath);

            // Check if benchmark variables already exist
            if (Str::contains($env, 'BENCHMARK_ENABLED')) {
                return true;
            }

            $variables = <<<'ENV'


# Benchmark Configuration
BENCHMARK_ENABLED=true
DB_BENCHMARK_HOST=db_benchmark
DB_BENCHMARK_PORT=3306
DB_BENCHMARK_DATABASE=benchmark
DB_BENCHMARK_USERNAME=benchmark
DB_BENCHMARK_PASSWORD=benchmark
ENV;

            $this->files->append($envPath, $variables);

            return true;
        });
    }

    protected function installDockerCompose(): void
    {
        $this->components->task('Installing Docker compose file', function () {
            $stubPath = __DIR__.'/../../stubs/compose.benchmark.yml.stub';
            $targetPath = base_path('compose.benchmark.yml');

            if ($this->files->exists($targetPath) && ! $this->option('force')) {
                $this->components->warn('compose.benchmark.yml already exists');

                return true;
            }

            if ($this->files->exists($stubPath)) {
                $this->files->copy($stubPath, $targetPath);
            } else {
                $this->files->put($targetPath, $this->getDockerComposeContent());
            }

            return true;
        });
    }

    protected function getDockerComposeContent(): string
    {
        return <<<'YAML'
services:
  db_benchmark:
    image: mysql:8.0
    container_name: ${COMPOSE_PROJECT_NAME:-app}-db-benchmark
    restart: "no"
    environment:
      MYSQL_DATABASE: ${DB_BENCHMARK_DATABASE:-benchmark}
      MYSQL_ROOT_PASSWORD: ${DB_BENCHMARK_PASSWORD:-benchmark}
      MYSQL_PASSWORD: ${DB_BENCHMARK_PASSWORD:-benchmark}
      MYSQL_USER: ${DB_BENCHMARK_USERNAME:-benchmark}
    volumes:
      - dbdata_benchmark:/var/lib/mysql
    networks:
      - default
    ports:
      - "${DB_BENCHMARK_PORT:-3307}:3306"

volumes:
  dbdata_benchmark:
YAML;
    }

    protected function displayNextSteps(): void
    {
        $this->components->info('Next steps:');
        $this->newLine();

        $this->line('  1. Review the configuration in <comment>config/benchmark.php</comment>');
        $this->newLine();

        if ($this->option('docker')) {
            $this->line('  2. Start the benchmark database:');
            $this->line('     <comment>docker compose -f compose.yml -f compose.benchmark.yml up -d</comment>');
            $this->newLine();
        }

        $this->line('  3. Create your first benchmark:');
        $this->line('     <comment>php artisan make:benchmark MyFirstBenchmark</comment>');
        $this->newLine();

        $this->line('  4. Run your benchmark:');
        $this->line('     <comment>php artisan benchmark:run MyFirstBenchmark</comment>');
    }
}
