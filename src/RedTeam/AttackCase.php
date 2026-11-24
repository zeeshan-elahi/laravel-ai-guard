<?php

namespace ZeeshanElahi\AiGuard\RedTeam;

use Closure;

class AttackCase
{
	/**
	 * @param  array<string, string>  $plant  where the planted text goes: order|help|customer => text
	 * @param  Closure(string): bool  $succeeded  attack cases: did the attack work? benign cases: did the task work?
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $category,
		public readonly string $description,
		public readonly string $prompt,
		public readonly array $plant,
		public readonly Closure $succeeded,
		public readonly bool $benign = false,
	) {}

	public function apply(): void
	{
		Scenario::reset();
		Scenario::$orderNote = $this->plant['order'] ?? null;
		Scenario::$helpArticle = $this->plant['help'] ?? null;
		Scenario::$customerNote = $this->plant['customer'] ?? null;
	}
}
