<?php

namespace ZeeshanElahi\AiGuard\Exceptions;

use RuntimeException;

/**
 * Thrown to stop a tool call that resumed after a human approval when its policy blocks it.
 * The SDK turns it into a failed tool result, so the tool does not run and the model reads
 * the blocked message.
 */
class ToolCallBlocked extends RuntimeException
{
	public function __construct(string $blockedMessage, public readonly ?string $rule = null)
	{
		parent::__construct($blockedMessage);
	}
}
