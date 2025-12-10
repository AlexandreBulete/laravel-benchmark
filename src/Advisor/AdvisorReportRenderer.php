<?php

declare(strict_types=1);

namespace AlexandreBulete\Benchmark\Advisor;

use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorReport;
use AlexandreBulete\Benchmark\Advisor\DTO\AdvisorSuggestion;
use Illuminate\Console\Command;

/**
 * Renders Advisor reports to the console
 *
 * @author Alexandre Bulete <bulete.alexandre@gmail.com>
 */
class AdvisorReportRenderer
{
    public function __construct(
        private readonly Command $command
    ) {}

    /**
     * Render the full Advisor report
     */
    public function render(AdvisorReport $report, float $total_execution_time): void
    {
        $this->renderHeader();
        $this->renderSummary($report, $total_execution_time);
        $this->renderSuggestions($report);
        $this->renderHotspots($report);
        $this->renderFooter($report);
    }

    /**
     * Render the header
     */
    private function renderHeader(): void
    {
        $this->command->newLine();
        $this->command->line('╔════════════════════════════════════════════════════════════════╗');
        $this->command->line('║                    📊 ADVISOR REPORT                           ║');
        $this->command->line('╚════════════════════════════════════════════════════════════════╝');
    }

    /**
     * Render summary statistics
     */
    private function renderSummary(AdvisorReport $report, float $total_execution_time): void
    {
        $this->command->newLine();
        $this->command->line('<fg=cyan>Database Statistics:</>');

        $db_percent = $report->getDbTimePercentage($total_execution_time * 1000);

        $this->command->table([], [
            ['Total Queries', number_format($report->total_queries)],
            ['Unique Queries', number_format($report->unique_queries)],
            ['Total DB Time', $this->formatTime($report->total_db_time)],
            ['DB Time %', sprintf('%.1f%%', $db_percent)],
        ]);

        // Show issue counts
        $critical = $report->getCriticalCount();
        $warnings = $report->getWarningCount();
        $info = $report->getInfoCount();

        if ($critical > 0 || $warnings > 0 || $info > 0) {
            $this->command->line('<fg=cyan>Issues Found:</>');

            $issues = [];
            if ($critical > 0) {
                $issues[] = "<fg=red>🔴 {$critical} critical</>";
            }
            if ($warnings > 0) {
                $issues[] = "<fg=yellow>⚠️  {$warnings} warnings</>";
            }
            if ($info > 0) {
                $issues[] = "<fg=blue>ℹ️  {$info} info</>";
            }

            $this->command->line('  '.implode('  ', $issues));
        } else {
            $this->command->info('  ✅ No issues detected!');
        }
    }

    /**
     * Render suggestions
     */
    private function renderSuggestions(AdvisorReport $report): void
    {
        if (! $report->hasSuggestions()) {
            return;
        }

        $max_per_type = config('benchmark.advisor.display.max_per_type', 3);
        $max_total = config('benchmark.advisor.display.max_total', 10);

        $this->command->newLine();
        $this->command->line('<fg=cyan>Optimization Suggestions:</>');
        $this->command->newLine();

        // Group by type and limit
        $by_type = $report->suggestions->groupBy('type');
        $displayed = 0;
        $hidden_counts = [];

        foreach ($by_type as $type => $suggestions) {
            $count = $suggestions->count();
            $to_show = min($max_per_type, $count);

            // Check total limit
            if ($displayed >= $max_total) {
                $hidden_counts[$type] = ($hidden_counts[$type] ?? 0) + $count;

                continue;
            }

            // Show limited suggestions
            foreach ($suggestions->take($to_show) as $suggestion) {
                if ($displayed >= $max_total) {
                    break;
                }
                $this->renderSuggestion($suggestion, $displayed + 1);
                $displayed++;
            }

            // Track hidden
            $hidden = $count - $to_show;
            if ($hidden > 0) {
                $hidden_counts[$type] = ($hidden_counts[$type] ?? 0) + $hidden;
            }
        }

        // Show hidden summary
        if (! empty($hidden_counts)) {
            $this->command->line('<fg=gray>───────────────────────────────────────────────────────────────</>');
            $this->command->line('<fg=gray>Additional issues not shown:</>');
            foreach ($hidden_counts as $type => $count) {
                $this->command->line("<fg=gray>  • {$count} more [{$type}] issues</>");
            }
            $this->command->newLine();
        }
    }

    /**
     * Render a single suggestion
     */
    private function renderSuggestion(AdvisorSuggestion $suggestion, int $index): void
    {
        $color = $suggestion->getSeverityColor();
        $icon = $suggestion->getSeverityIcon();

        // Title line
        $this->command->line("<fg={$color}>{$icon} [{$suggestion->type}] {$suggestion->title}</>");

        // Description
        $this->command->line("   {$suggestion->description}");

        // Location
        if ($suggestion->location) {
            $this->command->line("   <fg=gray>📍 {$suggestion->location}</>");
        }

        // Suggestion
        if ($suggestion->suggestion) {
            $lines = explode("\n", $suggestion->suggestion);
            foreach ($lines as $line) {
                $this->command->line("   <fg=green>💡 {$line}</>");
            }
        }

        // Show SQL for slow queries and N+1
        if (isset($suggestion->metadata['sql']) || isset($suggestion->metadata['sample_sql'])) {
            $sql = $suggestion->metadata['sql'] ?? $suggestion->metadata['sample_sql'];
            $truncated = strlen($sql) > 100 ? substr($sql, 0, 100).'...' : $sql;
            $this->command->line("   <fg=gray>SQL: {$truncated}</>");
        }

        $this->command->newLine();
    }

    /**
     * Render hotspots
     */
    private function renderHotspots(AdvisorReport $report): void
    {
        $top_locations = $report->getTopLocationsByQueryCount(5);

        if (empty($top_locations)) {
            return;
        }

        $this->command->newLine();
        $this->command->line('<fg=cyan>Top 5 Locations by Query Count:</>');

        $rows = [];
        foreach ($top_locations as $location => $count) {
            $time = $report->time_by_location[$location] ?? 0;
            $rows[] = [
                $location,
                number_format($count),
                $this->formatTime($time),
            ];
        }

        $this->command->table(
            ['Location', 'Queries', 'Time'],
            $rows
        );
    }

    /**
     * Render footer
     */
    private function renderFooter(AdvisorReport $report): void
    {
        $this->command->line('<fg=gray>Analysis completed in '.$this->formatTime($report->analysis_time).'</>');
    }

    /**
     * Format time value
     */
    private function formatTime(float $ms): string
    {
        if ($ms >= 60000) {
            return sprintf('%.2fm', $ms / 60000);
        }

        if ($ms >= 1000) {
            return sprintf('%.2fs', $ms / 1000);
        }

        return sprintf('%.2fms', $ms);
    }
}
