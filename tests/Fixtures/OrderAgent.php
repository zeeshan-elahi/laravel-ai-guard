<?php

namespace ZeeshanElahi\AiGuard\Tests\Fixtures;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use ZeeshanElahi\AiGuard\Middleware\GuardTools;
use ZeeshanElahi\AiGuard\Policy\ToolPolicy;

#[MaxSteps(10)]
class OrderAgent implements Agent, HasMiddleware, HasTools
{
	use Promptable;

	/** @var array<int, ToolPolicy>|null */
	public static ?array $policies = null;

	public function instructions(): string
	{
		return 'You are a support agent for an online store.';
	}

	public function tools(): iterable
	{
		return [new LookupOrder, new CreateSupportTicket];
	}

	public function middleware(): array
	{
		return [new GuardTools(self::$policies ?? self::defaultPolicies(), self::class)];
	}

	/**
	 * @return array<int, ToolPolicy>
	 */
	public static function defaultPolicies(): array
	{
		return [
			ToolPolicy::for(CreateSupportTicket::class)
				->requiresUserIntent(['ticket', 'refund', 'replacement'])
				->argumentsFromUserMessage(['order_number'])
				->maxCallsPerRun(1),
		];
	}
}
