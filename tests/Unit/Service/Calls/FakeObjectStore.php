<?php

/**
 * An object store that keeps rows in memory and records how it was called.
 *
 * It takes OpenRegister's own parameter names, so a call with a wrong named
 * argument fails here as it would against the real service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Calls;

class FakeObjectStore {
	/**
	 * Rows, keyed by id. Each row carries `_schema`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every call: what it did, on which schema, with which access flags, and who was acting.
	 *
	 * @var array<int, array{op: string, schema: string, rbac: bool, multitenancy: bool, as: string|null}>
	 */
	public array $calls = [];

	/**
	 * Answers who is acting when a call arrives; null means nobody is asked.
	 *
	 * @var \Closure|null
	 */
	public ?\Closure $acting = null;

	/**
	 * When set, a call with access checks on by anybody else is refused, as OpenRegister would.
	 *
	 * @var string|null
	 */
	public ?string $onlyFor = null;

	/**
	 * Keep one call.
	 *
	 * @param string $op The operation.
	 * @param string $schema The schema.
	 * @param bool $rbac Whether access checks are on.
	 * @param bool $multitenancy Whether the tenant filter is on.
	 *
	 * @return void
	 */
	private function record(string $op, string $schema, bool $rbac, bool $multitenancy): void {
		$as = null;
		if ($this->acting !== null) {
			$as = ($this->acting)();
		}

		$this->calls[] = ['op' => $op, 'schema' => $schema, 'rbac' => $rbac, 'multitenancy' => $multitenancy, 'as' => $as];
		if ($rbac === true && $this->onlyFor !== null && $as !== $this->onlyFor) {
			throw new \RuntimeException('Access denied: ' . $op . ' on ' . $schema . ' as ' . ($as ?? 'nobody'));
		}
	}//end record()

	/**
	 * The calls of one kind.
	 *
	 * @param string $op The operation.
	 * @param string|null $schema Only this schema, or every schema.
	 *
	 * @return array<int, array{op: string, schema: string, rbac: bool, multitenancy: bool, as: string|null}>
	 */
	public function callsOf(string $op, ?string $schema = null): array {
		return array_values(
			array_filter(
				$this->calls,
				static fn (array $call): bool => $call['op'] === $op && ($schema === null || $call['schema'] === $schema)
			)
		);
	}//end callsOf()

	/**
	 * Ids removed, with whether the removal was permanent.
	 *
	 * @var array<string, bool>
	 */
	public array $deleted = [];

	/**
	 * When true, the store ignores every filter but register and schema.
	 */
	public bool $ignoresFilters = false;

	/**
	 * Find rows.
	 *
	 * @param array<string, mixed> $config The query.
	 * @param bool $_rbac Whether access rules apply.
	 * @param bool $_multitenancy Whether tenancy applies.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->record(op: 'findAll', schema: (string)($config['filters']['schema'] ?? ''), rbac: $_rbac, multitenancy: $_multitenancy);

		$filters = ($config['filters'] ?? []);
		$out = [];
		foreach ($this->rows as $row) {
			if ($row['_schema'] !== ($filters['schema'] ?? '')) {
				continue;
			}

			$keep = true;
			foreach ($filters as $key => $value) {
				if ($this->ignoresFilters === true || $key === 'register' || $key === 'schema') {
					continue;
				}

				$keep = $keep && (($row[$key] ?? null) === $value);
			}

			if ($keep === true) {
				$out[] = $row;
			}
		}

		return array_slice($out, 0, ($config['limit'] ?? 1000));
	}//end findAll()

	/**
	 * Save a row.
	 *
	 * @param array<string, mixed> $object The row.
	 * @param array<int, string>|null $extend Unused.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 * @param string|null $uuid The id, or null to create.
	 * @param bool $_rbac Whether access rules apply.
	 * @param bool $_multitenancy Whether tenancy applies.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->record(op: 'saveObject', schema: (string)$schema, rbac: $_rbac, multitenancy: $_multitenancy);

		$id = ($uuid ?? ('row-' . (count($this->rows) + 1)));
		$this->rows[$id] = array_merge($object, ['id' => $id, '_schema' => $schema]);

		return $this->rows[$id];
	}//end saveObject()

	/**
	 * Remove a row.
	 *
	 * @param string $uuid The id.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 * @param bool $_rbac Whether access rules apply.
	 * @param bool $_multitenancy Whether tenancy applies.
	 * @param bool $permanent Whether the row is gone for good.
	 *
	 * @return bool True when removed.
	 */
	public function deleteObject(
		string $uuid,
		?string $register = null,
		?string $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $permanent = false,
	): bool {
		$this->record(op: 'deleteObject', schema: (string)$schema, rbac: $_rbac, multitenancy: $_multitenancy);

		unset($this->rows[$uuid]);
		$this->deleted[$uuid] = $permanent;

		return true;
	}//end deleteObject()
}//end class
