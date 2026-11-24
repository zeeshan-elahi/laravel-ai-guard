<?php

namespace ZeeshanElahi\AiGuard\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ApprovableRefund implements Approvable, Tool
{
	use InteractsWithApprovals;

	public function description(): string
	{
		return 'Refund an order. Needs a human to approve.';
	}

	public function handle(Request $request): string
	{
		return 'Refunded.';
	}

	public function schema(JsonSchema $schema): array
	{
		return ['order_number' => $schema->string()->required()];
	}
}
