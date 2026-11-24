<?php

namespace ZeeshanElahi\AiGuard\Listeners;

use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use ZeeshanElahi\AiGuard\Audit\AuditLogger;

/**
 * Records human-in-the-loop approvals. Calls resumed after approval run outside the guard
 * middleware, so the log keeps a trace of who allowed what.
 */
class RecordApprovalEvents
{
	public function __construct(protected AuditLogger $audit) {}

	public function requested(ToolApprovalRequested $event): void
	{
		$this->audit->record(
			'approval.requested',
			$event->agent::class,
			$event->conversationId,
			payload: ['invocation_id' => $event->invocationId, 'pending' => $event->pendingApprovals->count()],
		);
	}

	public function resolved(ToolApprovalResolved $event): void
	{
		$this->audit->record(
			'approval.resolved',
			$event->agent::class,
			$event->conversationId,
			payload: ['invocation_id' => $event->invocationId, 'results' => $event->toolResults->count()],
		);
	}
}
