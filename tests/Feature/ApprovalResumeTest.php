<?php

namespace ZeeshanElahi\AiGuard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use ZeeshanElahi\AiGuard\GuardContext;
use ZeeshanElahi\AiGuard\Policy\ToolPolicy;
use ZeeshanElahi\AiGuard\Tests\Fixtures\ApprovableRefund;
use ZeeshanElahi\AiGuard\Tests\Fixtures\RefundAgent;
use ZeeshanElahi\AiGuard\Tests\TestCase;

/**
 * A run that pauses for a human approval and then resumes, through laravel/ai's real tool loop
 * with the model's turns scripted by the fake gateway.
 */
class ApprovalResumeTest extends TestCase
{
	use RefreshDatabase;

	protected const USER_MESSAGE = 'Please refund order ORD-10012.';

	protected function setUp(): void
	{
		parent::setUp();

		ApprovableRefund::$refunded = [];
		RefundAgent::$policies = null;
	}

	public function test_a_resumed_run_holds_the_users_original_message(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'Your refund is done.',
		]);

		$pausedResponse = $this->promptUntilPaused();
		$resumedResponse = $this->resume($pausedResponse);

		$context = app(GuardContext::class);

		$this->assertSame(['ORD-10012'], ApprovableRefund::$refunded);
		$this->assertTrue($context->resuming($resumedResponse->invocationId));
		$this->assertSame(self::USER_MESSAGE, $context->userMessage());
		$this->assertSame(RefundAgent::class, $context->agent());
	}

	public function test_a_step_after_the_resume_keeps_the_same_boundary(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'Your refund is done.',
		]);

		$pausedResponse = $this->promptUntilPaused();

		// Registered after the package's listener, so it sees the context the resume started.
		$boundaryAtResume = null;

		Event::listen(PromptingAgent::class, function () use (&$boundaryAtResume) {
			$boundaryAtResume = app(GuardContext::class)->boundary();
		});

		$resumedResponse = $this->resume($pausedResponse);

		$this->assertNotNull($boundaryAtResume);
		$this->assertSame('Your refund is done.', $resumedResponse->text);
		$this->assertSame($boundaryAtResume, app(GuardContext::class)->boundary());
		$this->assertTrue(app(GuardContext::class)->resuming($resumedResponse->invocationId));
	}

	public function test_a_normal_run_is_not_marked_as_resuming(): void
	{
		RefundAgent::fake([
			'Your order has shipped.',
		]);

		$response = (new RefundAgent)->withMessages([])->prompt('What is the status of order ORD-10012?');

		$this->assertFalse(app(GuardContext::class)->resuming($response->invocationId));
	}

	public function test_a_new_run_after_a_resume_is_not_marked_as_resuming(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'Your refund is done.',
			'Your order has shipped.',
		]);

		$pausedResponse = $this->promptUntilPaused();
		$resumedResponse = $this->resume($pausedResponse);
		$nextResponse = (new RefundAgent)->withMessages([])->prompt('What is the status of order ORD-10012?');

		$context = app(GuardContext::class);

		$this->assertFalse($context->resuming($nextResponse->invocationId));
		$this->assertFalse($context->resuming($resumedResponse->invocationId));
	}

	public function test_an_approved_call_the_policy_allows_runs_and_is_logged(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'Your refund is done.',
		]);

		$pausedResponse = $this->promptUntilPaused();
		$this->resume($pausedResponse);

		$this->assertSame(['ORD-10012'], ApprovableRefund::$refunded);
		$this->assertStringContainsString('"after_approval":true', $this->payloadOf('tool.allowed'));
	}

	public function test_an_approved_call_is_checked_against_the_message_in_the_resumed_history(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'I could not refund that order.',
		]);

		$pausedResponse = $this->promptUntilPaused();

		// Ad-hoc history comes back from the client, so it can differ from the run that paused.
		$resumedResponse = $this->resume($pausedResponse, 'What is the status of order ORD-10012?');
		$toolResult = $resumedResponse->toolResults->first();

		$this->assertSame([], ApprovableRefund::$refunded);
		$this->assertTrue($toolResult->failed);
		$this->assertStringStartsWith('The tool call failed: Blocked by policy:', $toolResult->result);
		$this->assertDatabaseHas('ai_guard_events', [
			'event' => 'tool.blocked',
			'tool' => 'ApprovableRefund',
			'rule' => 'user_intent',
			'agent' => RefundAgent::class,
		]);
		$this->assertStringContainsString('"after_approval":true', $this->payloadOf('tool.blocked'));
	}

	public function test_edited_arguments_are_checked_before_they_run(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'I could not refund that order.',
		]);

		$pausedResponse = $this->promptUntilPaused();
		$editedDecision = Decision::edit(['order_number' => 'ORD-10001']);
		$resumedResponse = $this->resume($pausedResponse, self::USER_MESSAGE, $editedDecision);

		$this->assertSame([], ApprovableRefund::$refunded);
		$this->assertTrue($resumedResponse->toolResults->first()->failed);
		$this->assertDatabaseHas('ai_guard_events', [
			'event' => 'tool.blocked',
			'rule' => 'argument_not_from_user',
		]);
	}

	public function test_a_tool_with_return_fields_is_blocked_on_resume_by_default(): void
	{
		RefundAgent::$policies = [
			ToolPolicy::for(ApprovableRefund::class)->requiresUserIntent(['refund'])->onlyReturnFields(['status']),
		];

		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'I could not refund that order.',
		]);

		$pausedResponse = $this->promptUntilPaused();
		$resumedResponse = $this->resume($pausedResponse);

		$this->assertSame([], ApprovableRefund::$refunded);
		$this->assertTrue($resumedResponse->toolResults->first()->failed);
		$this->assertDatabaseHas('ai_guard_events', [
			'event' => 'tool.blocked',
			'rule' => 'return_fields_on_resume',
		]);
	}

	public function test_a_tool_with_return_fields_runs_on_resume_when_allowed_by_config(): void
	{
		config(['ai-guard.approvals.untrimmed_results' => 'allow']);

		RefundAgent::$policies = [
			ToolPolicy::for(ApprovableRefund::class)->requiresUserIntent(['refund'])->onlyReturnFields(['status']),
		];

		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'Your refund is done.',
		]);

		$pausedResponse = $this->promptUntilPaused();
		$this->resume($pausedResponse);

		$this->assertSame(['ORD-10012'], ApprovableRefund::$refunded);
		$this->assertStringContainsString('"warning"', $this->payloadOf('tool.allowed'));
	}

	public function test_with_the_guard_disabled_a_resumed_call_runs_unchecked(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'Your refund is done.',
		]);

		$pausedResponse = $this->promptUntilPaused();

		config(['ai-guard.enabled' => false]);

		$this->resume($pausedResponse, 'What is the status of order ORD-10012?');

		$this->assertSame(['ORD-10012'], ApprovableRefund::$refunded);
		$this->assertSame(0, DB::table('ai_guard_events')->where('event', 'tool.blocked')->count());
	}

	public function test_a_streamed_resume_is_checked_too(): void
	{
		RefundAgent::fake([
			new ToolCall('call-1', 'ApprovableRefund', ['order_number' => 'ORD-10012']),
			'I could not refund that order.',
		]);

		$pausedResponse = $this->promptUntilPaused();
		$history = [new UserMessage(self::USER_MESSAGE), ...$pausedResponse->messages->all()];
		$editedDecision = Decision::edit(['order_number' => 'ORD-10001']);
		$decisions = Decisions::from(['call-1' => $editedDecision]);

		$stream = (new RefundAgent)->withMessages($history)->stream($decisions);

		foreach ($stream as $streamEvent) {
			// Draining the stream runs the resume.
		}

		$this->assertSame([], ApprovableRefund::$refunded);
		$this->assertDatabaseHas('ai_guard_events', [
			'event' => 'tool.blocked',
			'rule' => 'argument_not_from_user',
		]);
	}

	protected function promptUntilPaused(): AgentResponse
	{
		// Ad-hoc history (even empty) makes the run resumable, which the SDK needs before it pauses.
		$response = (new RefundAgent)->withMessages([])->prompt(self::USER_MESSAGE);

		$this->assertCount(1, $response->pendingApprovals);

		return $response;
	}

	protected function resume(
		AgentResponse $pausedResponse,
		string $userMessage = self::USER_MESSAGE,
		?Decision $decision = null,
	): AgentResponse {
		$history = [new UserMessage($userMessage), ...$pausedResponse->messages->all()];
		$callDecision = $decision ?? Decision::approve();
		$decisions = Decisions::from(['call-1' => $callDecision]);

		return (new RefundAgent)->withMessages($history)->prompt($decisions);
	}

	protected function payloadOf(string $event): string
	{
		return (string) DB::table('ai_guard_events')->where('event', $event)->value('payload');
	}
}
