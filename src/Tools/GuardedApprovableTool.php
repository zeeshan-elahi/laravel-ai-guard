<?php

namespace ZeeshanElahi\AiGuard\Tools;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

/**
 * A guarded tool whose inner tool asks for human approval. Approval settings pass straight through,
 * except that a call the policy blocks is never sent to a reviewer.
 */
class GuardedApprovableTool extends GuardedTool implements Approvable
{
	public function requireApproval(?string $reason = null): static
	{
		$this->approvable()->requireApproval($reason);

		return $this;
	}

	public function withoutApproval(): static
	{
		$this->approvable()->withoutApproval();

		return $this;
	}

	public function shouldRequestApproval(Request $request): ?Approval
	{
		// A blocked call needs no reviewer: with no approval requested, the SDK calls handle(),
		// which returns the blocked message and writes the single tool.blocked audit row.
		if (config('ai-guard.enabled', true) && $this->policy !== null) {
			$decision = $this->policy->evaluate($request, $this->context, $this->name());

			if (! $decision->allowed) {
				return null;
			}
		}

		return $this->approvable()->shouldRequestApproval($request);
	}

	protected function approvable(): Approvable
	{
		/** @var Approvable $inner */
		$inner = $this->inner;

		return $inner;
	}
}
