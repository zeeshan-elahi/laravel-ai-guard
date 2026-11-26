<?php

namespace ZeeshanElahi\AiGuard\Listeners;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use ZeeshanElahi\AiGuard\Audit\AuditLogger;
use ZeeshanElahi\AiGuard\Exceptions\ToolCallBlocked;
use ZeeshanElahi\AiGuard\GuardContext;
use ZeeshanElahi\AiGuard\Middleware\GuardTools;
use ZeeshanElahi\AiGuard\Tools\GuardedTool;

/**
 * Checks a tool call that resumes after a human approval. The SDK runs approved calls before
 * the first step, outside the guard middleware, so without this check an approved call would
 * run with no policy at all. The policy runs on the arguments actually being run, so arguments
 * a reviewer edited are checked like any others.
 *
 * Acts only on runs that TrackResumedRuns marked as resuming: the SDK turns an exception thrown
 * here into a failed tool result on the resume path, but on a normal step it would end the run.
 */
class GuardResumedToolCalls
{
	public function __construct(protected AuditLogger $audit) {}

	public function handle(InvokingTool $event): void
	{
		$context = app(GuardContext::class);
		$tool = $event->tool;

		if (! config('ai-guard.enabled', true) || ! $context->resuming($event->invocationId)) {
			return;
		}

		// Calls in later steps of the same run are wrapped by the middleware and checked there.
		// Sub-agent tools are not guarded, on resume or otherwise.
		if ($tool instanceof GuardedTool || $tool instanceof AgentTool) {
			return;
		}

		$guardMiddleware = $this->guardMiddleware($event->agent);

		if ($guardMiddleware === null) {
			return;
		}

		$name = ToolNameResolver::resolve($tool);
		$policy = $guardMiddleware->policyFor($tool);
		$payload = [
			'arguments' => $event->arguments,
			'invocation_id' => $event->invocationId,
			'after_approval' => true,
		];

		if ($policy !== null) {
			$request = new Request($event->arguments);
			$decision = $policy->evaluate($request, $context, $name);

			if (! $decision->allowed) {
				$reason = (string) $decision->reason;

				$this->block($context, $name, $decision->rule, $reason, $payload);
			}

			$hasReturnFields = ($policy->returnFields() !== null) ? true : false;
			$allowsUntrimmed = (config('ai-guard.approvals.untrimmed_results', 'block') === 'allow') ? true : false;

			if ($hasReturnFields && ! $allowsUntrimmed) {
				$reason = 'This tool may only return some of its fields, and its result cannot be trimmed after a human approval.';

				$this->block($context, $name, 'return_fields_on_resume', $reason, $payload);
			}

			if ($hasReturnFields) {
				$payload['warning'] = 'The result was not trimmed to the allowed fields, because the call resumed after a human approval.';
			}
		}

		$context->recordCall($name);

		$this->audit->record('tool.allowed', $context->agent(), null, $name, 'allowed', null, null, $payload);
	}

	/**
	 * @param  array<string, mixed>  $payload
	 */
	protected function block(GuardContext $context, string $name, ?string $rule, string $reason, array $payload): never
	{
		$this->audit->record('tool.blocked', $context->agent(), null, $name, 'blocked', $rule, $reason, $payload);

		$blockedTemplate = (string) config('ai-guard.blocked_message');
		$blockedMessage = str_replace(':reason', $reason, $blockedTemplate);

		throw new ToolCallBlocked($blockedMessage, $rule);
	}

	/**
	 * The agent's first GuardTools middleware. It is the one that wraps the agent's tools on a
	 * normal step, so its policies are the ones a resumed call must meet too.
	 */
	protected function guardMiddleware(Agent $agent): ?GuardTools
	{
		$middleware = $agent instanceof HasMiddleware ? $agent->middleware() : [];

		foreach ($middleware as $entry) {
			if ($entry instanceof GuardTools) {
				return $entry;
			}
		}

		return null;
	}
}
