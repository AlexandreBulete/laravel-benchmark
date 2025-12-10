<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\DTO;

use Illuminate\Support\Collection;

/**
 * DTO containing the full Advisor analysis report
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class AdvisorReport
{
    public function __construct(
        public int $total_queries,
        public float $total_db_time,
        public int $unique_queries,
        /** @var Collection<int, AdvisorSuggestion> */
        public Collection $suggestions,
        /** @var array<string, int> */
        public array $queries_by_location,
        /** @var array<string, float> */
        public array $time_by_location,
        public float $analysis_time,
    ) {}

    /**
     * Check if there are any suggestions
     */
    public function hasSuggestions(): bool
    {
        return $this->suggestions->isNotEmpty();
    }

    /**
     * Get suggestions by severity
     *
     * @return Collection<int, AdvisorSuggestion>
     */
    public function getSuggestionsBySeverity(string $severity): Collection
    {
        return $this->suggestions->filter(fn (AdvisorSuggestion $s) => $s->severity === $severity);
    }

    /**
     * Get critical suggestions count
     */
    public function getCriticalCount(): int
    {
        return $this->getSuggestionsBySeverity(AdvisorSuggestion::SEVERITY_CRITICAL)->count();
    }

    /**
     * Get warning suggestions count
     */
    public function getWarningCount(): int
    {
        return $this->getSuggestionsBySeverity(AdvisorSuggestion::SEVERITY_WARNING)->count();
    }

    /**
     * Get info suggestions count
     */
    public function getInfoCount(): int
    {
        return $this->getSuggestionsBySeverity(AdvisorSuggestion::SEVERITY_INFO)->count();
    }

    /**
     * Get top N locations by query count
     *
     * @return array<string, int>
     */
    public function getTopLocationsByQueryCount(int $limit = 5): array
    {
        $sorted = $this->queries_by_location;
        arsort($sorted);

        return array_slice($sorted, 0, $limit, true);
    }

    /**
     * Get top N locations by time spent
     *
     * @return array<string, float>
     */
    public function getTopLocationsByTime(int $limit = 5): array
    {
        $sorted = $this->time_by_location;
        arsort($sorted);

        return array_slice($sorted, 0, $limit, true);
    }

    /**
     * Get percentage of time spent in DB
     */
    public function getDbTimePercentage(float $total_execution_time): float
    {
        if ($total_execution_time <= 0) {
            return 0;
        }

        return ($this->total_db_time / $total_execution_time) * 100;
    }

    /**
     * Convert to array for JSON export
     */
    public function toArray(): array
    {
        return [
            'total_queries' => $this->total_queries,
            'total_db_time_ms' => round($this->total_db_time, 2),
            'unique_queries' => $this->unique_queries,
            'suggestions' => $this->suggestions->map(fn (AdvisorSuggestion $s) => [
                'type' => $s->type,
                'severity' => $s->severity,
                'title' => $s->title,
                'description' => $s->description,
                'location' => $s->location,
                'suggestion' => $s->suggestion,
            ])->toArray(),
            'queries_by_location' => $this->queries_by_location,
            'time_by_location' => $this->time_by_location,
            'analysis_time_ms' => round($this->analysis_time, 2),
        ];
    }
}
