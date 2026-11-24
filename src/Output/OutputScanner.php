<?php

namespace ZeeshanElahi\AiGuard\Output;

use Closure;
use ZeeshanElahi\AiGuard\GuardContext;

/**
 * Finds what must not leave the app in model output: copies of the instructions, secrets,
 * and identifiers that do not belong to the current user. Deterministic pattern checks only.
 */
class OutputScanner
{
	/**
	 * @param  array<string, Closure|array<int, string>|null>  $identifiers  regex => allowed values (or a closure returning them)
	 * @param  array<int, string>  $protectedTerms  exact phrases that must never appear in output
	 */
	public function __construct(
		protected array $identifiers = [],
		protected array $protectedTerms = [],
	) {}

	/**
	 * Does the text repeat a run of the instructions long enough to count as a leak?
	 */
	public function leaksInstructions(string $text, ?string $instructions): bool
	{
		$window = max(3, (int) config('ai-guard.output.instructions_min_words', 10));
		$source = $this->words((string) $instructions);
		$output = $this->words($text);

		if (count($source) < $window || count($output) < $window) {
			return false;
		}

		$grams = [];

		for ($i = 0; $i + $window <= count($source); $i++) {
			$grams[implode(' ', array_slice($source, $i, $window))] = true;
		}

		for ($i = 0; $i + $window <= count($output); $i++) {
			if (isset($grams[implode(' ', array_slice($output, $i, $window))])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Replace secrets with the redaction marker.
	 *
	 * @return array{string, array<int, string>}  the redacted text and the names of the rules that matched
	 */
	public function redactSecrets(string $text): array
	{
		$hits = [];
		$marker = (string) config('ai-guard.output.redaction', '[redacted]');

		foreach ((array) config('ai-guard.output.secret_patterns', []) as $name => $pattern) {
			$text = (string) preg_replace_callback($pattern, function () use (&$hits, $name, $marker) {
				$hits[] = (string) $name;

				return $marker;
			}, $text);
		}

		foreach ($this->secretValues() as $value) {
			if (str_contains($text, $value)) {
				$hits[] = 'configured_value';
				$text = str_replace($value, $marker, $text);
			}
		}

		return [$text, array_values(array_unique($hits))];
	}

	/**
	 * Replace identifiers that match a configured pattern but are not on the current user's allowed list.
	 *
	 * @return array{string, int}  the redacted text and how many identifiers were removed
	 */
	public function redactForeignIdentifiers(string $text, GuardContext $context): array
	{
		$count = 0;
		$marker = (string) config('ai-guard.output.redaction', '[redacted]');

		foreach ($this->identifiers as $pattern => $allowed) {
			$allowedValues = $this->allowedValues($pattern, $allowed, $context);

			$text = (string) preg_replace_callback($pattern, function (array $match) use ($allowedValues, &$count, $marker) {
				if (in_array(mb_strtolower($match[0]), $allowedValues, true)) {
					return $match[0];
				}

				$count++;

				return $marker;
			}, $text);
		}

		return [$text, $count];
	}

	/**
	 * Remove links and images that point outside the allowed domains. A planted instruction can
	 * ask for an image whose URL carries user data; the browser would send it on render.
	 *
	 * @return array{string, int}  the cleaned text and how many links were removed
	 */
	public function redactLinks(string $text): array
	{
		if (! config('ai-guard.output.links.enabled', true)) {
			return [$text, 0];
		}

		$count = 0;
		$allowed = $this->allowedHosts();
		$isForeign = function (string $url) use ($allowed): bool {
			$host = strtolower((string) parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url, PHP_URL_HOST));

			foreach ($allowed as $domain) {
				if ($host === $domain || str_ends_with($host, '.'.$domain)) {
					return false;
				}
			}

			return true;
		};
		$url = '((?:https?:)?\/\/[^\s)<>"\']+)';
		$replace = function (string $pattern, int $group, Closure $replacement) use (&$text, &$count, $isForeign): void {
			$text = (string) preg_replace_callback($pattern, function (array $match) use ($group, $replacement, &$count, $isForeign): string {
				if (! $isForeign($match[$group])) {
					return $match[0];
				}

				$count++;

				return $replacement($match);
			}, $text);
		};

		// Markdown images and HTML images: removed entirely.
		$replace('/!\[[^\]]*\]\(\s*<?'.$url.'>?[^)]*\)/i', 1, fn () => '');
		$replace('/<img\b[^>]*\bsrc\s*=\s*["\']?'.$url.'[^>]*>/i', 1, fn () => '');

		// Markdown links: keep the link text, drop the URL.
		$replace('/\[([^\]]*)\]\(\s*<?'.$url.'>?[^)]*\)/i', 2, fn (array $match) => $match[1]);

		// Bare URLs.
		$replace('/(?<![\w\/])https?:\/\/[^\s<>()"\'\]]+/i', 0, fn () => '[link removed]');

		return [$text, $count];
	}

	/**
	 * Redact protected terms: the configured list plus code-like tokens from the instructions
	 * (letters and digits mixed, 6+ characters), which a 10-word leak check would miss.
	 *
	 * @return array{string, int}  the redacted text and how many terms were removed
	 */
	public function redactProtectedTerms(string $text, ?string $instructions): array
	{
		$count = 0;
		$marker = (string) config('ai-guard.output.redaction', '[redacted]');

		foreach ($this->protectedTermsFor($instructions) as $term) {
			$text = (string) preg_replace_callback('/'.preg_quote($term, '/').'/iu', function () use (&$count, $marker) {
				$count++;

				return $marker;
			}, $text);
		}

		return [$text, $count];
	}

	/**
	 * @return array<int, string>
	 */
	public function protectedTermsFor(?string $instructions): array
	{
		$terms = [...(array) config('ai-guard.output.protected_terms', []), ...$this->protectedTerms];

		if (config('ai-guard.output.protect_instruction_codes', true) && $instructions !== null) {
			preg_match_all('/(?<![\p{L}\p{N}])(?=[\p{L}\p{N}_-]*\p{N})(?=[\p{L}\p{N}_-]*\p{L})[\p{L}\p{N}][\p{L}\p{N}_-]{5,}(?![\p{L}\p{N}])/u', $instructions, $codes);
			$terms = [...$terms, ...$codes[0]];
		}

		$terms = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $terms)), fn ($term) => mb_strlen($term) >= 4)));

		// Longest first, so a term that contains another is redacted whole.
		usort($terms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

		return $terms;
	}

	/**
	 * @return array<int, string>
	 */
	protected function allowedHosts(): array
	{
		$hosts = array_map(fn ($host) => strtolower(trim((string) $host)), (array) config('ai-guard.output.links.allowed_domains', []));
		$appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

		if (is_string($appHost) && $appHost !== '') {
			$hosts[] = strtolower($appHost);
		}

		return array_values(array_filter(array_unique($hosts)));
	}

	/**
	 * Values the user typed themselves, plus whatever the app says belongs to them.
	 *
	 * @return array<int, string>
	 */
	protected function allowedValues(string $pattern, Closure|array|null $allowed, GuardContext $context): array
	{
		preg_match_all($pattern, (string) $context->userMessage(), $typed);

		$fromApp = $allowed instanceof Closure ? (array) $allowed($context) : (array) $allowed;

		return array_map(fn ($value) => mb_strtolower((string) $value), [...$typed[0], ...$fromApp]);
	}

	/**
	 * Exact values that must never appear in output: the app key plus any configured secrets.
	 *
	 * @return array<int, string>
	 */
	protected function secretValues(): array
	{
		$values = [(string) config('app.key'), ...array_map('strval', (array) config('ai-guard.output.secret_values', []))];

		// Short values would redact ordinary words.
		return array_values(array_filter(array_unique($values), fn ($value) => mb_strlen($value) >= 12));
	}

	/**
	 * @return array<int, string>
	 */
	protected function words(string $text): array
	{
		preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

		return $matches[0];
	}
}
