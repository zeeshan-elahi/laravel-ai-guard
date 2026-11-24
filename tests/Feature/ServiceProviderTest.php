<?php

namespace ZeeshanElahi\AiGuard\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ZeeshanElahi\AiGuard\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
	use RefreshDatabase;

	public function test_config_is_merged_with_defaults(): void
	{
		$this->assertTrue(config('ai-guard.enabled'));
		$this->assertTrue(config('ai-guard.audit.enabled'));
		$this->assertSame('ai_guard_events', config('ai-guard.audit.table'));
	}

	public function test_audit_table_is_migrated(): void
	{
		$this->assertTrue(Schema::hasTable('ai_guard_events'));
		$this->assertTrue(Schema::hasColumns('ai_guard_events', [
			'agent', 'conversation_id', 'event', 'tool', 'decision', 'rule', 'reason', 'payload', 'created_at',
		]));
	}

	public function test_laravel_ai_is_installed(): void
	{
		$this->assertTrue(interface_exists(\Laravel\Ai\Contracts\Tool::class));
	}
}
