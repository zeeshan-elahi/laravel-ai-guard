<?php

namespace ZeeshanElahi\AiGuard;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use ZeeshanElahi\AiGuard\Audit\AuditLogger;
use ZeeshanElahi\AiGuard\Console\GuardReportCommand;
use ZeeshanElahi\AiGuard\Console\RedTeamCommand;
use ZeeshanElahi\AiGuard\Listeners\GuardResumedToolCalls;
use ZeeshanElahi\AiGuard\Listeners\RecordApprovalEvents;
use ZeeshanElahi\AiGuard\Listeners\TrackResumedRuns;

class AiGuardServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		$this->mergeConfigFrom(__DIR__.'/../config/ai-guard.php', 'ai-guard');

		$this->app->scoped(GuardContext::class);
		$this->app->singleton(AuditLogger::class);
	}

	public function boot(): void
	{
		if ($this->app->runningInConsole()) {
			$this->publishes([
				__DIR__.'/../config/ai-guard.php' => config_path('ai-guard.php'),
			], 'ai-guard-config');

			$this->publishesMigrations([
				__DIR__.'/../database/migrations' => database_path('migrations'),
			], 'ai-guard-migrations');

			$this->commands([GuardReportCommand::class, RedTeamCommand::class]);
		}

		$this->loadMigrationsFrom(__DIR__.'/../database/migrations');

		Event::listen(ToolApprovalRequested::class, [RecordApprovalEvents::class, 'requested']);
		Event::listen(ToolApprovalResolved::class, [RecordApprovalEvents::class, 'resolved']);

		// StreamingAgent extends PromptingAgent, but Laravel matches listeners by class and
		// interface only, so each event is registered on its own.
		Event::listen(PromptingAgent::class, [TrackResumedRuns::class, 'handle']);
		Event::listen(StreamingAgent::class, [TrackResumedRuns::class, 'handle']);
		Event::listen(InvokingTool::class, [GuardResumedToolCalls::class, 'handle']);
	}
}
