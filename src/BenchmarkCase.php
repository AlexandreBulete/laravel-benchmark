<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark;

use AlexandreBulete\Benchmark\Concerns\IsNotProductionEnvironment;
use AlexandreBulete\Benchmark\Concerns\UsesBenchmarkDatabase;
use Illuminate\Console\Command;

/**
 * Base class for all benchmark suites
 * Extend this class to create your own benchmark suites
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
abstract class BenchmarkCase
{
    use IsNotProductionEnvironment;
    use UsesBenchmarkDatabase;

    /**
     * The command code for dynamic command registration
     * If set, creates a command "benchmark:{code}"
     * Example: 'notifications' creates "benchmark:notifications"
     */
    protected static ?string $code = null;

    /**
     * CLI options for this benchmark
     * Format: ['option_name' => ['default' => value, 'description' => 'Help text']]
     *
     * @var array<string, array{default: mixed, description: string}>
     */
    protected static array $options = [];

    protected float $startTime = 0;

    protected float $startMemory = 0;

    protected array $results = [];

    protected ?Command $command = null;

    /**
     * Configured options values
     */
    protected array $configuredOptions = [];

    /**
     * Get the command code for dynamic registration
     */
    public static function getCode(): ?string
    {
        return static::$code;
    }

    /**
     * Get the CLI options definition
     *
     * @return array<string, array{default: mixed, description: string}>
     */
    public static function getOptions(): array
    {
        return static::$options;
    }

    /**
     * Check if this benchmark has a command code
     */
    public static function hasCode(): bool
    {
        return static::$code !== null && static::$code !== '';
    }

    /**
     * Set the console command for output
     */
    public function setCommand(Command $command): self
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Configure the benchmark with CLI options
     *
     * @param  array<string, mixed>  $options
     */
    public function configure(array $options): self
    {
        $this->configuredOptions = array_merge(
            $this->getDefaultOptions(),
            $options
        );

        $this->applyOptions($this->configuredOptions);

        return $this;
    }

    /**
     * Get default values for all options
     *
     * @return array<string, mixed>
     */
    protected function getDefaultOptions(): array
    {
        $defaults = [];
        foreach (static::$options as $name => $config) {
            $defaults[$name] = $config['default'] ?? null;
        }

        return $defaults;
    }

    /**
     * Apply configured options to the benchmark
     * Override this method to handle your custom options
     *
     * @param  array<string, mixed>  $options
     */
    protected function applyOptions(array $options): void
    {
        // Override in child class to apply options
    }

    /**
     * Get a configured option value
     */
    protected function option(string $name, mixed $default = null): mixed
    {
        return $this->configuredOptions[$name] ?? $default;
    }

    /**
     * Run the benchmark with setup and teardown
     */
    public function run(): array
    {
        $this->ensureNotProductionEnvironment();
        $this->ensureBenchmarkEnabled();

        $this->setUp();

        try {
            $this->startMeasuring();
            $this->benchmark();
            $results = $this->stopMeasuring();
        } finally {
            $this->tearDown();
        }

        return $results;
    }

    /**
     * Set up the benchmark environment
     * Override this method to add custom setup logic
     */
    protected function setUp(): void
    {
        $this->setUpBenchmarkDatabase();
    }

    /**
     * Tear down the benchmark environment
     * Override this method to add custom teardown logic
     */
    protected function tearDown(): void
    {
        $this->tearDownBenchmarkDatabase();
    }

    /**
     * Start measuring execution time and memory
     */
    protected function startMeasuring(): void
    {
        gc_collect_cycles();
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * Stop measuring and return results
     */
    protected function stopMeasuring(): array
    {
        return $this->results = [
            'execution_time' => microtime(true) - $this->startTime,
            'memory_used' => memory_get_usage(true) - $this->startMemory,
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Output info message to console
     */
    protected function info(string $message): void
    {
        $this->command?->info($message);
    }

    /**
     * Output warning message to console
     */
    protected function warn(string $message): void
    {
        $this->command?->warn($message);
    }

    /**
     * Output error message to console
     */
    protected function error(string $message): void
    {
        $this->command?->error($message);
    }

    /**
     * Output a line to console
     */
    protected function line(string $message): void
    {
        $this->command?->line($message);
    }

    /**
     * Get the benchmark name for display
     */
    public function getName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Get the benchmark description
     */
    public function getDescription(): string
    {
        return 'No description provided';
    }

    /**
     * The actual benchmark logic
     * Implement this method in your benchmark class
     */
    abstract public function benchmark(): void;
}
