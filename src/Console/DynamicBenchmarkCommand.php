<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\BenchmarkCase;
use AlexandreBulete\Benchmark\Exceptions\ProductionEnvironmentException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dynamic command that runs a specific benchmark
 * This command is instantiated dynamically for each benchmark with a $code
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class DynamicBenchmarkCommand extends Command
{
    /**
     * The benchmark class to run
     *
     * @var class-string<BenchmarkCase>
     */
    protected string $benchmarkClass;

    /**
     * The benchmark code
     */
    protected string $benchmarkCode;

    /**
     * The benchmark options definition
     *
     * @var array<string, array{default: mixed, description: string}>
     */
    protected array $benchmarkOptions;

    /**
     * Create a new dynamic benchmark command
     *
     * @param  class-string<BenchmarkCase>  $benchmarkClass
     * @param  array<string, array{default: mixed, description: string}>  $options
     */
    public function __construct(string $benchmarkClass, string $code, array $options)
    {
        $this->benchmarkClass = $benchmarkClass;
        $this->benchmarkCode = $code;
        $this->benchmarkOptions = $options;

        // Build the signature dynamically
        $this->signature = $this->buildSignature($code, $options);
        $this->description = $this->buildDescription($benchmarkClass);

        parent::__construct();
    }

    /**
     * Build the command signature with options
     */
    protected function buildSignature(string $code, array $options): string
    {
        $signature = "benchmark:{$code}";

        foreach ($options as $name => $config) {
            $default = $config['default'] ?? null;
            $description = $config['description'] ?? "The {$name} option";

            if ($default !== null) {
                $signature .= " {--{$name}={$default} : {$description}}";
            } else {
                $signature .= " {--{$name}= : {$description}}";
            }
        }

        return $signature;
    }

    /**
     * Build the command description
     */
    protected function buildDescription(string $benchmarkClass): string
    {
        $instance = new $benchmarkClass;

        return $instance->getDescription();
    }

    /**
     * Execute the command
     */
    public function handle(): int
    {
        try {
            /** @var BenchmarkCase $benchmark */
            $benchmark = new $this->benchmarkClass;
            $benchmark->setCommand($this);

            // Collect options from CLI
            $options = $this->collectOptions();
            $benchmark->configure($options);

            $this->displayHeader($benchmark, $options);

            $results = $benchmark->run();

            $this->displayResults($benchmark->getName(), $results, $options);

            return Command::SUCCESS;
        } catch (ProductionEnvironmentException $e) {
            $this->error('❌ '.$e->getMessage());

            return Command::FAILURE;
        } catch (Throwable $e) {
            $this->error('❌ Benchmark failed: '.$e->getMessage());
            $this->newLine();
            $this->line('<fg=gray>'.$e->getTraceAsString().'</>');

            return Command::FAILURE;
        }
    }

    /**
     * Collect options from CLI input
     *
     * @return array<string, mixed>
     */
    protected function collectOptions(): array
    {
        $options = [];

        foreach ($this->benchmarkOptions as $name => $config) {
            $value = $this->option($name);

            // Cast to appropriate type based on default value
            if (isset($config['default'])) {
                $value = $this->castValue($value, $config['default']);
            }

            $options[$name] = $value;
        }

        return $options;
    }

    /**
     * Cast a value to the same type as the default
     */
    protected function castValue(mixed $value, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }

        return match (gettype($default)) {
            'integer' => (int) $value,
            'double' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => is_array($value) ? $value : [$value],
            default => $value,
        };
    }

    /**
     * Display benchmark header
     */
    protected function displayHeader(BenchmarkCase $benchmark, array $options): void
    {
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║  BENCHMARK: '.str_pad($benchmark->getName(), 46).'║');
        $this->info('╠════════════════════════════════════════════════════════════╣');

        foreach ($options as $name => $value) {
            $displayValue = is_array($value) ? json_encode($value) : (string) $value;
            $this->info('║  '.str_pad($name.': '.$displayValue, 58).'║');
        }

        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }

    /**
     * Display benchmark results
     */
    protected function displayResults(string $name, array $results, array $options): void
    {
        $this->newLine();
        $this->info('╔════════════════════════════════════════════════════════════╗');
        $this->info('║                    BENCHMARK RESULTS                       ║');
        $this->info('╠════════════════════════════════════════════════════════════╣');
        $this->info('║  Execution Time:     '.str_pad($this->formatTime($results['execution_time']), 37).'║');
        $this->info('║  Memory Used:        '.str_pad($this->formatMemory($results['memory_used']), 37).'║');
        $this->info('║  Peak Memory:        '.str_pad($this->formatMemory($results['peak_memory']), 37).'║');
        $this->info('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Performance assessment
        if ($results['execution_time'] < 60) {
            $this->info('✅ Performance: EXCELLENT (< 1 minute)');
        } elseif ($results['execution_time'] < 300) {
            $this->warn('⚠️  Performance: ACCEPTABLE (< 5 minutes)');
        } else {
            $this->error('❌ Performance: NEEDS OPTIMIZATION (> 5 minutes)');
        }
    }

    /**
     * Format execution time
     */
    protected function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).' ms';
        }

        if ($seconds < 60) {
            return round($seconds, 2).' s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($seconds < 3600) {
            return "{$minutes}m ".round($remainingSeconds, 2).'s';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$hours}h {$minutes}m ".round($remainingSeconds, 2).'s';
    }

    /**
     * Format memory size
     */
    protected function formatMemory(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }
}
