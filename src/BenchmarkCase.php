<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark;

use AlexandreBulete\Benchmark\Advisor\Advisor;
use AlexandreBulete\Benchmark\Advisor\AdvisorReportRenderer;
use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorReport;
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
     * The Advisor instance for query analysis
     */
    protected ?Advisor $advisor = null;

    /**
     * The Advisor report after benchmark execution
     */
    protected ?AdvisorReport $advisorReport = null;

    /**
     * Whether the Advisor is enabled for this benchmark
     */
    protected bool $withAdvisor = true;

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
     * Enable or disable the Advisor
     */
    public function withAdvisor(bool $enabled = true): self
    {
        $this->withAdvisor = $enabled;

        return $this;
    }

    /**
     * Run the benchmark with setup and teardown
     */
    public function run(): array
    {
        $this->ensureNotProductionEnvironment();
        $this->ensureBenchmarkEnabled();

        // Initialize Advisor if enabled
        $this->setUpAdvisor();

        $this->setUp();

        try {
            $this->startMeasuring();

            // Start collecting queries
            $this->advisor?->start();

            $this->benchmark();

            // Stop collecting and get report
            $this->advisorReport = $this->advisor?->stop();

            $results = $this->stopMeasuring();
        } finally {
            $this->tearDown();
        }

        // Render Advisor report if available
        $this->renderAdvisorReport($results);

        return $results;
    }

    /**
     * Set up the Advisor
     */
    protected function setUpAdvisor(): void
    {
        if (! $this->withAdvisor) {
            return;
        }

        $advisor_enabled = config('benchmark.advisor.enabled', true);

        if (! $advisor_enabled) {
            return;
        }

        $this->advisor = new Advisor;
    }

    /**
     * Render the Advisor report
     */
    protected function renderAdvisorReport(array $results): void
    {
        if (! $this->advisorReport || ! $this->command) {
            return;
        }

        $renderer = new AdvisorReportRenderer($this->command);
        $renderer->render($this->advisorReport, $results['execution_time']);
    }

    /**
     * Get the Advisor report (useful for programmatic access)
     */
    public function getAdvisorReport(): ?AdvisorReport
    {
        return $this->advisorReport;
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
