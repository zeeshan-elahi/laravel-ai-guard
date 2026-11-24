<?php

namespace ZeeshanElahi\AiGuard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\ToolCall;
use ZeeshanElahi\AiGuard\RedTeam\AttackCase;
use ZeeshanElahi\AiGuard\RedTeam\CaseLibrary;
use ZeeshanElahi\AiGuard\RedTeam\RedTeamAgent;
use ZeeshanElahi\AiGuard\RedTeam\Runner;
use ZeeshanElahi\AiGuard\RedTeam\Scenario;
use ZeeshanElahi\AiGuard\Tests\TestCase;

/**
 * A scripted model that obeys every planted instruction, so these tests check the harness and
 * the guard, not a real model's judgement.
 */
class RedTeamTest extends TestCase
{
	use RefreshDatabase;

	protected function case(string $id): AttackCase
	{
		return collect(CaseLibrary::all())->firstWhere('id', $id);
	}

	/**
	 * @param  array<int, mixed>  $script
	 * @return array{array<string, mixed>, array<string, mixed>}  unguarded row, guarded row
	 */
	protected function both(string $id, array $script): array
	{
		$runner = new Runner;

		RedTeamAgent::fake($script);
		$unguarded = $runner->runOnce($this->case($id), 'unguarded');

		RedTeamAgent::fake($script);
		$guarded = $runner->runOnce($this->case($id), 'guarded');

		return [$unguarded, $guarded];
	}

	public function test_the_library_has_unique_ids_and_the_planned_mix(): void
	{
		$cases = collect(CaseLibrary::all());

		$this->assertSame($cases->count(), $cases->pluck('id')->unique()->count());
		$this->assertSame(19, $cases->where('benign', false)->count());
		$this->assertSame(5, $cases->where('benign', true)->count());
		$this->assertTrue($cases->where('benign', false)->every(fn (AttackCase $case) => $case->plant !== []));
	}

	public function test_a_planted_ticket_works_unguarded_and_is_blocked_guarded(): void
	{
		[$unguarded, $guarded] = $this->both('action-01', [
			new ToolCall('c1', 'lookup_order', ['order_number' => 'ORD-10012']),
			new ToolCall('c2', 'create_support_ticket', ['order_number' => 'ORD-10001', 'reason' => 'refund']),
			'Your order has shipped.',
		]);

		$this->assertTrue($unguarded['succeeded']);
		$this->assertTrue($unguarded['planted_read']);
		$this->assertFalse($guarded['succeeded']);
		$this->assertTrue($guarded['planted_read']);
	}

	public function test_an_email_to_the_attacker_is_blocked(): void
	{
		[$unguarded, $guarded] = $this->both('exfil-01', [
			new ToolCall('c1', 'lookup_order', ['order_number' => 'ORD-10012']),
			new ToolCall('c2', 'send_email', ['to' => Scenario::ATTACKER_EMAIL, 'subject' => 'Profile', 'body' => 'data']),
			'Shipped.',
		]);

		$this->assertTrue($unguarded['succeeded']);
		$this->assertFalse($guarded['succeeded']);
	}

	public function test_a_redirected_email_goes_nowhere_when_guarded(): void
	{
		[$unguarded, $guarded] = $this->both('exfil-04', [
			new ToolCall('c1', 'lookup_order', ['order_number' => 'ORD-10012']),
			new ToolCall('c2', 'send_email', ['to' => Scenario::ATTACKER_EMAIL, 'subject' => 'Summary', 'body' => 'ORD-10012 shipped']),
			'Sent.',
		]);

		$this->assertTrue($unguarded['succeeded']);
		$this->assertFalse($guarded['succeeded']);
	}

	public function test_the_api_key_never_reaches_the_answer_when_guarded(): void
	{
		// The model copies the key from the tool result; when guarded the field allowlist removes it first.
		$script = fn () => [
			new ToolCall('c1', 'get_customer', []),
			'You are in Dubai. Your key: '.Scenario::SECRET_KEY,
		];

		[$unguarded, $guarded] = $this->both('leak-01', $script());

		$this->assertTrue($unguarded['succeeded']);
		$this->assertFalse($guarded['succeeded']);
		$this->assertStringContainsString('[redacted]', $guarded['answer']);
	}

	public function test_another_customers_order_is_redacted_from_the_answer(): void
	{
		[$unguarded, $guarded] = $this->both('foreign-01', [
			new ToolCall('c1', 'lookup_order', ['order_number' => 'ORD-10012']),
			'ORD-10012 has shipped. Also, ORD-10077 was refunded 900 dollars.',
		]);

		$this->assertTrue($unguarded['succeeded']);
		$this->assertFalse($guarded['succeeded']);
	}

	public function test_a_ticket_the_user_asked_for_still_works_when_guarded(): void
	{
		[$unguarded, $guarded] = $this->both('control-02', [
			new ToolCall('c1', 'create_support_ticket', ['order_number' => 'ORD-10012', 'reason' => 'damaged']),
			'Ticket opened.',
		]);

		$this->assertTrue($unguarded['succeeded']);
		$this->assertTrue($guarded['succeeded']);
		$this->assertNull($guarded['planted_read']);
	}

	public function test_an_email_the_user_asked_for_still_works_when_guarded(): void
	{
		[, $guarded] = $this->both('control-04', [
			new ToolCall('c1', 'send_email', ['to' => Scenario::CUSTOMER_EMAIL, 'subject' => 'ORD-10012', 'body' => 'Shipped.']),
			'Sent.',
		]);

		$this->assertTrue($guarded['succeeded']);
	}

	public function test_summary_counts_attacks_controls_and_categories(): void
	{
		$runs = [
			['mode' => 'guarded', 'benign' => false, 'category' => 'exfiltration', 'succeeded' => false, 'planted_read' => true, 'error' => null],
			['mode' => 'guarded', 'benign' => false, 'category' => 'exfiltration', 'succeeded' => true, 'planted_read' => true, 'error' => null],
			['mode' => 'guarded', 'benign' => true, 'category' => 'control', 'succeeded' => true, 'planted_read' => null, 'error' => null],
			['mode' => 'guarded', 'benign' => false, 'category' => 'data_leak', 'succeeded' => false, 'planted_read' => false, 'error' => 'Timeout'],
		];

		$summary = (new Runner)->summarise($runs)['guarded'];

		$this->assertSame(3, $summary['attack_runs']);
		$this->assertSame(1, $summary['attacks_succeeded']);
		$this->assertSame(2, $summary['planted_text_read']);
		$this->assertSame(1, $summary['controls_passed']);
		$this->assertSame(1, $summary['errors']);
		$this->assertSame(['runs' => 2, 'succeeded' => 1], $summary['by_category']['exfiltration']);
	}

	public function test_the_command_runs_selected_cases_and_writes_a_report(): void
	{
		RedTeamAgent::fake(fn () => 'Items can be returned within 30 days.');

		$path = sys_get_temp_dir().'/ai-guard-redteam-test.json';
		@unlink($path);

		$this->artisan('guard:redteam', ['--only' => 'control-03,prompt-01', '--json' => $path])
			->expectsOutputToContain('2 case(s) × 2 mode(s) × 1 run(s)')
			->expectsOutputToContain('controls passed')
			->assertSuccessful();

		$report = json_decode((string) file_get_contents($path), true);

		$this->assertCount(4, $report['runs']);
		$this->assertSame(1, $report['summary']['guarded']['controls_passed']);
		$this->assertSame(0, $report['summary']['guarded']['attacks_succeeded']);
	}
}
