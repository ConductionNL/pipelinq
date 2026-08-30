<?php

/**
 * In-memory MdmObjectRepository test double.
 *
 * Replaces the OpenRegister-backed persistence of MdmObjectRepository with a
 * per-schema array store so the MDM services' real logic (golden-record
 * survivorship, merge/unmerge, sync backoff, scoring, anonymisation) runs
 * unmocked against deterministic data. Only the storage methods are overridden;
 * uuid() / now() are kept (now() is injectable for deterministic time).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Mdm
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Mdm;

use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;

/**
 * Array-backed repository for MDM service unit tests.
 */
final class InMemoryMdmObjectRepository extends MdmObjectRepository {
	/**
	 * Per-schema object store: schemaSlug => [id => object].
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Fixed clock value for deterministic now().
	 *
	 * @var string
	 */
	public string $clock = '2026-06-03T12:00:00Z';

	/**
	 * Monotonic uuid counter for predictable ids.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Construct without any real collaborators.
	 */
	public function __construct() {
		// Intentionally do not call parent::__construct — no OR/container needed.
	}//end __construct()

	/**
	 * Seed an object directly into the store.
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param string $id The object id.
	 * @param array<string, mixed> $object The object data.
	 *
	 * @return void
	 */
	public function seed(string $schemaSlug, string $id, array $object): void {
		$object['id'] = $id;
		$this->store[$schemaSlug][$id] = $object;
	}//end seed()

	/**
	 * {@inheritDoc}
	 */
	public function register(): string {
		return 'test-register';
	}//end register()

	/**
	 * {@inheritDoc}
	 */
	public function schema(string $schemaSlug): string {
		return $schemaSlug . '-schema';
	}//end schema()

	/**
	 * {@inheritDoc}
	 */
	public function find(string $schemaSlug, string $id): ?array {
		return ($this->store[$schemaSlug][$id] ?? null);
	}//end find()

	/**
	 * {@inheritDoc}
	 */
	public function findAll(string $schemaSlug, array $filters = []): array {
		$objects = array_values(($this->store[$schemaSlug] ?? []));

		// Drop the register/schema framing filters; apply the rest as equality.
		unset($filters['register'], $filters['schema']);

		if (empty($filters) === true) {
			return $objects;
		}

		return array_values(
			array_filter($objects,
				static function (array $object) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($object[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * {@inheritDoc}
	 */
	public function save(string $schemaSlug, array $object, ?string $uuid = null): array {
		unset($object['@self']);

		$id = ($uuid ?? (string)($object['id'] ?? $this->uuid()));
		$object['id'] = $id;

		$this->store[$schemaSlug][$id] = $object;

		return $object;
	}//end save()

	/**
	 * {@inheritDoc}
	 */
	public function delete(string $schemaSlug, string $id): void {
		unset($this->store[$schemaSlug][$id]);
	}//end delete()

	/**
	 * {@inheritDoc}
	 */
	public function uuid(): string {
		$this->counter++;

		return sprintf('uuid-%04d', $this->counter);
	}//end uuid()

	/**
	 * {@inheritDoc}
	 */
	public function now(): string {
		return $this->clock;
	}//end now()
}//end class
