<?php

namespace ZeeshanElahi\AiGuard\Policy;

use Closure;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Tools\Request;
use ZeeshanElahi\AiGuard\GuardContext;

/**
 * Deterministic rules for one tool. Every rule is checked in code before the tool runs;
 * none of them asks the model for its opinion.
 */
class ToolPolicy
{
	protected bool $denied = false;

	protected ?string $denyReason = null;

	/** @var array<int, string> */
	protected array $intentKeywords = [];

	/** @var array<int, string> */
	protected array $argumentsFromUser = [];

	protected ?int $maxCalls = null;

	/** @var array<string, mixed> */
	protected array $rules = [];

	/** @var array<int, array{Closure, string}> */
	protected array $conditions = [];

	/** @var array<int, string>|null */
	protected ?array $returnFields = null;

	/**
	 * @param  string  $tool  the tool's class basename or the name the model sees
	 */
	public function __construct(public readonly string $tool) {}

	public function matches(string $toolName, string $toolClass): bool
	{
		return $this->tool === $toolName || $this->tool === class_basename($toolClass) || $this->tool === $toolClass;
	}

	/**
	 * Start a policy for a tool, by class name or by the tool's name as the model sees it.
	 */
	public static function for(string $tool): self
	{
		return new self($tool);
	}

	/**
	 * Never run this tool.
	 */
	public function deny(string $reason = 'This tool is disabled.'): self
	{
		$this->denied = true;
		$this->denyReason = $reason;

		return $this;
	}

	/**
	 * Run only when the user's own message contains at least one of these words or phrases.
	 * Text inside documents and tool results does not count.
	 *
	 * @param  array<int, string>  $keywords
	 */
	public function requiresUserIntent(array $keywords): self
	{
		$this->intentKeywords = array_values(array_filter(array_map('trim', $keywords), 'strlen'));

		return $this;
	}

	/**
	 * Each named argument must appear, as typed, in the user's own message. Stops a planted
	 * instruction from pointing the tool at a record the user never mentioned.
	 *
	 * @param  array<int, string>  $arguments
	 */
	public function argumentsFromUserMessage(array $arguments): self
	{
		$this->argumentsFromUser = array_values($arguments);

		return $this;
	}

	/**
	 * Allow at most this many runs of the tool per agent invocation.
	 */
	public function maxCallsPerRun(int $max): self
	{
		$this->maxCalls = max(0, $max);

		return $this;
	}

	/**
	 * Laravel validation rules for the tool's arguments, on top of the JSON schema.
	 *
	 * @param  array<string, mixed>  $rules
	 */
	public function validate(array $rules): self
	{
		$this->rules = $rules;

		return $this;
	}

	/**
	 * A custom rule: the callback receives the tool request and the guard context and returns true to allow.
	 */
	public function when(Closure $condition, string $reason): self
	{
		$this->conditions[] = [$condition, $reason];

		return $this;
	}

	/**
	 * Only these fields of the tool's JSON result reach the model. Dot paths; a path into a
	 * list applies to every item. A result that is not JSON is withheld.
	 *
	 * @param  array<int, string>  $fields
	 */
	public function onlyReturnFields(array $fields): self
	{
		$this->returnFields = array_values($fields);

		return $this;
	}

	/**
	 * @return array<int, string>|null
	 */
	public function returnFields(): ?array
	{
		return $this->returnFields;
	}

	/**
	 * @param  string|null  $toolName  the name the model calls the tool by; used for the per-run call count
	 */
	public function evaluate(Request $request, GuardContext $context, ?string $toolName = null): PolicyDecision
	{
		if ($this->denied) {
			return PolicyDecision::block('deny', $this->denyReason ?? 'This tool is disabled.');
		}

		$userMessage = (string) $context->userMessage();

		if ($this->intentKeywords !== [] && ! $this->mentionsAny($userMessage, $this->intentKeywords)) {
			return PolicyDecision::block('user_intent', 'The user did not ask for this action in their own message.');
		}

		foreach ($this->argumentsFromUser as $argument) {
			foreach ($this->scalarValues($request->all()[$argument] ?? null) as $value) {
				if (! $this->contains($userMessage, $value)) {
					return PolicyDecision::block(
						'argument_not_from_user',
						"The value given for '{$argument}' does not appear in the user's own message.",
					);
				}
			}
		}

		if ($this->maxCalls !== null && $context->callsFor($toolName ?? $this->tool) >= $this->maxCalls) {
			return PolicyDecision::block('max_calls', "This tool may run at most {$this->maxCalls} time(s) per request.");
		}

		if ($this->rules !== []) {
			$validator = Validator::make($request->all(), $this->rules);

			if ($validator->fails()) {
				return PolicyDecision::block('validation', implode(' ', $validator->errors()->all()));
			}
		}

		foreach ($this->conditions as [$condition, $reason]) {
			if ($condition($request, $context) !== true) {
				return PolicyDecision::block('condition', $reason);
			}
		}

		return PolicyDecision::allow();
	}

	/**
	 * @param  array<int, string>  $needles
	 */
	protected function mentionsAny(string $haystack, array $needles): bool
	{
		foreach ($needles as $needle) {
			$pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/iu';

			if (preg_match($pattern, $haystack) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whole-token match, so an ID of "12" does not match "2012" or "ORD-120".
	 */
	protected function contains(string $haystack, string $needle): bool
	{
		$needle = $this->normalise($needle);

		if ($needle === '') {
			return false;
		}

		$pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u';

		return preg_match($pattern, $this->normalise($haystack)) === 1;
	}

	protected function normalise(string $value): string
	{
		return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
	}

	/**
	 * @return array<int, string>
	 */
	protected function scalarValues(mixed $value): array
	{
		if (is_array($value)) {
			return array_merge(...array_map(fn ($item) => $this->scalarValues($item), array_values($value)) ?: [[]]);
		}

		if (is_string($value) || is_int($value) || is_float($value)) {
			return trim((string) $value) === '' ? [] : [(string) $value];
		}

		return [];
	}
}
