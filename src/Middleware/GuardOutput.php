<?php

namespace ZeeshanElahi\AiGuard\Middleware;

use Closure;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;
use ZeeshanElahi\AiGuard\Audit\AuditLogger;
use ZeeshanElahi\AiGuard\GuardContext;
use ZeeshanElahi\AiGuard\Output\OutputScanner;

/**
 * Agent middleware: checks each model response before the app uses it. A copy of the
 * instructions replaces the whole answer; secrets, protected terms, other users' identifiers and
 * links outside the allowed domains are removed;
 * tool-call arguments are cleaned before any tool runs.
 *
 *     public function middleware(): array
 *     {
 *         return [
 *             new GuardOutput(identifiers: [
 *                 '/ORD-\d{5}/' => fn () => auth()->user()->orders()->pluck('number')->all(),
 *             ]),
 *         ];
 *     }
 *
 * With stream(), text already sent to the client cannot be taken back: the final stored
 * answer and tool-call arguments are still checked.
 */
class GuardOutput
{
	/**
	 * @param  array<string, Closure|array<int, string>|null>  $identifiers  regex => values the current user may see
	 * @param  array<int, string>  $protectedTerms  exact phrases that must never appear in an answer
	 */
	public function __construct(
		protected array $identifiers = [],
		protected ?string $agent = null,
		protected array $protectedTerms = [],
	) {}

	public function handle(PendingStep $step, Closure $next): mixed
	{
		$context = app(GuardContext::class);
		$context->sync($step, $this->agent);

		$result = $next($step);

		if (! config('ai-guard.enabled', true) || ! config('ai-guard.output.enabled', true)) {
			return $result;
		}

		$check = fn (StepResponse $response) => $this->check($response, $step->instructions, $context);

		if ($result instanceof StepResponse) {
			$check($result);
		} elseif ($result instanceof StepResult) {
			$result->then($check);
		}

		return $result;
	}

	protected function check(StepResponse $response, ?string $instructions, GuardContext $context): void
	{
		$scanner = new OutputScanner($this->identifiers, $this->protectedTerms);
		$audit = app(AuditLogger::class);

		if ($response->text !== '') {
			if ($scanner->leaksInstructions($response->text, $instructions)) {
				$response->text = (string) config('ai-guard.output.leak_message');
				$audit->record('output.blocked', $context->agent(), null, null, 'blocked', 'instructions_leak', 'The answer repeated the agent instructions.');
			} else {
				[$response->text, $secrets] = $scanner->redactSecrets($response->text);
				[$response->text, $foreign] = $scanner->redactForeignIdentifiers($response->text, $context);
				[$response->text, $terms] = $scanner->redactProtectedTerms($response->text, $instructions);
				[$response->text, $links] = $scanner->redactLinks($response->text);

				foreach ($secrets as $rule) {
					$audit->record('output.redacted', $context->agent(), null, null, 'redacted', "secret:{$rule}", 'A secret was removed from the answer.');
				}

				if ($foreign > 0) {
					$audit->record('output.redacted', $context->agent(), null, null, 'redacted', 'foreign_identifier', "{$foreign} identifier(s) not belonging to the user were removed.", ['count' => $foreign]);
				}

				if ($terms > 0) {
					$audit->record('output.redacted', $context->agent(), null, null, 'redacted', 'protected_term', "{$terms} protected term(s) were removed.", ['count' => $terms]);
				}

				if ($links > 0) {
					$audit->record('output.redacted', $context->agent(), null, null, 'redacted', 'external_link', "{$links} link(s) outside the allowed domains were removed.", ['count' => $links]);
				}
			}
		}

		foreach ($response->toolCalls as $toolCall) {
			$rules = [];
			$toolCall->arguments = $this->cleanArguments($toolCall->arguments, $scanner, $instructions, $rules);

			foreach (array_unique($rules) as $rule) {
				$audit->record('output.redacted', $context->agent(), null, $toolCall->name, 'redacted', $rule, 'Removed from tool-call arguments before the tool ran.');
			}
		}
	}

	/**
	 * @param  array<mixed>  $arguments
	 * @param  array<int, string>  $rules
	 * @return array<mixed>
	 */
	protected function cleanArguments(array $arguments, OutputScanner $scanner, ?string $instructions, array &$rules): array
	{
		foreach ($arguments as $key => $value) {
			if (is_array($value)) {
				$arguments[$key] = $this->cleanArguments($value, $scanner, $instructions, $rules);
			} elseif (is_string($value)) {
				if ($scanner->leaksInstructions($value, $instructions)) {
					$arguments[$key] = (string) config('ai-guard.output.redaction', '[redacted]');
					$rules[] = 'instructions_leak';

					continue;
				}

				[$arguments[$key], $secrets] = $scanner->redactSecrets($value);

				foreach ($secrets as $rule) {
					$rules[] = "secret:{$rule}";
				}

				[$arguments[$key], $terms] = $scanner->redactProtectedTerms($arguments[$key], $instructions);

				if ($terms > 0) {
					$rules[] = 'protected_term';
				}
			}
		}

		return $arguments;
	}
}
