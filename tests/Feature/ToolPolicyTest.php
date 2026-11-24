<?php

namespace ZeeshanElahi\AiGuard\Tests\Feature;

use Laravel\Ai\Tools\Request;
use ZeeshanElahi\AiGuard\GuardContext;
use ZeeshanElahi\AiGuard\Policy\ToolPolicy;
use ZeeshanElahi\AiGuard\Tests\TestCase;

class ToolPolicyTest extends TestCase
{
	protected function context(string $userMessage): GuardContext
	{
		$context = new GuardContext;
		$context->begin('inv-1', $userMessage);

		return $context;
	}

	public function test_argument_values_match_whole_tokens_only(): void
	{
		$policy = ToolPolicy::for('Refund')->argumentsFromUserMessage(['id']);

		$this->assertFalse($policy->evaluate(new Request(['id' => '12']), $this->context('my order from 2012'))->allowed);
		$this->assertFalse($policy->evaluate(new Request(['id' => '12']), $this->context('order ORD-120'))->allowed);
		$this->assertTrue($policy->evaluate(new Request(['id' => '12']), $this->context('refund order 12 please'))->allowed);
		$this->assertTrue($policy->evaluate(new Request(['id' => 'ORD-10012']), $this->context('refund ord-10012'))->allowed);
	}

	public function test_every_value_in_an_array_argument_must_come_from_the_user(): void
	{
		$policy = ToolPolicy::for('Refund')->argumentsFromUserMessage(['ids']);

		$this->assertTrue($policy->evaluate(new Request(['ids' => ['A1', 'B2']]), $this->context('refund A1 and B2'))->allowed);
		$this->assertFalse($policy->evaluate(new Request(['ids' => ['A1', 'C3']]), $this->context('refund A1 and B2'))->allowed);
	}

	public function test_intent_keywords_match_whole_words(): void
	{
		$policy = ToolPolicy::for('Refund')->requiresUserIntent(['refund']);

		$this->assertTrue($policy->evaluate(new Request, $this->context('I want a Refund.'))->allowed);
		$this->assertFalse($policy->evaluate(new Request, $this->context('the refunding policy, just asking'))->allowed);
	}

	public function test_no_user_message_means_intent_and_argument_rules_block(): void
	{
		$context = new GuardContext;

		$this->assertFalse(ToolPolicy::for('Refund')->requiresUserIntent(['refund'])->evaluate(new Request, $context)->allowed);
		$this->assertFalse(ToolPolicy::for('Refund')->argumentsFromUserMessage(['id'])->evaluate(new Request(['id' => 'A1']), $context)->allowed);
	}

	public function test_a_policy_matches_by_tool_name_or_class(): void
	{
		$this->assertTrue(ToolPolicy::for('App\\Ai\\Tools\\CreateTicket')->matches('CreateTicket', 'App\\Ai\\Tools\\CreateTicket'));
		$this->assertTrue(ToolPolicy::for('create_ticket')->matches('create_ticket', 'App\\Ai\\Tools\\CreateTicket'));
		$this->assertFalse(ToolPolicy::for('Other')->matches('CreateTicket', 'App\\Ai\\Tools\\CreateTicket'));
	}
}
