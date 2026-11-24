<?php

namespace ZeeshanElahi\AiGuard\Content;

use ZeeshanElahi\AiGuard\GuardContext;

/**
 * Marks text the model reads (tool results, retrieved documents) as data, inside a boundary tag
 * whose name carries a random value per invocation. Planted text cannot close the boundary,
 * because it cannot know the tag, and any copy of the tag inside the text is broken up.
 */
class UntrustedContent
{
	public static function tag(?string $boundary): string
	{
		return 'untrusted-'.($boundary ?? 'data');
	}

	public static function wrap(string $content, string $source, ?string $boundary = null): string
	{
		$boundary ??= app(GuardContext::class)->boundary();
		$tag = self::tag($boundary);

		// Defuse any copy of the boundary inside the content.
		$content = str_ireplace($tag, str_replace('-', '_', $tag), $content);
		$source = htmlspecialchars($source, ENT_QUOTES);

		return "<{$tag} source=\"{$source}\">\n{$content}\n</{$tag}>";
	}

	public static function notice(?string $boundary): string
	{
		$tag = self::tag($boundary);

		return str_replace(':tag', $tag, (string) config('ai-guard.untrusted.notice'));
	}
}
