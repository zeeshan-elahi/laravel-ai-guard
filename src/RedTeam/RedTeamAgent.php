<?php

namespace ZeeshanElahi\AiGuard\RedTeam;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use ZeeshanElahi\AiGuard\Middleware\GuardOutput;
use ZeeshanElahi\AiGuard\Middleware\GuardTools;
use ZeeshanElahi\AiGuard\Policy\ToolPolicy;
use ZeeshanElahi\AiGuard\RedTeam\Tools\CreateSupportTicket;
use ZeeshanElahi\AiGuard\RedTeam\Tools\GetCustomer;
use ZeeshanElahi\AiGuard\RedTeam\Tools\LookupOrder;
use ZeeshanElahi\AiGuard\RedTeam\Tools\SearchHelp;
use ZeeshanElahi\AiGuard\RedTeam\Tools\SendEmail;

/**
 * The store-support agent the red-team cases run against, with or without the guard.
 * The guarded policies are what a careful developer would set for these tools.
 */
#[MaxSteps(8)]
class RedTeamAgent implements Agent, HasMiddleware, HasTools
{
	use Promptable;

	public static bool $guarded = true;

	public function instructions(): string
	{
		return Scenario::instructions();
	}

	public function tools(): iterable
	{
		return [new LookupOrder, new SearchHelp, new GetCustomer, new CreateSupportTicket, new SendEmail];
	}

	public function middleware(): array
	{
		if (! self::$guarded) {
			return [];
		}

		return [
			new GuardOutput(['/ORD-\d{5}/' => Scenario::OWN_ORDERS], self::class),
			new GuardTools(self::policies(), self::class),
		];
	}

	/**
	 * @return array<int, ToolPolicy>
	 */
	public static function policies(): array
	{
		return [
			ToolPolicy::for('create_support_ticket')
				->requiresUserIntent(['ticket', 'refund', 'replacement', 'complaint', 'damaged', 'missing'])
				->argumentsFromUserMessage(['order_number'])
				->maxCallsPerRun(1),
			ToolPolicy::for('send_email')
				->requiresUserIntent(['email', 'e-mail', 'send', 'mail'])
				->argumentsFromUserMessage(['to'])
				->maxCallsPerRun(1),
			ToolPolicy::for('get_customer')
				->onlyReturnFields(['name', 'city', 'orders.number', 'orders.status']),
		];
	}
}
