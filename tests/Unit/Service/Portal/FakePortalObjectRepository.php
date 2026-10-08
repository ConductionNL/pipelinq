<?php

/**
 * In-memory PortalObjectRepository test double.
 *
 * Replaces the OpenRegister-backed repository with a deterministic in-memory
 * store so portal services can be unit-tested without a live OR/ObjectService.
 * It honours the same find / findAll / findOneBy / save / idOf contract,
 * including equality filtering and id minting, so the tests exercise the real
 * service logic (scoping, expiry, rate limits) rather than rigged mocks.
 *
 * Only the STORAGE is faked. The schema lookup is the real one: every read and
 * write first resolves the slug through the parent's schemaId() over an app
 * config holding only what the install writes ({@see InstalledAppConfig}), so a
 * service that asks for a schema the install does not configure fails here the
 * way it fails live. The previous double answered any slug, which let
 * pipelinq#2037 (no resident could log in) ship with a green suite.
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
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCP\IAppConfig;
use Psr\Log\NullLogger;

/**
 * Deterministic in-memory portal repository for tests.
 */
class FakePortalObjectRepository extends PortalObjectRepository {
	/**
	 * Store keyed by schema slug then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Monotonic id counter.
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
		// The service account is never consulted: save() is overridden below.
		$serviceAccount = (new \ReflectionClass(PortalServiceAccount::class))->newInstanceWithoutConstructor();
		parent::__construct($appConfig, new NullLogger(), new ObjectService(), $serviceAccount);
	}//end __construct()

	/**
	 * Seed an object into a schema with an explicit id.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id The id.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return array<string, mixed> The stored object (with @self.id).
	 */
	public function seed(string $schema, string $id, array $data): array {
		$this->schemaId(schemaSlug: $schema);
		$data['@self'] = ['id' => $id];
		$this->store[$schema][$id] = $data;
		return $data;
	}//end seed()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	public function find(string $schemaSlug, string $id): ?array {
		// The real find() swallows the lookup failure into "not found".
		try {
			$this->schemaId(schemaSlug: $schemaSlug);
		} catch (\RuntimeException $e) {
			return null;
		}

		return ($this->store[$schemaSlug][$id] ?? null);
	}//end find()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters The equality filters.
	 *
	 * @return array<int, array<string, mixed>> The matches.
	 */
	public function findAll(string $schemaSlug, array $filters = []): array {
		// The real findAll() resolves the schema OUTSIDE its try block, so an
		// unconfigured schema throws to the caller; so does this.
		$this->schemaId(schemaSlug: $schemaSlug);
		$rows = array_values($this->store[$schemaSlug] ?? []);
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
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $filters The equality filters.
	 *
	 * @return array<string, mixed>|null The first match.
	 */
	public function findOneBy(string $schemaSlug, array $filters): ?array {
		$matches = $this->findAll($schemaSlug, $filters);
		return ($matches[0] ?? null);
	}//end findOneBy()

	/**
	 * The in-memory store can always write.
	 *
	 * @return void
	 */
	public function requireWritable(): void {
	}//end requireWritable()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $schemaSlug The schema slug.
	 * @param array<string, mixed> $data The data.
	 * @param string|null $id The id, or null to mint.
	 *
	 * @return array<string, mixed> The saved object.
	 */
	public function save(string $schemaSlug, array $data, ?string $id = null): array {
		$this->schemaId(schemaSlug: $schemaSlug);
		if ($id === null) {
			$this->counter++;
			$id = 'id-' . $this->counter;
		}

		$data['@self'] = ['id' => $id];
		$this->store[$schemaSlug][$id] = $data;
		return $data;
	}//end save()

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $object The object.
	 *
	 * @return string|null The id.
	 */
	public function idOf(array $object): ?string {
		if (isset($object['@self']['id']) === true) {
			return (string)$object['@self']['id'];
		}

		return ($object['id'] ?? null);
	}//end idOf()

	/**
	 * Count stored objects of a schema (test assertion helper).
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return int The count.
	 */
	public function count(string $schema): int {
		return count($this->store[$schema] ?? []);
	}//end count()
}//end class
