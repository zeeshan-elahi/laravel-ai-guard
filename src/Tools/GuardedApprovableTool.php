<?php

namespace ZeeshanElahi\AiGuard\Tools;

use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

/**
 * A guarded tool whose inner tool asks for human approval. Approval settings pass straight through.
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
		return $this->approvable()->shouldRequestApproval($request);
	}

	protected function approvable(): Approvable
	{
		/** @var Approvable $inner */
		$inner = $this->inner;

		return $inner;
	}
}
