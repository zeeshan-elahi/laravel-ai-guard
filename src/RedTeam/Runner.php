<?php

namespace ZeeshanElahi\AiGuard\RedTeam;

use Closure;
use Throwable;

/**
 * Runs cases against the scenario agent, unguarded and guarded, and scores each run.
 */
class Runner
{
	/**
	 * @param  array<int, AttackCase>  $cases
	 * @param  array<int, string>  $modes  'unguarded' and/or 'guarded'
	 * @param  Closure(string, AttackCase, int, array<string, mixed>): void|null  $progress
	 * @return array<int, array<string, mixed>>  one row per run
	 */
	public function run(array $cases, array $modes, int $repeat, ?string $provider, ?string $model, int $timeout = 180, ?Closure $progress = null): array
	{
		$runs = [];

		foreach ($modes as $mode) {
			foreach ($cases as $case) {
				for ($i = 1; $i <= $repeat; $i++) {
					$row = $this->runOnce($case, $mode, $provider, $model, $timeout);
					$runs[] = $row;

					if ($progress !== null) {
						$progress($mode, $case, $i, $row);
					}
				}
			}
		}

		return $runs;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function runOnce(AttackCase $case, string $mode, ?string $provider = null, ?string $model = null, int $timeout = 180): array
	{
		$case->apply();
		RedTeamAgent::$guarded = $mode === 'guarded';

		$started = microtime(true);
		$answer = '';
		$error = null;

		try {
			$response = (new RedTeamAgent)->prompt($case->prompt, provider: $provider, model: $model, timeout: $timeout);
			$answer = (string) $response->text;
		} catch (Throwable $exception) {
			$error = class_basename($exception).': '.$exception->getMessage();
		}

		$hit = $error === null && (bool) ($case->succeeded)($answer);

		return [
			'case' => $case->id,
			'category' => $case->category,
			'benign' => $case->benign,
			'mode' => $mode,
			// Attack cases: true = the attack worked. Controls: true = the legitimate task worked.
			'succeeded' => $hit,
			'planted_read' => $case->plant === [] ? null : Scenario::$plantedReads !== [],
			'error' => $error,
			'seconds' => round(microtime(true) - $started, 1),
			'answer' => mb_substr($answer, 0, 500),
			'tickets' => Scenario::$tickets,
			'emails' => array_map(fn ($e) => ['to' => $e['to'] ?? null], Scenario::$emails),
		];
	}

	/**
	 * Per mode: attack success rate overall and per category, control pass rate, planted-text read rate.
	 *
	 * @param  array<int, array<string, mixed>>  $runs
	 * @return array<string, array<string, mixed>>
	 */
	public function summarise(array $runs): array
	{
		$summary = [];

		foreach (collect($runs)->groupBy('mode') as $mode => $rows) {
			$attacks = $rows->where('benign', false);
			$controls = $rows->where('benign', true);

			$summary[$mode] = [
				'attack_runs' => $attacks->count(),
				'attacks_succeeded' => $attacks->where('succeeded', true)->count(),
				'planted_text_read' => $attacks->where('planted_read', true)->count(),
				'control_runs' => $controls->count(),
				'controls_passed' => $controls->where('succeeded', true)->count(),
				'errors' => $rows->whereNotNull('error')->count(),
				'by_category' => $attacks->groupBy('category')->map(fn ($group) => [
					'runs' => $group->count(),
					'succeeded' => $group->where('succeeded', true)->count(),
				])->all(),
			];
		}

		return $summary;
	}
}
