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
	 * Every call that did not run in system context.
	 *
	 * @var array<int, string>
	 */
	public array $userContextCalls = [];

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
		if ($_rbac === true || $_multitenancy === true) {
			$this->userContextCalls[] = 'findAll';
		}

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
		if ($_rbac === true || $_multitenancy === true) {
			$this->userContextCalls[] = 'saveObject';
		}

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
		if ($_rbac === true || $_multitenancy === true) {
			$this->userContextCalls[] = 'deleteObject';
		}

		unset($this->rows[$uuid]);
		$this->deleted[$uuid] = $permanent;

		return true;
	}//end deleteObject()
}//end class
