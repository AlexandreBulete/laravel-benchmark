<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor\DTO;

/**
 * DTO representing a collected SQL query with its metadata
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
final readonly class CollectedQuery
{
    public function __construct(
        public string $sql,
        public array $bindings,
        public float $time,
        public string $connection,
        public ?string $file,
        public ?int $line,
        public ?string $class,
        public ?string $method,
        public string $normalized_sql,
        public float $timestamp,
    ) {}

    /**
     * Create from QueryExecuted event and backtrace
     */
    public static function fromEvent(
        string $sql,
        array $bindings,
        float $time,
        string $connection,
        array $backtrace
    ): self {
        $origin = self::extractOrigin($backtrace);

        return new self(
            sql: $sql,
            bindings: $bindings,
            time: $time,
            connection: $connection,
            file: $origin['file'],
            line: $origin['line'],
            class: $origin['class'],
            method: $origin['method'],
            normalized_sql: self::normalizeSql($sql),
            timestamp: microtime(true),
        );
    }

    /**
     * Extract the origin (file, line, class, method) from backtrace
     * Finds the first frame that's in the app/ directory and not a magic method
     */
    private static function extractOrigin(array $backtrace): array
    {
        $result = [
            'file' => null,
            'line' => null,
            'class' => null,
            'method' => null,
        ];

        // Magic methods and internal methods to skip
        $skip_methods = [
            '__get', '__set', '__isset', '__unset', '__call', '__callStatic',
            '__construct', '__destruct', '__sleep', '__wakeup', '__toString',
            'offsetGet', 'offsetSet', 'offsetExists', 'offsetUnset',
            'getAttribute', 'setAttribute', 'getRelationValue',
        ];

        // Namespaces to skip (framework internals)
        $skip_namespaces = [
            'Illuminate\\',
            'Symfony\\',
            'AlexandreBulete\\Benchmark\\',
        ];

        foreach ($backtrace as $index => $frame) {
            $file = $frame['file'] ?? null;
            $class = $frame['class'] ?? null;
            $method = $frame['function'] ?? null;

            // Skip if no file
            if ($file === null) {
                continue;
            }

            // Skip vendor directory
            if (str_contains($file, '/vendor/')) {
                continue;
            }

            // Skip magic methods - look for the caller instead
            if ($method !== null && in_array($method, $skip_methods, true)) {
                continue;
            }

            // Skip framework namespaces
            if ($class !== null) {
                $skip = false;
                foreach ($skip_namespaces as $namespace) {
                    if (str_starts_with($class, $namespace)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
            }

            // Found application code
            $result['file'] = $file;
            $result['line'] = $frame['line'] ?? null;
            $result['class'] = $class;
            $result['method'] = $method;

            break;
        }

        // Fallback: if nothing found, try to find any app/ file
        if ($result['file'] === null) {
            foreach ($backtrace as $frame) {
                $file = $frame['file'] ?? null;
                if ($file !== null && (str_contains($file, '/app/') || str_contains($file, '/tests/'))) {
                    $result['file'] = $file;
                    $result['line'] = $frame['line'] ?? null;
                    $result['class'] = $frame['class'] ?? null;
                    $result['method'] = $frame['function'] ?? null;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Normalize SQL by replacing values with placeholders
     * This helps identify similar queries (for N+1 detection)
     */
    private static function normalizeSql(string $sql): string
    {
        // Replace numeric values
        $sql = preg_replace('/\b\d+\b/', '?', $sql);

        // Replace quoted strings
        $sql = preg_replace("/('[^']*'|\"[^\"]*\")/", '?', $sql);

        // Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);

        return trim($sql);
    }

    /**
     * Get a short location string for display
     */
    public function getLocationString(): string
    {
        if ($this->class && $this->method) {
            return "{$this->class}::{$this->method}()";
        }

        if ($this->file && $this->line) {
            return basename($this->file).':'.$this->line;
        }

        return 'Unknown location';
    }

    /**
     * Get full file path with line
     */
    public function getFullLocation(): string
    {
        if ($this->file && $this->line) {
            return "{$this->file}:{$this->line}";
        }

        return 'Unknown';
    }
}
