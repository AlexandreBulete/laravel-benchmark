<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Console;

use AlexandreBulete\Benchmark\BenchmarkRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Command to list all available benchmarks
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class ListBenchmarksCommand extends Command
{
    protected $signature = 'benchmark:list';

    protected $description = 'List all available benchmark suites';

    public function handle(): int
    {
        $benchmarks = BenchmarkRegistry::discover();

        if ($benchmarks->isEmpty()) {
            $this->warn('No benchmarks found.');
            $this->newLine();
            $this->line('Create one with: <comment>php artisan make:benchmark MyBenchmark</comment>');

            return Command::SUCCESS;
        }

        $this->info('📋 Available benchmarks:');
        $this->newLine();

        $rows = [];
        foreach ($benchmarks as $name => $benchmark) {
            $instance = new $benchmark['class'];
            $code = $benchmark['code'] ?? '-';
            $command = $code !== '-' ? "benchmark:{$code}" : 'benchmark:run '.$name;

            $rows[] = [
                $name,
                $code !== '-' ? $code : '<fg=gray>-</>',
                Str::limit($instance->getDescription(), 40),
                "<comment>{$command}</comment>",
            ];
        }

        $this->table(
            ['Class', 'Code', 'Description', 'Command'],
            $rows
        );

        $this->newLine();
        $this->line('Run a benchmark:');
        $this->line('  <comment>php artisan benchmark:run {ClassName}</comment>');
        $this->line('  <comment>php artisan benchmark:{code} [options]</comment> (if code is defined)');

        return Command::SUCCESS;
    }
}
