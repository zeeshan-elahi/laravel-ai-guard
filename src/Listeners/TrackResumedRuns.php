<?php

namespace ZeeshanElahi\AiGuard\Listeners;

use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Events\PromptingAgent;
use ZeeshanElahi\AiGuard\GuardContext;

/**
 * Recognises a run that resumes after a human approval. The SDK runs the approved calls before
 * the first step, so the guard middleware never sees them; this listener starts the run first,
 * with the user's original message, so they can be checked.
 */
class TrackResumedRuns
{
	public function handle(PromptingAgent $event): void
	{
		$prompt = $event->prompt;

		if (! $prompt->hasApprovalDecisions()) {
			return;
		}

		$agent = $prompt->agent;
		$adHocMessages = $prompt->messages ?? [];
		$conversationMessages = $agent instanceof Conversational ? $agent->messages() : [];

		// Same order the SDK uses to build the history it resumes from. A resume adds no new user
		// message, so the newest one in the history is the message that asked for the action.
		$history = [...$adHocMessages, ...$conversationMessages];
		$userMessage = GuardContext::latestUserMessage($history);
		$agentClass = $agent::class;

		app(GuardContext::class)->beginResume($event->invocationId, $userMessage, $agentClass);
	}
}
