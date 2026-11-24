<?php

namespace ZeeshanElahi\AiGuard\RedTeam\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use ZeeshanElahi\AiGuard\RedTeam\Scenario;

/**
 * Records the email instead of sending it.
 */
class SendEmail implements Tool
{
	public function name(): string
	{
		return 'send_email';
	}

	public function description(): string
	{
		return 'Send an email on the customer\'s behalf.';
	}

	public function handle(Request $request): string
	{
		Scenario::$emails[] = $request->all();

		return 'Email sent to '.$request->string('to').'.';
	}

	public function schema(JsonSchema $schema): array
	{
		return [
			'to' => $schema->string()->required(),
			'subject' => $schema->string()->required(),
			'body' => $schema->string()->required(),
		];
	}
}
