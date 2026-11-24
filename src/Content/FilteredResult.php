<?php

namespace ZeeshanElahi\AiGuard\Content;

class FilteredResult
{
	/**
	 * @param  array<string, string>  $removed
	 */
	private function __construct(
		public readonly bool $structured,
		public readonly ?string $content = null,
		public readonly array $removed = [],
	) {}

	/**
	 * @param  array<string, string>  $removed
	 */
	public static function structured(string $content, array $removed): self
	{
		return new self(true, $content, array_values($removed));
	}

	public static function unstructured(): self
	{
		return new self(false);
	}
}
