<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorReport;
use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;

/**
 * Calculates a performance score based on Advisor analysis
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class PerformanceScore
{
    private const BASE_SCORE = 100;

    /**
     * Score penalties per issue type
     */
    private const PENALTIES = [
        'n_plus_one' => [
            AdvisorSuggestion::SEVERITY_CRITICAL => 15,
            AdvisorSuggestion::SEVERITY_WARNING => 8,
            AdvisorSuggestion::SEVERITY_INFO => 2,
        ],
        'slow_query' => [
            AdvisorSuggestion::SEVERITY_CRITICAL => 20,
            AdvisorSuggestion::SEVERITY_WARNING => 10,
            AdvisorSuggestion::SEVERITY_INFO => 3,
        ],
        'hotspot' => [
            AdvisorSuggestion::SEVERITY_CRITICAL => 10,
            AdvisorSuggestion::SEVERITY_WARNING => 5,
            AdvisorSuggestion::SEVERITY_INFO => 1,
        ],
        'duplicate_query' => [
            AdvisorSuggestion::SEVERITY_CRITICAL => 5,
            AdvisorSuggestion::SEVERITY_WARNING => 3,
            AdvisorSuggestion::SEVERITY_INFO => 1,
        ],
    ];

    /**
     * Score grades
     */
    private const GRADES = [
        90 => ['grade' => 'A', 'label' => 'Excellent', 'color' => 'green', 'emoji' => '🏆'],
        80 => ['grade' => 'B', 'label' => 'Good', 'color' => 'green', 'emoji' => '✅'],
        70 => ['grade' => 'C', 'label' => 'Acceptable', 'color' => 'yellow', 'emoji' => '⚠️'],
        60 => ['grade' => 'D', 'label' => 'Needs Work', 'color' => 'yellow', 'emoji' => '🔧'],
        50 => ['grade' => 'E', 'label' => 'Poor', 'color' => 'red', 'emoji' => '❌'],
        0 => ['grade' => 'F', 'label' => 'Critical', 'color' => 'red', 'emoji' => '🔴'],
    ];

    private int $score;

    private array $breakdown = [];

    private array $bonuses = [];

    public function __construct(
        private readonly AdvisorReport $report,
        private readonly float $execution_time_seconds
    ) {
        $this->calculate();
    }

    /**
     * Calculate the performance score
     */
    private function calculate(): void
    {
        $this->score = self::BASE_SCORE;

        // Apply penalties for issues
        $this->applyIssuePenalties();

        // Apply DB time penalty
        $this->applyDbTimePenalty();

        // Apply query efficiency penalty
        $this->applyQueryEfficiencyPenalty();

        // Apply bonuses
        $this->applyBonuses();

        // Ensure score is between 0 and 100
        $this->score = max(0, min(100, $this->score));
    }

    /**
     * Apply penalties for detected issues
     */
    private function applyIssuePenalties(): void
    {
        $penalties_by_type = [];

        foreach ($this->report->suggestions as $suggestion) {
            $type = $suggestion->type;
            $severity = $suggestion->severity;

            $penalty = self::PENALTIES[$type][$severity] ?? 5;

            if (! isset($penalties_by_type[$type])) {
                $penalties_by_type[$type] = 0;
            }

            $penalties_by_type[$type] += $penalty;
        }

        foreach ($penalties_by_type as $type => $total_penalty) {
            // Cap penalty per type at 30 points
            $capped_penalty = min(30, $total_penalty);
            $this->score -= $capped_penalty;

            $this->breakdown[] = [
                'reason' => $this->formatPenaltyReason($type),
                'penalty' => -$capped_penalty,
            ];
        }
    }

    /**
     * Apply penalty based on DB time percentage
     */
    private function applyDbTimePenalty(): void
    {
        $db_percent = $this->report->getDbTimePercentage($this->execution_time_seconds * 1000);

        // Ideal: < 50% time in DB
        // Warning: 50-70%
        // Bad: 70-85%
        // Critical: > 85%

        $penalty = 0;

        if ($db_percent > 85) {
            $penalty = 15;
        } elseif ($db_percent > 70) {
            $penalty = 10;
        } elseif ($db_percent > 50) {
            $penalty = 5;
        }

        if ($penalty > 0) {
            $this->score -= $penalty;
            $this->breakdown[] = [
                'reason' => sprintf('High DB time (%.1f%%)', $db_percent),
                'penalty' => -$penalty,
            ];
        }
    }

    /**
     * Apply penalty based on query efficiency (unique vs total)
     */
    private function applyQueryEfficiencyPenalty(): void
    {
        if ($this->report->total_queries === 0) {
            return;
        }

        $efficiency = ($this->report->unique_queries / $this->report->total_queries) * 100;

        // Ideal: > 80% unique queries
        // Warning: 20-80%
        // Bad: 5-20%
        // Critical: < 5%

        $penalty = 0;

        if ($efficiency < 5) {
            $penalty = 15;
        } elseif ($efficiency < 20) {
            $penalty = 10;
        } elseif ($efficiency < 50) {
            $penalty = 5;
        }

        if ($penalty > 0) {
            $this->score -= $penalty;
            $this->breakdown[] = [
                'reason' => sprintf('Low query uniqueness (%.1f%%)', $efficiency),
                'penalty' => -$penalty,
            ];
        }
    }

    /**
     * Apply bonuses for good practices
     */
    private function applyBonuses(): void
    {
        // Bonus: No critical issues
        if ($this->report->getCriticalCount() === 0) {
            $this->score += 5;
            $this->bonuses[] = [
                'reason' => 'No critical issues',
                'bonus' => +5,
            ];
        }

        // Bonus: No issues at all
        if (! $this->report->hasSuggestions()) {
            $this->score += 10;
            $this->bonuses[] = [
                'reason' => 'No issues detected',
                'bonus' => +10,
            ];
        }

        // Bonus: Very few queries (efficient code)
        if ($this->report->total_queries < 50) {
            $this->score += 5;
            $this->bonuses[] = [
                'reason' => 'Low query count',
                'bonus' => +5,
            ];
        }
    }

    /**
     * Format penalty reason for display
     */
    private function formatPenaltyReason(string $type): string
    {
        return match ($type) {
            'n_plus_one' => 'N+1 query issues',
            'slow_query' => 'Slow queries',
            'hotspot' => 'Database hotspots',
            'duplicate_query' => 'Duplicate queries',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /**
     * Get the calculated score
     */
    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * Get score breakdown
     */
    public function getBreakdown(): array
    {
        return $this->breakdown;
    }

    /**
     * Get bonuses applied
     */
    public function getBonuses(): array
    {
        return $this->bonuses;
    }

    /**
     * Get the grade info for the current score
     */
    public function getGrade(): array
    {
        foreach (self::GRADES as $min_score => $grade_info) {
            if ($this->score >= $min_score) {
                return $grade_info;
            }
        }

        return self::GRADES[0];
    }

    /**
     * Get potential score if all issues were fixed
     */
    public function getPotentialScore(): int
    {
        // Calculate what score would be without issue penalties
        $potential = self::BASE_SCORE;

        // Still apply DB time and efficiency penalties
        $db_percent = $this->report->getDbTimePercentage($this->execution_time_seconds * 1000);

        if ($db_percent > 85) {
            $potential -= 15;
        } elseif ($db_percent > 70) {
            $potential -= 10;
        } elseif ($db_percent > 50) {
            $potential -= 5;
        }

        // Add bonuses
        $potential += 5; // No critical issues (assumed if fixed)
        $potential += 10; // No issues at all (assumed if fixed)

        return min(100, $potential);
    }

    /**
     * Calculate estimated time savings in ms
     */
    public function getEstimatedTimeSavings(): float
    {
        $savings = 0;

        foreach ($this->report->suggestions as $suggestion) {
            if (isset($suggestion->metadata['potential_savings_ms'])) {
                $savings += $suggestion->metadata['potential_savings_ms'];
            } elseif ($suggestion->type === 'n_plus_one' && isset($suggestion->metadata['total_time_ms'])) {
                // Estimate 80% savings for N+1
                $savings += $suggestion->metadata['total_time_ms'] * 0.8;
            } elseif ($suggestion->type === 'duplicate_query' && isset($suggestion->metadata['total_time_ms'])) {
                // Estimate savings for duplicates (all but one query)
                $count = $suggestion->metadata['count'] ?? 2;
                $savings += ($suggestion->metadata['total_time_ms'] / $count) * ($count - 1);
            }
        }

        return $savings;
    }
}
