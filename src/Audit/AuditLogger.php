<?php

namespace ZeeshanElahi\AiGuard\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes guard events to the audit table. Never throws: a logging failure is reported
 * and the agent run carries on.
 */
class AuditLogger
{
	public const MAX_PAYLOAD_CHARS = 2000;

	/**
	 * @param  array<string, mixed>  $payload
	 */
	public function record(
		string $event,
		?string $agent = null,
		?string $conversationId = null,
		?string $tool = null,
		?string $decision = null,
		?string $rule = null,
		?string $reason = null,
		array $payload = [],
	): void {
		if (! config('ai-guard.audit.enabled', true)) {
			return;
		}

		try {
			DB::table(config('ai-guard.audit.table', 'ai_guard_events'))->insert([
				'agent' => $agent,
				'conversation_id' => $conversationId,
				'event' => $event,
				'tool' => $tool,
				'decision' => $decision,
				'rule' => $rule,
				'reason' => $reason !== null ? Str::limit($reason, 1000) : null,
				'payload' => $payload === [] ? null : $this->encode($payload),
				'created_at' => now(),
			]);
		} catch (Throwable $exception) {
			report($exception);
		}
	}

	/**
	 * @param  array<string, mixed>  $payload
	 */
	protected function encode(array $payload): string
	{
		if (! config('ai-guard.audit.store_arguments', true)) {
			unset($payload['arguments']);
		}

		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

		if (mb_strlen((string) $json) <= self::MAX_PAYLOAD_CHARS) {
			return (string) $json;
		}

		return (string) json_encode(['truncated' => mb_substr((string) $json, 0, self::MAX_PAYLOAD_CHARS)], JSON_UNESCAPED_UNICODE);
	}
}
