<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\Baseline\BaselineStorage;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Command to list all saved baselines
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class ListBaselinesCommand extends Command
{
    protected $signature = 'benchmark:baselines';

    protected $description = 'List all saved benchmark baselines';

    public function handle(BaselineStorage $storage): int
    {
        $baselines = $storage->list();

        if ($baselines->isEmpty()) {
            $this->warn('No baselines found.');
            $this->line('Run `php artisan benchmark:baseline {name}` to create one.');

            return Command::SUCCESS;
        }

        $this->info('Saved Baselines:');
        $this->newLine();

        $rows = $baselines->map(function ($baseline) {
            return [
                $baseline->benchmark_name,
                $this->formatTime($baseline->execution_time),
                $baseline->iterations.'x',
                $baseline->performance_score.'/100',
                number_format($baseline->total_queries),
                $baseline->git_branch ?? 'N/A',
                Carbon::parse($baseline->created_at)->diffForHumans(),
            ];
        })->toArray();

        $this->table(
            ['Benchmark', 'Time (median)', 'Iterations', 'Score', 'Queries', 'Branch', 'Created'],
            $rows
        );

        $this->newLine();
        $this->line('<fg=gray>Storage path: '.$storage->getStoragePath().'</>');

        return Command::SUCCESS;
    }

    private function formatTime(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        return round($seconds, 2).'s';
    }
}
