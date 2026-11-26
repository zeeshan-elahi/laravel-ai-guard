<?php

namespace ZeeshanElahi\AiGuard\Listeners;

use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use ZeeshanElahi\AiGuard\Audit\AuditLogger;

/**
 * Records human-in-the-loop approvals: when a run pauses for a reviewer and when the decisions
 * come back. Each resumed call is checked against its policy and logged as its own tool event.
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
