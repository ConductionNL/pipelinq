<?php

/**
 * A small in-memory OpenRegister ObjectService for the opt-out tests.
 *
 * Mirrors the calls pipelinq makes: find(), findAll(['filters' => ...]) with
 * register and schema as reserved keys, saveObject(), updateObject() and
 * deleteObject().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

/**
 * Rows by uuid, each remembering its schema.
 */
class InMemoryObjectService {

	/** @var array<string, array{schema: string, row: array<string, mixed>}> */
	public array $rows = [];

	/** @var int */
	private int $next = 0;

	/**
	 * Store a row under a schema.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row; `uuid` is assigned when absent.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function put(string $schema, array $row): array {
		$row['uuid'] = (string)($row['uuid'] ?? ($schema.'-'.(++$this->next)));
		$this->rows[$row['uuid']] = ['schema' => $schema, 'row' => $row];
		return $row;
	}//end put()

	/**
	 * Rows of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function ofSchema(string $schema): array {
		$out = [];
		foreach ($this->rows as $hit) {
			if ($hit['schema'] === $schema) {
				$out[] = $hit['row'];
			}
		}

		return $out;
	}//end ofSchema()

	public function find(string $id, $register = null, $schema = null): ?array {
		$hit = ($this->rows[$id] ?? null);
		if ($hit === null || ($schema !== null && $hit['schema'] !== $schema)) {
			return null;
		}

		return $hit['row'];
	}//end find()

	public function findAll(array $config = []): array {
		$filters = ($config['filters'] ?? []);
		$schema = ($filters['schema'] ?? null);
		unset($filters['register'], $filters['schema']);
		$out = [];
		foreach ($this->rows as $hit) {
			if ($schema !== null && $hit['schema'] !== $schema) {
				continue;
			}

			foreach ($filters as $key => $value) {
				if (($hit['row'][$key] ?? null) !== $value) {
					continue 2;
				}
			}

			$out[] = $hit['row'];
		}

		return $out;
	}//end findAll()

	public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
		if ($uuid !== null && $uuid !== '') {
			$object['uuid'] = $uuid;
		}

		return $this->put(schema: (string)$schema, row: $object);
	}//end saveObject()

	public function updateObject(string $id, array $object, $register = null, $schema = null): array {
		$object['uuid'] = $id;
		return $this->put(schema: (string)$schema, row: $object);
	}//end updateObject()

	public function deleteObject(string $uuid, $register = null, $schema = null): bool {
		unset($this->rows[$uuid]);
		return true;
	}//end deleteObject()
}//end class
