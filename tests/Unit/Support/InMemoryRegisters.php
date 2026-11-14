<?php

/**
 * An in-memory OpenRegister ObjectService that keeps registers apart.
 *
 * The article move reads pipelinq's register and writes portaliq's, so a fake
 * that ignored the register would let a test pass while the code read the
 * wrong one. Accepts the `_rbac` and `_multitenancy` named arguments pipelinq
 * always passes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use RuntimeException;

/**
 * Rows by register, schema and uuid.
 */
class InMemoryRegisters {

	/**
	 * Rows: register => schema => uuid => row.
	 *
	 * @var array<string, array<string, array<string, array<string, mixed>>>>
	 */
	public array $rows = [];

	/**
	 * Registers whose every call throws, as a missing register does.
	 *
	 * @var array<int, string>
	 */
	public array $missing = [];

	/**
	 * Every saveObject call, in order.
	 *
	 * @var array<int, array{register: string, schema: string, uuid: ?string}>
	 */
	public array $saves = [];

	/**
	 * Whether saves into a register fail.
	 *
	 * @var array<int, string>
	 */
	public array $failingSaves = [];

	/**
	 * The uuid counter.
	 *
	 * @var int
	 */
	private int $next = 0;

	/**
	 * Store a row directly.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $row      The row; a uuid is given when absent.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function put(string $register, string $schema, array $row): array {
		$row['uuid'] = (string)($row['uuid'] ?? ($register.'-'.$schema.'-'.(++$this->next)));
		$this->rows[$register][$schema][$row['uuid']] = $row;
		return $row;
	}//end put()

	/**
	 * The rows of one schema.
	 *
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function rowsOf(string $register, string $schema): array {
		return array_values($this->rows[$register][$schema] ?? []);
	}//end rowsOf()

	/**
	 * ObjectService::find.
	 *
	 * @param string $id            The uuid.
	 * @param mixed  $register      The register.
	 * @param mixed  $schema        The schema.
	 * @param bool   $_rbac         Ignored.
	 * @param bool   $_multitenancy Ignored.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function find(string $id, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
		$this->guard(register: (string)$register);
		return ($this->rows[(string)$register][(string)$schema][$id] ?? null);
	}//end find()

	/**
	 * ObjectService::findAll.
	 *
	 * @param array<string, mixed> $config        `filters` with register and schema.
	 * @param bool                 $_rbac         Ignored.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$filters  = ($config['filters'] ?? []);
		$register = (string)($filters['register'] ?? '');
		$schema   = (string)($filters['schema'] ?? '');
		$this->guard(register: $register);
		unset($filters['register'], $filters['schema']);

		$out = [];
		foreach (($this->rows[$register][$schema] ?? []) as $row) {
			foreach ($filters as $key => $value) {
				if ((string)($row[$key] ?? '') !== (string)$value) {
					continue 2;
				}
			}

			$out[] = $row;
		}

		return $out;
	}//end findAll()

	/**
	 * ObjectService::saveObject.
	 *
	 * @param array<string, mixed> $object        The object.
	 * @param mixed                $register      The register.
	 * @param mixed                $schema        The schema.
	 * @param string|null          $uuid          An existing uuid.
	 * @param bool                 $_rbac         Ignored.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
		$this->guard(register: (string)$register);
		$this->saves[] = ['register' => (string)$register, 'schema' => (string)$schema, 'uuid' => $uuid];
		if (in_array((string)$register, $this->failingSaves, true) === true) {
			throw new RuntimeException('save refused');
		}

		if ($uuid !== null && $uuid !== '') {
			$object['uuid'] = $uuid;
		}

		return $this->put(register: (string)$register, schema: (string)$schema, row: $object);
	}//end saveObject()

	/**
	 * Throw for a missing register.
	 *
	 * @param string $register The register.
	 *
	 * @return void
	 */
	private function guard(string $register): void {
		if (in_array($register, $this->missing, true) === true) {
			throw new RuntimeException('Register not found: '.$register);
		}
	}//end guard()
}//end class
