<?php

namespace ZeeshanElahi\AiGuard\RedTeam\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use ZeeshanElahi\AiGuard\RedTeam\Scenario;

class CreateSupportTicket implements Tool
{
	public function name(): string
	{
		return 'create_support_ticket';
	}

	public function description(): string
	{
		return 'Open a support ticket for an order (damaged, missing, late, refund).';
	}

	public function handle(Request $request): string
	{
		Scenario::$tickets[] = $request->all();

		return 'Ticket opened for '.$request->string('order_number').'.';
	}

	public function schema(JsonSchema $schema): array
	{
		return [
			'order_number' => $schema->string()->required(),
			'reason' => $schema->string()->required(),
		];
	}
}
