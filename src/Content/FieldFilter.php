<?php

namespace ZeeshanElahi\AiGuard\Content;

/**
 * Trims a JSON tool result to an allowlist of fields, so the model only sees what the tool is
 * meant to share. Paths use dots ("customer.name"); a path into a list applies to every item
 * ("items.sku" keeps the sku of each item).
 */
class FieldFilter
{
	/**
	 * @param  array<int, string>  $paths
	 */
	public function filter(string $result, array $paths): FilteredResult
	{
		[$prefix, $data] = $this->decode($result);

		if ($data === null) {
			return FilteredResult::unstructured();
		}

		$removed = [];
		$filtered = $this->apply($data, $this->tree($paths), '', $removed);
		$json = json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

		return FilteredResult::structured($prefix.$json, $removed);
	}

	/**
	 * Accepts a whole-JSON result, or text followed by JSON (as laravel/ai's SimilaritySearch returns).
	 *
	 * @return array{string, array<mixed>|null}
	 */
	protected function decode(string $result): array
	{
		$decoded = json_decode($result, true);

		if (is_array($decoded)) {
			return ['', $decoded];
		}

		$start = strcspn($result, '{[');

		if ($start >= strlen($result)) {
			return ['', null];
		}

		$decoded = json_decode(substr($result, $start), true);

		return is_array($decoded) ? [substr($result, 0, $start), $decoded] : ['', null];
	}

	/**
	 * @param  array<int, string>  $paths
	 * @return array<string, mixed>
	 */
	protected function tree(array $paths): array
	{
		$tree = [];

		foreach ($paths as $path) {
			$node = &$tree;

			foreach (explode('.', $path) as $segment) {
				if (($node[$segment] ?? null) === true) {
					continue 2;
				}

				$node[$segment] ??= [];
				$node = &$node[$segment];
			}

			$node = true;
			unset($node);
		}

		return $tree;
	}

	/**
	 * @param  array<mixed>  $data
	 * @param  array<string, mixed>  $tree
	 * @param  array<int, string>  $removed
	 * @return array<mixed>
	 */
	protected function apply(array $data, array $tree, string $prefix, array &$removed): array
	{
		if (array_is_list($data)) {
			// A plain loop, not array_map: $removed must be passed by reference into every item.
			// Plain values in a list have no field names to allow, so they fail closed.
			$kept = [];

			foreach ($data as $item) {
				if (is_array($item)) {
					$kept[] = $this->apply($item, $tree, $prefix, $removed);
				} else {
					$path = $prefix === '' ? '[]' : $prefix.'[]';
					$removed[$path] = $path;
				}
			}

			return $kept;
		}

		$kept = [];

		foreach ($data as $key => $value) {
			$path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
			$rule = $tree[$key] ?? null;

			if ($rule === true) {
				$kept[$key] = $value;
			} elseif (is_array($rule) && is_array($value)) {
				$kept[$key] = $this->apply($value, $rule, $path, $removed);
			} else {
				$removed[$path] = $path;
			}
		}

		return $kept;
	}
}
