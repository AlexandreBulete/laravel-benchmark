<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Baseline;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Handles storage and retrieval of baseline results
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class BaselineStorage
{
    private string $storage_path;

    public function __construct(?string $storage_path = null)
    {
        $this->storage_path = $storage_path ?? $this->getDefaultPath();
    }

    /**
     * Get default storage path
     */
    private function getDefaultPath(): string
    {
        return config('benchmark.baseline.path', base_path('tests/Benchmark/baselines'));
    }

    /**
     * Save a baseline result
     */
    public function save(BaselineResult $result): string
    {
        $this->ensureDirectoryExists();

        $filename = $this->generateFilename($result->benchmark_name);
        $filepath = $this->storage_path.'/'.$filename;

        File::put($filepath, json_encode($result->toArray(), JSON_PRETTY_PRINT));

        return $filepath;
    }

    /**
     * Load baseline for a benchmark
     */
    public function load(string $benchmark_name): ?BaselineResult
    {
        $filename = $this->generateFilename($benchmark_name);
        $filepath = $this->storage_path.'/'.$filename;

        if (! File::exists($filepath)) {
            return null;
        }

        $data = json_decode(File::get($filepath), true);

        if (! $data) {
            return null;
        }

        return BaselineResult::fromArray($data);
    }

    /**
     * Check if baseline exists for a benchmark
     */
    public function exists(string $benchmark_name): bool
    {
        $filename = $this->generateFilename($benchmark_name);
        $filepath = $this->storage_path.'/'.$filename;

        return File::exists($filepath);
    }

    /**
     * Delete baseline for a benchmark
     */
    public function delete(string $benchmark_name): bool
    {
        $filename = $this->generateFilename($benchmark_name);
        $filepath = $this->storage_path.'/'.$filename;

        if (File::exists($filepath)) {
            return File::delete($filepath);
        }

        return false;
    }

    /**
     * List all saved baselines
     *
     * @return Collection<int, BaselineResult>
     */
    public function list(): Collection
    {
        if (! File::isDirectory($this->storage_path)) {
            return collect();
        }

        return collect(File::files($this->storage_path))
            ->filter(fn ($file) => $file->getExtension() === 'json')
            ->map(function ($file) {
                $data = json_decode(File::get($file->getPathname()), true);

                return $data ? BaselineResult::fromArray($data) : null;
            })
            ->filter();
    }

    /**
     * Generate filename for a benchmark
     */
    private function generateFilename(string $benchmark_name): string
    {
        $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $benchmark_name);

        return strtolower($safe_name).'.baseline.json';
    }

    /**
     * Ensure storage directory exists
     */
    private function ensureDirectoryExists(): void
    {
        if (! File::isDirectory($this->storage_path)) {
            File::makeDirectory($this->storage_path, 0755, true);
        }
    }

    /**
     * Get the storage path
     */
    public function getStoragePath(): string
    {
        return $this->storage_path;
    }
}
