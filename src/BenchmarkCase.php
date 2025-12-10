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

    protected float $startTime = 0;

    protected float $startMemory = 0;

    protected array $results = [];

    protected ?Command $command = null;

    /**
     * Set the console command for output
     */
    public function setCommand(Command $command): self
    {
        $this->command = $command;

        return $this;
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
