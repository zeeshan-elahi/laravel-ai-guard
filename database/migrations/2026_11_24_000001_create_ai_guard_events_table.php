<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		Schema::create(config('ai-guard.audit.table', 'ai_guard_events'), function (Blueprint $table) {
			$table->id();
			$table->string('agent')->nullable()->index();
			$table->string('conversation_id')->nullable()->index();
			$table->string('event', 64)->index();
			$table->string('tool')->nullable()->index();
			$table->string('decision', 32)->nullable()->index();
			$table->string('rule')->nullable();
			$table->text('reason')->nullable();
			$table->json('payload')->nullable();
			$table->timestamp('created_at')->useCurrent()->index();
		});
	}

	public function down(): void
	{
		Schema::dropIfExists(config('ai-guard.audit.table', 'ai_guard_events'));
	}
};
