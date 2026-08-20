<?php

/**
 * In-memory MainRegisterReader test double.
 *
 * Lets the read facades and request service be tested without a live
 * OpenRegister main register. It stores objects per schema key and honours the
 * same hasSchema / find / findAll / save contract, so the facade's per-customer
 * filtering and the request service's rate-limit / scoping logic are exercised
 * for real.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\MainRegisterReader;

/**
 * Deterministic in-memory main-register reader for tests.
 */
class FakeMainRegisterReader extends MainRegisterReader {
	/**
	 * Store keyed by schema key then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Schema keys that are "configured".
	 *
	 * @var array<string, bool>
	 */
	private array $configured = [];

	/**
	 * Id counter.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Constructor (bypasses the real DI wiring).
	 */
	public function __construct() {
		// Intentionally does not call parent::__construct().
	}//end __construct()

	/**
	 * Seed an object into a schema and mark the schema configured.
	 *
	 * @param string $schemaKey The schema key.
	 * @param string $id The id.
	 * @param array<string, mixed> $data The data.
	 *
	 * @return void
	 */
	public function seed(string $schemaKey, string $id, array $data): void {
		$data['@self'] = ['id' => $id];
		$this->store[$schemaKey][$id] = $data;
		$this->configured[$schemaKey] = true;
	}//end seed()

	/**
	 * Mark a schema as configured without seeding rows.
	 *
	 * @param string $schemaKey The schema key.
	 *
	 * @return void
	 */
	public function markConfigured(string $schemaKey): void {
		$this->configured[$schemaKey] = true;
	}//end markConfigured()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $schemaKey The schema key.
	 *
	 * @return bool Whether configured.
	 */
	public function hasSchema(string $schemaKey): bool {
		return ($this->configured[$schemaKey] ?? false);
	}//end hasSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $schemaKey The schema key.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function findAll(string $schemaKey, array $filters = []): array {
		$rows = array_values($this->store[$schemaKey] ?? []);
		if (empty($filters) === true) {
			return $rows;
		}

		return array_values(array_filter($rows,
			static function (array $row) use ($filters): bool {
				foreach ($filters as $key => $value) {
					if (($row[$key] ?? null) !== $value) {
						return false;
					}
				}

				return true;
			}
		));
	}//end findAll()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $schemaKey The schema key.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	public function find(string $schemaKey, string $id): ?array {
		return ($this->store[$schemaKey][$id] ?? null);
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $schemaKey The schema key.
	 * @param array<string, mixed> $data The data.
	 * @param string|null $id The id, or null to mint.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function save(string $schemaKey, array $data, ?string $id = null): array {
		if ($id === null) {
			$this->counter++;
			$id = 'req-' . $this->counter;
		}

		$data['@self'] = ['id' => $id];
		$this->store[$schemaKey][$id] = $data;
		$this->configured[$schemaKey] = true;
		return $data;
	}//end save()
}//end class
