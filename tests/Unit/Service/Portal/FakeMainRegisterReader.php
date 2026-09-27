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
 * Only the STORAGE is faked. Whether a schema is configured is the real answer:
 * the parent's hasSchema() over an app config holding only what the install
 * writes ({@see InstalledAppConfig}). A service that reads a schema the install
 * never configures (the retired `request`, pipelinq#2038) finds nothing here,
 * exactly as it does live. The previous double reported any key it was told
 * about as configured, which is how that shipped with a green suite.
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

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use OCP\IAppConfig;
use Psr\Log\NullLogger;
use RuntimeException;

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
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig An app config holding the installed keys
	 *                              (build it with InstalledAppConfig::wire()).
	 */
	public function __construct(IAppConfig $appConfig) {
		// The OR stub is inert: storage is the in-memory map below.
		parent::__construct($appConfig, new NullLogger(), new ObjectService());
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
		return parent::hasSchema(schemaKey: $schemaKey) === true && ($this->configured[$schemaKey] ?? false);
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
		if (parent::hasSchema(schemaKey: $schemaKey) === false) {
			return [];
		}

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
		if (parent::hasSchema(schemaKey: $schemaKey) === false) {
			return null;
		}

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
		if (parent::hasSchema(schemaKey: $schemaKey) === false) {
			throw new RuntimeException("Main register schema '{$schemaKey}' is not configured.");
		}

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
