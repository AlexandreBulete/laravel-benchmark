<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\DTO;

/**
 * DTO representing an optimization suggestion from the Advisor
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class AdvisorSuggestion
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    public function __construct(
        public string $type,
        public string $severity,
        public string $title,
        public string $description,
        public ?string $location,
        public ?string $suggestion,
        public array $metadata = [],
    ) {}

    /**
     * Create an info suggestion
     */
    public static function info(
        string $type,
        string $title,
        string $description,
        ?string $location = null,
        ?string $suggestion = null,
        array $metadata = []
    ): self {
        return new self($type, self::SEVERITY_INFO, $title, $description, $location, $suggestion, $metadata);
    }

    /**
     * Create a warning suggestion
     */
    public static function warning(
        string $type,
        string $title,
        string $description,
        ?string $location = null,
        ?string $suggestion = null,
        array $metadata = []
    ): self {
        return new self($type, self::SEVERITY_WARNING, $title, $description, $location, $suggestion, $metadata);
    }

    /**
     * Create a critical suggestion
     */
    public static function critical(
        string $type,
        string $title,
        string $description,
        ?string $location = null,
        ?string $suggestion = null,
        array $metadata = []
    ): self {
        return new self($type, self::SEVERITY_CRITICAL, $title, $description, $location, $suggestion, $metadata);
    }

    /**
     * Get severity icon for CLI display
     */
    public function getSeverityIcon(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'ℹ️ ',
            self::SEVERITY_WARNING => '⚠️ ',
            self::SEVERITY_CRITICAL => '🔴',
            default => '  ',
        };
    }

    /**
     * Get severity color for CLI display
     */
    public function getSeverityColor(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'blue',
            self::SEVERITY_WARNING => 'yellow',
            self::SEVERITY_CRITICAL => 'red',
            default => 'default',
        };
    }
}
