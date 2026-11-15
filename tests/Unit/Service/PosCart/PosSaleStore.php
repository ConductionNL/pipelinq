<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PosCart;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * An in-memory register for sales and their lines, running a pre-save hook on every write as OpenRegister does.
 *
 * Reads accept both filter shapes: flat `schema` and nested `@self.schema`.
 */
class PosSaleStore {
	/**
	 * The stored rows by uuid, each with its schema id.
	 *
	 * @var array<string, array{schema: string, row: array<string, mixed>}>
	 */
	public array $rows = [];

	/**
	 * The listener run before each write, or null.
	 *
	 * @var callable|null
	 */
	public $hook = null;

	/**
	 * Running number for new uuids.
	 *
	 * @var integer
	 */
	private int $next = 0;

	/**
	 * Store a row through the hook.
	 *
	 * @param array<string, mixed> $object The row.
	 * @param string               $schema The schema id.
	 * @param string|null          $uuid   The uuid, or null for a new row.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function save(array $object, string $schema, ?string $uuid = null): array {
		$uuid = (string)($uuid ?: ($object['id'] ?? $schema.'-'.(++$this->next)));
		$object = $this->runHook(object: $object, schema: $schema, existing: isset($this->rows[$uuid]));
		$object['id'] = $uuid;
		$this->rows[$uuid] = ['schema' => $schema, 'row' => $object];
		return $object;
	}//end save()

	/**
	 * A stored row, or null.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function row(string $uuid): ?array {
		return ($this->rows[$uuid]['row'] ?? null);
	}//end row()

	/**
	 * A stored row as an OpenRegister entity, or null.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity|null The entity.
	 */
	public function entity(string $uuid): ?ObjectEntity {
		if (isset($this->rows[$uuid]) === false) {
			return null;
		}

		return self::makeEntity(object: $this->rows[$uuid]['row'], schema: $this->rows[$uuid]['schema']);
	}//end entity()

	/**
	 * The rows matching a findAll config.
	 *
	 * @param array<string, mixed> $config The config with its filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(array $config): array {
		$filters = ($config['filters'] ?? []);
		$schema  = (string)($filters['@self']['schema'] ?? ($filters['schema'] ?? ''));
		unset($filters['@self'], $filters['register'], $filters['schema']);
		$out = [];
		foreach ($this->rows as $hit) {
			if ($hit['schema'] === $schema && array_intersect_assoc(array_map('strval', $filters), array_map('strval', array_filter($hit['row'], 'is_scalar'))) == array_map('strval', $filters)) {
				$out[] = $hit['row'];
			}
		}

		return $out;
	}//end findAll()

	/**
	 * An entity with a schema and data.
	 *
	 * @param array<string, mixed> $object The data.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	public static function makeEntity(array $object, string $schema): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($object);
		return $entity;
	}//end makeEntity()

	/**
	 * Run the pre-save hook the way OpenRegister merges its modified data.
	 *
	 * @param array<string, mixed> $object   The row.
	 * @param string               $schema   The schema id.
	 * @param bool                 $existing Whether this is an update.
	 *
	 * @return array<string, mixed> The row as stored.
	 */
	private function runHook(array $object, string $schema, bool $existing): array {
		if ($this->hook === null) {
			return $object;
		}

		$entity = self::makeEntity(object: $object, schema: $schema);
		$event  = $existing ? new ObjectUpdatingEvent($entity, null) : new ObjectCreatingEvent($entity);
		($this->hook)($event);
		return array_merge($object, $event->getModifiedData());
	}//end runHook()
}//end class
