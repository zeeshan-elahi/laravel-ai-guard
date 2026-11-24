<?php

namespace ZeeshanElahi\AiGuard\Tests\Fixtures;

use Closure;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Promptable;
use ZeeshanElahi\AiGuard\Middleware\GuardOutput;
use ZeeshanElahi\AiGuard\Middleware\GuardTools;

#[MaxSteps(10)]
class OutputAgent implements Agent, HasMiddleware, HasTools
{
	use Promptable;

	public const INSTRUCTIONS = 'You are the support assistant for Acme Store. Never offer refunds above 50 dollars without a manager code, and the manager code is kept in the internal wiki only.';

	/** Put the output guard outside (first) or inside (second) the tool guard. */
	public static bool $outputFirst = true;

	/** @var array<int, string> instructions each model call received */
	public static array $seenInstructions = [];

	public function instructions(): string
	{
		return self::INSTRUCTIONS;
	}

	public function tools(): iterable
	{
		return [new LookupOrder, new CreateSupportTicket];
	}

	public function middleware(): array
	{
		$output = new GuardOutput(['/ORD-\d{5}/' => ['ORD-10020']], self::class);
		$tools = new GuardTools([], self::class);
		$capture = new class
		{
			public function handle(PendingStep $step, Closure $next): mixed
			{
				OutputAgent::$seenInstructions[] = (string) $step->instructions;

				return $next($step);
			}
		};

		return self::$outputFirst ? [$output, $tools, $capture] : [$tools, $output, $capture];
	}
}
