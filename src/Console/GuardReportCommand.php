<?php

namespace ZeeshanElahi\AiGuard\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GuardReportCommand extends Command
{
	protected $signature = 'guard:report
		{--hours=24 : Only events from the last N hours}
		{--agent= : Only events for this agent}
		{--blocked : List the most recent blocked calls}';

	protected $description = 'Summarise AI Guard decisions: allowed, blocked and failed tool calls';

	public function handle(): int
	{
		$hours = max(1, (int) $this->option('hours'));
		$query = DB::table(config('ai-guard.audit.table', 'ai_guard_events'))
			->where('created_at', '>=', now()->subHours($hours))
			->when($this->option('agent'), fn ($query, $agent) => $query->where('agent', $agent));

		$rows = (clone $query)
			->selectRaw('tool, decision, count(*) as total')
			->whereNotNull('tool')
			->groupBy('tool', 'decision')
			->orderBy('tool')
			->get();

		if ($rows->isEmpty()) {
			$this->info("No AI Guard events in the last {$hours} hour(s).");

			return self::SUCCESS;
		}

		$table = $rows->groupBy('tool')->map(fn ($group, $tool) => [
			$tool,
			(int) $group->firstWhere('decision', 'allowed')?->total,
			(int) $group->firstWhere('decision', 'blocked')?->total,
			(int) $group->firstWhere('decision', 'failed')?->total,
		])->values()->all();

		$this->line("AI Guard, last {$hours} hour(s)");
		$this->table(['Tool', 'Allowed', 'Blocked', 'Failed'], $table);

		$rules = (clone $query)
			->selectRaw('rule, count(*) as total')
			->where('decision', 'blocked')
			->groupBy('rule')
			->orderByDesc('total')
			->get();

		if ($rules->isNotEmpty()) {
			$this->table(['Blocking rule', 'Count'], $rules->map(fn ($row) => [$row->rule, (int) $row->total])->all());
		}

		if ($this->option('blocked')) {
			$recent = (clone $query)
				->where('decision', 'blocked')
				->latest('created_at')
				->limit(20)
				->get(['created_at', 'agent', 'tool', 'rule', 'reason']);

			$this->table(
				['When', 'Agent', 'Tool', 'Rule', 'Reason'],
				$recent->map(fn ($row) => [(string) $row->created_at, $row->agent, $row->tool, $row->rule, $row->reason])->all(),
			);
		}

		return self::SUCCESS;
	}
}
