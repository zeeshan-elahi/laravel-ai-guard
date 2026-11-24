<?php

namespace ZeeshanElahi\AiGuard\Middleware;

use Closure;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\PendingStep;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\ToolNameResolver;
use ZeeshanElahi\AiGuard\Audit\AuditLogger;
use ZeeshanElahi\AiGuard\Content\UntrustedContent;
use ZeeshanElahi\AiGuard\GuardContext;
use ZeeshanElahi\AiGuard\Policy\ToolPolicy;
use ZeeshanElahi\AiGuard\Tools\GuardedApprovableTool;
use ZeeshanElahi\AiGuard\Tools\GuardedTool;

/**
 * Agent middleware: remembers the user's own message for this invocation and wraps every
 * tool in the step so its policy runs in code before the tool does.
 *
 * Add it to an agent that implements Laravel\Ai\Contracts\HasMiddleware:
 *
 *     public function middleware(): array
 *     {
 *         return [
 *             new GuardTools([
 *                 ToolPolicy::for(CreateSupportTicket::class)->requiresUserIntent(['ticket', 'refund']),
 *             ]),
 *         ];
 *     }
 */
class GuardTools
{
	/**
	 * @param  array<int, ToolPolicy>  $policies
	 * @param  string|null  $agent  a label for the audit log, e.g. static::class
	 */
	public function __construct(
		protected array $policies = [],
		protected ?string $agent = null,
	) {}

	public function handle(PendingStep $step, Closure $next): mixed
	{
		$context = app(GuardContext::class);

		$context->sync($step, $this->agent);

		$step = $step->withTools(array_map(fn ($tool) => $this->guard($tool, $context), $step->tools));

		if ($step->tools !== [] && config('ai-guard.enabled', true) && config('ai-guard.untrusted.enabled', true)) {
			$step = $step->withInstructions(trim(($step->instructions ?? '')."\n\n".UntrustedContent::notice($context->boundary())));
		}

		return $next($step);
	}

	protected function guard(mixed $tool, GuardContext $context): mixed
	{
		// Provider-hosted tools never run in this app, and sub-agent tools have their own streaming path.
		if (! $tool instanceof Tool || $tool instanceof AgentTool || $tool instanceof GuardedTool) {
			return $tool;
		}

		$name = ToolNameResolver::resolve($tool);
		$policy = collect($this->policies)->first(fn (ToolPolicy $policy) => $policy->matches($name, $tool::class));
		$audit = app(AuditLogger::class);

		return $tool instanceof Approvable
			? new GuardedApprovableTool($tool, $policy, $context, $audit)
			: new GuardedTool($tool, $policy, $context, $audit);
	}
}
