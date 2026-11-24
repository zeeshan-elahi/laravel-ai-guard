<?php

namespace ZeeshanElahi\AiGuard\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Write tool. Records what it would have created so tests can check nothing slipped through.
 */
class CreateSupportTicket implements Tool
{
	/** @var array<int, array<string, mixed>> */
	public static array $created = [];

	public function description(): string
	{
		return 'Open a support ticket for an order.';
	}

	public function handle(Request $request): string
	{
		self::$created[] = $request->all();

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
