<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Baseline;

use Carbon\Carbon;

/**
 * DTO representing a saved baseline result
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class BaselineResult
{
    public function __construct(
        public string $benchmark_name,
        public string $benchmark_class,
        public float $execution_time,
        public float $memory_used,
        public float $peak_memory,
        public int $total_queries,
        public float $total_db_time,
        public int $performance_score,
        public array $options,
        public string $created_at,
        public ?string $git_branch = null,
        public ?string $git_commit = null,
        public int $iterations = 1,
        public ?array $stats = null,
    ) {}

    /**
     * Create from benchmark results
     */
    public static function fromResults(
        string $benchmark_name,
        string $benchmark_class,
        array $results,
        array $advisor_data,
        array $options,
        int $iterations = 1,
        ?array $stats = null
    ): self {
        return new self(
            benchmark_name: $benchmark_name,
            benchmark_class: $benchmark_class,
            execution_time: (float) $results['execution_time'],
            memory_used: (float) $results['memory_used'],
            peak_memory: (float) $results['peak_memory'],
            total_queries: (int) ($advisor_data['total_queries'] ?? 0),
            total_db_time: (float) ($advisor_data['total_db_time'] ?? 0),
            performance_score: (int) ($advisor_data['performance_score'] ?? 0),
            options: $options,
            created_at: Carbon::now()->toIso8601String(),
            git_branch: self::getGitBranch(),
            git_commit: self::getGitCommit(),
            iterations: $iterations,
            stats: $stats,
        );
    }

    /**
     * Create from array (loaded from JSON)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            benchmark_name: $data['benchmark_name'],
            benchmark_class: $data['benchmark_class'],
            execution_time: (float) $data['execution_time'],
            memory_used: (float) $data['memory_used'],
            peak_memory: (float) $data['peak_memory'],
            total_queries: (int) $data['total_queries'],
            total_db_time: (float) $data['total_db_time'],
            performance_score: (int) $data['performance_score'],
            options: $data['options'] ?? [],
            created_at: $data['created_at'],
            git_branch: $data['git_branch'] ?? null,
            git_commit: $data['git_commit'] ?? null,
            iterations: (int) ($data['iterations'] ?? 1),
            stats: $data['stats'] ?? null,
        );
    }

    /**
     * Convert to array for JSON export
     */
    public function toArray(): array
    {
        $data = [
            'benchmark_name' => $this->benchmark_name,
            'benchmark_class' => $this->benchmark_class,
            'execution_time' => $this->execution_time,
            'memory_used' => $this->memory_used,
            'peak_memory' => $this->peak_memory,
            'total_queries' => $this->total_queries,
            'total_db_time' => $this->total_db_time,
            'performance_score' => $this->performance_score,
            'options' => $this->options,
            'created_at' => $this->created_at,
            'git_branch' => $this->git_branch,
            'git_commit' => $this->git_commit,
            'iterations' => $this->iterations,
        ];

        if ($this->stats !== null) {
            $data['stats'] = $this->stats;
        }

        return $data;
    }

    /**
     * Get current git branch
     */
    private static function getGitBranch(): ?string
    {
        $branch = @exec('git rev-parse --abbrev-ref HEAD 2>/dev/null');

        return $branch ?: null;
    }

    /**
     * Get current git commit hash
     */
    private static function getGitCommit(): ?string
    {
        $commit = @exec('git rev-parse --short HEAD 2>/dev/null');

        return $commit ?: null;
    }
}
