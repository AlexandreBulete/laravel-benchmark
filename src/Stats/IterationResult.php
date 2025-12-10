<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Stats;

/**
 * DTO containing aggregated results from multiple iterations
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class IterationResult
{
    public function __construct(
        public BenchmarkStats $execution_time,
        public BenchmarkStats $memory_used,
        public BenchmarkStats $peak_memory,
        public BenchmarkStats $query_count,
        public BenchmarkStats $db_time,
        public BenchmarkStats $performance_score,
        /** @var array<array> Raw results from each iteration */
        public array $raw_iterations,
    ) {}

    /**
     * Create from array of iteration results
     *
     * @param  array<array>  $iterations  Each element has keys: execution_time, memory_used, peak_memory, etc.
     */
    public static function fromIterations(array $iterations, int $warmup_runs = 0): self
    {
        $execution_times = [];
        $memory_used = [];
        $peak_memory = [];
        $query_counts = [];
        $db_times = [];
        $performance_scores = [];

        foreach ($iterations as $result) {
            $execution_times[] = $result['execution_time'] ?? 0;
            $memory_used[] = $result['memory_used'] ?? 0;
            $peak_memory[] = $result['peak_memory'] ?? 0;
            $query_counts[] = $result['query_count'] ?? 0;
            $db_times[] = $result['db_time'] ?? 0;
            $performance_scores[] = $result['performance_score'] ?? 0;
        }

        return new self(
            execution_time: BenchmarkStats::fromValues($execution_times, $warmup_runs),
            memory_used: BenchmarkStats::fromValues($memory_used, $warmup_runs),
            peak_memory: BenchmarkStats::fromValues($peak_memory, $warmup_runs),
            query_count: BenchmarkStats::fromValues($query_counts, $warmup_runs),
            db_time: BenchmarkStats::fromValues($db_times, $warmup_runs),
            performance_score: BenchmarkStats::fromValues($performance_scores, $warmup_runs),
            raw_iterations: $iterations,
        );
    }

    /**
     * Get primary result (median execution time - most stable metric)
     */
    public function getPrimaryResult(): float
    {
        return $this->execution_time->median;
    }

    /**
     * Convert to array for export
     */
    public function toArray(): array
    {
        return [
            'execution_time' => $this->execution_time->toArray(),
            'memory_used' => $this->memory_used->toArray(),
            'peak_memory' => $this->peak_memory->toArray(),
            'query_count' => $this->query_count->toArray(),
            'db_time' => $this->db_time->toArray(),
            'performance_score' => $this->performance_score->toArray(),
        ];
    }

    /**
     * Get simplified results for baseline storage (using medians)
     */
    public function toBaselineArray(): array
    {
        return [
            'execution_time' => $this->execution_time->median,
            'memory_used' => (int) $this->memory_used->median,
            'peak_memory' => (int) $this->peak_memory->median,
            'total_queries' => (int) $this->query_count->median,
            'total_db_time' => $this->db_time->median,
            'performance_score' => (int) $this->performance_score->median,
            'stats' => $this->toArray(),
        ];
    }
}
