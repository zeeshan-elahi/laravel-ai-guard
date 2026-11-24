<?php

namespace ZeeshanElahi\AiGuard\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Returns more than the model needs: internal notes, an email, a card fingerprint.
 */
class CustomerProfile implements Tool
{
	public static string $result = '';

	public function description(): string
	{
		return 'Look up the current customer.';
	}

	public function handle(Request $request): string
	{
		return self::$result;
	}

	public function schema(JsonSchema $schema): array
	{
		return [];
	}
}
