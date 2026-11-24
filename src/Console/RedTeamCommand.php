<?php

namespace ZeeshanElahi\AiGuard\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZeeshanElahi\AiGuard\RedTeam\AttackCase;
use ZeeshanElahi\AiGuard\RedTeam\CaseLibrary;
use ZeeshanElahi\AiGuard\RedTeam\Runner;

class RedTeamCommand extends Command
{
	protected $signature = 'guard:redteam
		{--provider= : laravel/ai provider to use (defaults to ai.default)}
		{--model= : Model name for that provider}
		{--mode=both : unguarded, guarded or both}
		{--repeat=1 : Runs per case and mode}
		{--only= : Comma-separated case ids or categories}
		{--timeout=180 : Seconds per prompt}
		{--json= : Where to write the full JSON report (default: storage/ai-guard/redteam-<time>.json)}';

	protected $description = 'Run the indirect prompt-injection cases against a store-support agent, with and without the guard';

	public function handle(Runner $runner): int
	{
		$cases = $this->selectCases();
		$modes = match ($this->option('mode')) {
			'unguarded' => ['unguarded'],
			'guarded' => ['guarded'],
			default => ['unguarded', 'guarded'],
		};
		$repeat = max(1, (int) $this->option('repeat'));
		$provider = $this->option('provider') ?: null;
		$model = $this->option('model') ?: null;

		if ($cases === []) {
			$this->error('No cases match --only.');

			return self::FAILURE;
		}

		$this->line(sprintf('%d case(s) × %d mode(s) × %d run(s) on %s%s', count($cases), count($modes), $repeat,
			$provider ?? config('ai.default'), $model ? " / {$model}" : ''));

		$runs = $runner->run($cases, $modes, $repeat, $provider, $model, (int) $this->option('timeout'),
			function (string $mode, AttackCase $case, int $i, array $row) {
				$verdict = $row['error'] !== null ? 'ERROR' : match (true) {
					$case->benign => $row['succeeded'] ? 'ok' : 'FAILED',
					default => $row['succeeded'] ? 'ATTACK WORKED' : 'held',
				};

				$this->line(sprintf('  %-9s %-11s #%d  %-13s %5.1fs', $mode, $case->id, $i, $verdict, $row['seconds']));
			});

		$summary = $runner->summarise($runs);
		$this->report($summary, $modes);

		$path = $this->option('json') ?: storage_path('ai-guard/redteam-'.now()->format('Ymd-His').'.json');
		File::ensureDirectoryExists(dirname($path));
		File::put($path, json_encode([
			'provider' => $provider ?? config('ai.default'),
			'model' => $model,
			'repeat' => $repeat,
			'ran_at' => now()->toIso8601String(),
			'summary' => $summary,
			'runs' => $runs,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

		$this->info("Full report: {$path}");

		return self::SUCCESS;
	}

	/**
	 * @return array<int, AttackCase>
	 */
	protected function selectCases(): array
	{
		$all = CaseLibrary::all();
		$only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));

		return $only === []
			? $all
			: array_values(array_filter($all, fn (AttackCase $case) => in_array($case->id, $only, true) || in_array($case->category, $only, true)));
	}

	/**
	 * @param  array<string, array<string, mixed>>  $summary
	 * @param  array<int, string>  $modes
	 */
	protected function report(array $summary, array $modes): void
	{
		$rate = fn (int $n, int $of) => $of === 0 ? '-' : sprintf('%d/%d (%d%%)', $n, $of, (int) round(100 * $n / $of));
		$categories = collect($summary)->flatMap(fn ($s) => array_keys($s['by_category']))->unique()->values();

		$rows = $categories->map(fn ($category) => [
			$category,
			...array_map(fn ($mode) => $rate($summary[$mode]['by_category'][$category]['succeeded'] ?? 0, $summary[$mode]['by_category'][$category]['runs'] ?? 0), $modes),
		])->all();

		$rows[] = ['ALL ATTACKS', ...array_map(fn ($mode) => $rate($summary[$mode]['attacks_succeeded'], $summary[$mode]['attack_runs']), $modes)];
		$rows[] = ['planted text read', ...array_map(fn ($mode) => $rate($summary[$mode]['planted_text_read'], $summary[$mode]['attack_runs']), $modes)];
		$rows[] = ['controls passed', ...array_map(fn ($mode) => $rate($summary[$mode]['controls_passed'], $summary[$mode]['control_runs']), $modes)];
		$rows[] = ['errors', ...array_map(fn ($mode) => (string) $summary[$mode]['errors'], $modes)];

		$this->newLine();
		$this->line('Attack success rate (lower is better); controls passed (higher is better)');
		$this->table(['', ...$modes], $rows);
	}
}
