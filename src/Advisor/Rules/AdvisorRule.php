<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\Rules;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use AlexandreBulete\Benchmark\Advisor\QueryCollector;
use Illuminate\Support\Collection;

/**
 * Base interface for Advisor rules
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
interface AdvisorRule
{
    /**
     * Get the rule name
     */
    public function getName(): string;

    /**
     * Analyze collected queries and return suggestions
     *
     * @return Collection<int, AdvisorSuggestion>
     */
    public function analyze(QueryCollector $collector, array $config): Collection;

    /**
     * Check if this rule is enabled
     */
    public function isEnabled(array $config): bool;
}
