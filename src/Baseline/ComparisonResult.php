<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Baseline;

/**
 * Result of comparing current benchmark to baseline
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class ComparisonResult
{
    public function __construct(
        public BaselineResult $baseline,
        public BaselineResult $current,
        /** @var array<RegressionItem> */
        public array $regressions,
        /** @var array<ImprovementItem> */
        public array $improvements,
        public bool $has_critical,
        public bool $has_warning,
    ) {}

    /**
     * Check if there are any regressions
     */
    public function hasRegressions(): bool
    {
        return ! empty($this->regressions);
    }

    /**
     * Check if there are any improvements
     */
    public function hasImprovements(): bool
    {
        return ! empty($this->improvements);
    }

    /**
     * Get overall status
     */
    public function getStatus(): string
    {
        if ($this->has_critical) {
            return 'critical';
        }

        if ($this->has_warning) {
            return 'warning';
        }

        if ($this->hasImprovements() && ! $this->hasRegressions()) {
            return 'improved';
        }

        return 'stable';
    }

    /**
     * Get status emoji
     */
    public function getStatusEmoji(): string
    {
        return match ($this->getStatus()) {
            'critical' => '🔴',
            'warning' => '⚠️',
            'improved' => '🚀',
            'stable' => '✅',
            default => '📊',
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match ($this->getStatus()) {
            'critical' => 'REGRESSION DETECTED',
            'warning' => 'Performance Warning',
            'improved' => 'Performance Improved',
            'stable' => 'Stable',
            default => 'Unknown',
        };
    }

    /**
     * Should fail CI?
     */
    public function shouldFailCI(): bool
    {
        return $this->has_critical;
    }

    /**
     * Convert to array for export
     */
    public function toArray(): array
    {
        return [
            'status' => $this->getStatus(),
            'has_regressions' => $this->hasRegressions(),
            'has_improvements' => $this->hasImprovements(),
            'baseline' => $this->baseline->toArray(),
            'current' => $this->current->toArray(),
            'regressions' => array_map(fn ($r) => $r->toArray(), $this->regressions),
            'improvements' => array_map(fn ($i) => $i->toArray(), $this->improvements),
        ];
    }
}
