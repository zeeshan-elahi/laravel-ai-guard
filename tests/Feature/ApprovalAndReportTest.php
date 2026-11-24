<?php

namespace ZeeshanElahi\AiGuard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use ZeeshanElahi\AiGuard\Middleware\GuardTools;
use ZeeshanElahi\AiGuard\Tests\Fixtures\ApprovableRefund;
use ZeeshanElahi\AiGuard\Tests\Fixtures\CreateSupportTicket;
use ZeeshanElahi\AiGuard\Tests\Fixtures\OrderAgent;
use ZeeshanElahi\AiGuard\Tests\TestCase;
use ZeeshanElahi\AiGuard\Tools\GuardedApprovableTool;

class ApprovalAndReportTest extends TestCase
{
	use RefreshDatabase;

	public function test_approval_settings_pass_through_the_wrapper(): void
	{
		$wrapped = null;

		(new GuardTools)->handle(
			new PendingStep(0, false, 'ollama', 'test', null, [new UserMessage('refund ORD-1')], [new ApprovableRefund], null, null),
			function (PendingStep $step) use (&$wrapped) {
				$wrapped = $step->tools[0];

				return StepResult::stop();
			},
		);

		$this->assertInstanceOf(GuardedApprovableTool::class, $wrapped);
		$this->assertNotNull($wrapped->shouldRequestApproval(new Request(['order_number' => 'ORD-1'])));
		$this->assertNull($wrapped->withoutApproval()->shouldRequestApproval(new Request(['order_number' => 'ORD-1'])));
	}

	public function test_report_summarises_decisions(): void
	{
		CreateSupportTicket::$created = [];
		OrderAgent::$policies = null;

		OrderAgent::fake([
			new ToolCall('call-1', 'LookupOrder', ['order_number' => 'ORD-10012']),
			new ToolCall('call-2', 'CreateSupportTicket', ['order_number' => 'ORD-10001', 'reason' => 'refund']),
			'Shipped.',
		]);

		(new OrderAgent)->prompt('Status of ORD-10012?');

		$this->artisan('guard:report', ['--blocked' => true])
			->expectsOutputToContain('AI Guard, last 24 hour(s)')
			->expectsOutputToContain('user_intent')
			->assertSuccessful();
	}

	public function test_report_with_no_events(): void
	{
		$this->artisan('guard:report')
			->expectsOutputToContain('No AI Guard events')
			->assertSuccessful();
	}
}
