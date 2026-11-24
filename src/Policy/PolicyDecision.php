<?php

namespace ZeeshanElahi\AiGuard\Policy;

class PolicyDecision
{
	private function __construct(
		public readonly bool $allowed,
		public readonly ?string $rule = null,
		public readonly ?string $reason = null,
	) {}

	public static function allow(): self
	{
		return new self(true);
	}

	public static function block(string $rule, string $reason): self
	{
		return new self(false, $rule, $reason);
	}
}
