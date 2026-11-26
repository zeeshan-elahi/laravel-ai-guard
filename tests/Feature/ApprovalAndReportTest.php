<?php

namespace ZeeshanElahi\AiGuard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request;
use ZeeshanElahi\AiGuard\Middleware\GuardTools;
use ZeeshanElahi\AiGuard\Tests\Fixtures\ApprovableRefund;
use ZeeshanElahi\AiGuard\Tests\Fixtures\CreateSupportTicket;
use ZeeshanElahi\AiGuard\Tests\Fixtures\OrderAgent;
use ZeeshanElahi\AiGuard\Tests\Fixtures\RefundAgent;
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

	public function test_a_call_the_policy_blocks_never_reaches_a_reviewer(): void
	{
		ApprovableRefund::$refunded = [];

		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10001']),
			'Your order has shipped.',
		]);

		$response = (new RefundAgent)->withMessages([])->prompt('What is the status of order ORD-10012?');

		$this->assertCount(0, $response->pendingApprovals);
		$this->assertSame([], ApprovableRefund::$refunded);
		$this->assertStringStartsWith('Blocked by policy:', $response->toolResults->first()->result);
		$this->assertSame(1, DB::table('ai_guard_events')->where('event', 'tool.blocked')->count());
		$this->assertDatabaseHas('ai_guard_events', [
			'event' => 'tool.blocked',
			'tool' => 'ApprovableRefund',
			'rule' => 'user_intent',
		]);
	}

	public function test_an_argument_not_from_the_user_never_reaches_a_reviewer(): void
	{
		ApprovableRefund::$refunded = [];

		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10001']),
			'Done.',
		]);

		$response = (new RefundAgent)->withMessages([])->prompt('Please refund order ORD-10012.');

		$this->assertCount(0, $response->pendingApprovals);
		$this->assertSame([], ApprovableRefund::$refunded);
		$this->assertDatabaseHas('ai_guard_events', [
			'event' => 'tool.blocked',
			'rule' => 'argument_not_from_user',
		]);
	}

	public function test_an_allowed_call_still_pauses_for_approval(): void
	{
		ApprovableRefund::$refunded = [];

		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'Refunded.',
		]);

		// Ad-hoc history (even empty) makes the run resumable, which the SDK needs before it pauses.
		$response = (new RefundAgent)->withMessages([])->prompt('Please refund order ORD-10012.');

		$this->assertCount(1, $response->pendingApprovals);
		$this->assertSame('call-1', $response->pendingApprovals->first()->id);
		$this->assertSame([], ApprovableRefund::$refunded);
		$this->assertSame(0, DB::table('ai_guard_events')->where('event', 'tool.blocked')->count());
	}

	public function test_with_the_guard_disabled_the_call_pauses_as_before(): void
	{
		config(['ai-guard.enabled' => false]);
		ApprovableRefund::$refunded = [];

		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10001']),
			'Done.',
		]);

		$response = (new RefundAgent)->withMessages([])->prompt('What is the status of order ORD-10012?');

		$this->assertCount(1, $response->pendingApprovals);
		$this->assertSame([], ApprovableRefund::$refunded);
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
