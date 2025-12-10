<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Registry for discovering and indexing benchmark classes
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class BenchmarkRegistry
{
    /**
     * Cached benchmarks
     *
     * @var Collection<string, array{class: class-string<BenchmarkCase>, code: ?string, options: array}>|null
     */
    protected static ?Collection $benchmarks = null;

    /**
     * Discover all benchmark classes in the configured path
     *
     * @return Collection<string, array{class: class-string<BenchmarkCase>, code: ?string, options: array}>
     */
    public static function discover(): Collection
    {
        if (static::$benchmarks !== null) {
            return static::$benchmarks;
        }

        $path = base_path(config('benchmark.path', 'tests/Benchmark/Suites'));
        $namespace = config('benchmark.namespace', 'Tests\\Benchmark\\Suites');

        static::$benchmarks = collect();

        if (! File::isDirectory($path)) {
            return static::$benchmarks;
        }

        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $file->getFilenameWithoutExtension();
            $fullClass = $namespace.'\\'.$className;

            if (! class_exists($fullClass)) {
                continue;
            }

            if (! is_subclass_of($fullClass, BenchmarkCase::class)) {
                continue;
            }

            static::$benchmarks->put($className, [
                'class' => $fullClass,
                'code' => $fullClass::getCode(),
                'options' => $fullClass::getOptions(),
            ]);
        }

        return static::$benchmarks;
    }

    /**
     * Get all benchmarks with a command code
     *
     * @return Collection<string, array{class: class-string<BenchmarkCase>, code: string, options: array}>
     */
    public static function getWithCodes(): Collection
    {
        return static::discover()->filter(fn ($benchmark) => $benchmark['code'] !== null);
    }

    /**
     * Find a benchmark by its class name
     */
    public static function findByName(string $name): ?array
    {
        $benchmarks = static::discover();

        // Try exact match
        if ($benchmarks->has($name)) {
            return $benchmarks->get($name);
        }

        // Try with 'Benchmark' suffix
        $withSuffix = $name.'Benchmark';
        if ($benchmarks->has($withSuffix)) {
            return $benchmarks->get($withSuffix);
        }

        return null;
    }

    /**
     * Find a benchmark by its command code
     */
    public static function findByCode(string $code): ?array
    {
        return static::getWithCodes()
            ->first(fn ($benchmark) => $benchmark['code'] === $code);
    }

    /**
     * Clear the cache
     */
    public static function clearCache(): void
    {
        static::$benchmarks = null;
    }

    /**
     * Get all registered benchmark codes
     *
     * @return array<string>
     */
    public static function getCodes(): array
    {
        return static::getWithCodes()
            ->pluck('code')
            ->filter()
            ->values()
            ->toArray();
    }
}
