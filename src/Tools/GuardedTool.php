<?php

namespace ZeeshanElahi\AiGuard\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;
use Throwable;
use ZeeshanElahi\AiGuard\Audit\AuditLogger;
use ZeeshanElahi\AiGuard\Content\FieldFilter;
use ZeeshanElahi\AiGuard\Content\UntrustedContent;
use ZeeshanElahi\AiGuard\GuardContext;
use ZeeshanElahi\AiGuard\Policy\ToolPolicy;

/**
 * Wraps a tool so its policy is checked in code before it runs. The model sees the same
 * name, description and schema as the original tool.
 */
class GuardedTool implements Tool
{
	public function __construct(
		protected Tool $inner,
		protected ?ToolPolicy $policy,
		protected GuardContext $context,
		protected AuditLogger $audit,
	) {}

	public function name(): string
	{
		return ToolNameResolver::resolve($this->inner);
	}

	public function inner(): Tool
	{
		return $this->inner;
	}

	public function description(): Stringable|string
	{
		return $this->inner->description();
	}

	public function schema(JsonSchema $schema): array
	{
		return $this->inner->schema($schema);
	}

	public function handle(Request $request): Stringable|string
	{
		$name = $this->name();
		$payload = ['arguments' => $request->all(), 'invocation_id' => $this->context->invocationId()];

		if (config('ai-guard.enabled', true) && $this->policy !== null) {
			$decision = $this->policy->evaluate($request, $this->context, $name);

			if (! $decision->allowed) {
				$this->audit->record('tool.blocked', $this->context->agent(), null, $name, 'blocked', $decision->rule, $decision->reason, $payload);

				return str_replace(':reason', (string) $decision->reason, (string) config('ai-guard.blocked_message'));
			}
		}

		$this->context->recordCall($name);

		try {
			$result = $this->inner->handle($request);
		} catch (Throwable $exception) {
			$this->audit->record('tool.failed', $this->context->agent(), null, $name, 'failed', null, $exception->getMessage(), $payload);

			throw $exception;
		}

		$result = (string) $result;
		$fields = $this->policy?->returnFields();

		if (config('ai-guard.enabled', true) && $fields !== null) {
			$filtered = (new FieldFilter)->filter($result, $fields);

			if (! $filtered->structured) {
				$this->audit->record('tool.withheld', $this->context->agent(), null, $name, 'withheld', 'return_fields', 'The result was not JSON, so its fields could not be checked.', $payload);

				return (string) config('ai-guard.withheld_message');
			}

			$result = (string) $filtered->content;
			$payload['removed_fields'] = $filtered->removed;
		}

		$this->audit->record('tool.allowed', $this->context->agent(), null, $name, 'allowed', null, null, $payload);

		return config('ai-guard.enabled', true) && config('ai-guard.untrusted.enabled', true)
			? UntrustedContent::wrap($result, $name, $this->context->boundary())
			: $result;
	}
}
