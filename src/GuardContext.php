<?php

namespace ZeeshanElahi\AiGuard;

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\PendingStep;

/**
 * State for one agent invocation: the user's own message and how often each tool has run.
 *
 * Bound as a scoped singleton, so it resets between requests and queued jobs. The middleware
 * also resets it whenever a new invocation starts.
 */
class GuardContext
{
	protected ?string $invocationId = null;

	protected ?string $userMessage = null;

	protected ?string $agent = null;

	protected ?string $boundary = null;

	protected ?string $syncKey = null;

	/** @var array<string, int> */
	protected array $toolCalls = [];

	public function begin(?string $invocationId, ?string $userMessage, ?string $agent = null): void
	{
		$this->invocationId = $invocationId;
		$this->userMessage = $userMessage;
		$this->agent = $agent;
		$this->toolCalls = [];
		$this->boundary = bin2hex(random_bytes(6));
	}

	/**
	 * Start a new invocation when this step belongs to one we have not seen. Safe to call from
	 * several guard middlewares on the same step: only the first call starts the invocation.
	 */
	public function sync(PendingStep $step, ?string $agent = null): void
	{
		// laravel/ai gives every prompt an invocation id. Without one (a hand-built step), the
		// first step's messages identify the run, so a second guard middleware on the same
		// step does not restart it and change the boundary tag.
		$messages = $step->messages;
		$last = end($messages);
		$key = $step->invocationId
			?? ($step->isFirstStep() ? 'step:'.count($messages).':'.(is_object($last) ? spl_object_id($last) : md5(serialize($last))) : $this->syncKey);

		if ($key !== $this->syncKey) {
			$this->begin($step->invocationId, self::latestUserMessage($messages), $agent);
			$this->syncKey = $key;
		} elseif ($agent !== null && $this->agent === null) {
			$this->agent = $agent;
		}
	}

	/**
	 * The newest message written by the user. Tool results are a separate role, so text the
	 * model read from a document or a tool can never stand in for the user's request.
	 *
	 * @param  array<int, mixed>  $messages
	 */
	public static function latestUserMessage(array $messages): ?string
	{
		foreach (array_reverse($messages) as $message) {
			$message = $message instanceof Message ? $message : Message::tryFrom($message);

			if ($message->role === MessageRole::User) {
				return $message->content;
			}
		}

		return null;
	}

	/**
	 * Set the user's message explicitly, for agents that are run without the guard middleware.
	 */
	public function setUserMessage(?string $userMessage): void
	{
		$this->userMessage = $userMessage;
	}

	public function invocationId(): ?string
	{
		return $this->invocationId;
	}

	public function userMessage(): ?string
	{
		return $this->userMessage;
	}

	/**
	 * Random per invocation; names the tag that marks untrusted content.
	 */
	public function boundary(): string
	{
		return $this->boundary ??= bin2hex(random_bytes(6));
	}

	public function agent(): ?string
	{
		return $this->agent;
	}

	public function callsFor(string $tool): int
	{
		return $this->toolCalls[$tool] ?? 0;
	}

	public function recordCall(string $tool): void
	{
		$this->toolCalls[$tool] = $this->callsFor($tool) + 1;
	}
}
