<?php

/**
 * Phase-0 POS regression tests for PosTransactionService.
 *
 * Locks the three server-authoritative POS fixes made during Phase-0 hardening,
 * each of which previously left every POS total at 0 or made confirm fail:
 *
 *   1. config() slug-fallback — when the deployed `<slug>_schema` app-config key
 *      is empty, the canonical slug derived from the key is resolved to its
 *      numeric OpenRegister schema id via SchemaMapper (findAll's `@self.schema`
 *      filter needs a numeric id; a slug silently matches nothing).
 *   2. `@self` filter nesting — register / schema are nested under a `@self`
 *      block in findAll() filters (flat filters.register / filters.schema are
 *      treated as ordinary property filters and return zero rows, the bug that
 *      left the cart "empty" and every total at 0).
 *   3. null-key drop on re-save — saveTransaction() strips null-valued keys
 *      before persisting so OpenRegister's strict type validation does not
 *      reject an optional typed field that came back null on re-fetch.
 *
 * These methods are private; they are exercised through the public confirm /
 * recalculate surface with a fake OR ObjectService + SchemaMapper wired through
 * the container, so the assertions observe the exact filter shape and persisted
 * payload the real OpenRegister would receive.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake OR ObjectService recording every findAll / saveObject call so the test
 * can assert the exact filter shape and persisted payload.
 */
class Phase0FakeObjectService {

	/**
	 * Captured findAll() config arrays, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $findAllCalls = [];

	/**
	 * Captured saveObject() argument bundles, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saveCalls = [];

	/**
	 * Rows returned by findAll(); keyed by nothing — returned verbatim.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * The single object returned by find().
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $object = null;

	/**
	 * Record the find() lookup and return the configured object.
	 *
	 * @param string $id The object UUID.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return array<string, mixed>|null The configured object.
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return $this->object;
	}//end find()

	/**
	 * Record and answer a findAll() query.
	 *
	 * @param array<string, mixed> $config The query config.
	 *
	 * @return array<int, array<string, mixed>> The configured rows.
	 */
	public function findAll(array $config = []): array {
		$this->findAllCalls[] = $config;
		return $this->rows;
	}//end findAll()

	/**
	 * Record and echo a saveObject() call.
	 *
	 * @param array<string, mixed> $object The persisted payload.
	 * @param array<string, mixed> $extend Extra values.
	 * @param string|int|null $register The register id.
	 * @param string|int|null $schema The schema id.
	 * @param string|null $uuid The UUID.
	 *
	 * @return array<string, mixed> The persisted payload (echoed back).
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): array {
		$this->saveCalls[] = [
			'object' => $object,
			'register' => $register,
			'schema' => $schema,
			'uuid' => $uuid,
		];
		return $object;
	}//end saveObject()
}//end class

/**
 * Fake OR Schema entity exposing the magic getId() the slug-resolver calls.
 */
class Phase0FakeSchema {
	/**
	 * Constructor.
	 *
	 * @param int|string $id The numeric schema id.
	 */
	public function __construct(
		private int|string $id,
	) {
	}//end __construct()

	/**
	 * The numeric schema id.
	 *
	 * @return int|string The id.
	 */
	public function getId(): int|string {
		return $this->id;
	}//end getId()
}//end class

/**
 * Fake OR SchemaMapper resolving a slug to a Phase0FakeSchema with a numeric id.
 */
class Phase0FakeSchemaMapper {
	/**
	 * Constructor.
	 *
	 * @param array<string, int> $idsBySlug Slug => numeric id map.
	 */
	public function __construct(
		private array $idsBySlug = [],
	) {
	}//end __construct()

	/**
	 * Resolve a slug (or id/uuid) to a fake schema.
	 *
	 * @param string $idOrSlug The slug to resolve.
	 * @param array<int, string> $extend Unused (OR signature).
	 * @param int|null $register Unused (OR signature).
	 * @param bool $rbac Unused (OR signature).
	 * @param bool $tenant Unused (OR signature).
	 *
	 * @return Phase0FakeSchema The resolved fake schema.
	 *
	 * @throws \RuntimeException When the slug is unknown.
	 */
	public function find(
		string $idOrSlug,
		array $extend = [],
		?int $register = null,
		bool $rbac = true,
		bool $tenant = true,
	): Phase0FakeSchema {
		if (isset($this->idsBySlug[$idOrSlug]) === false) {
			throw new \RuntimeException('unknown schema slug ' . $idOrSlug);
		}

		return new Phase0FakeSchema($this->idsBySlug[$idOrSlug]);
	}//end find()
}//end class

/**
 * Phase-0 regression tests for PosTransactionService.
 */
class PosTransactionServicePhase0Test extends TestCase {
	/**
	 * Build the service wired to the given fake OR collaborators.
	 *
	 * @param Phase0FakeObjectService $objects The fake ObjectService.
	 * @param Phase0FakeSchemaMapper $mapper The fake SchemaMapper.
	 * @param array<string, string> $configMap The app-config key => value map.
	 *
	 * @return PosTransactionService The wired service.
	 */
	private function service(
		Phase0FakeObjectService $objects,
		Phase0FakeSchemaMapper $mapper,
		array $configMap,
	): PosTransactionService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($configMap[$key] ?? $default)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objects, $mapper) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objects;
				}

				if ($id === 'OCA\OpenRegister\Db\SchemaMapper') {
					return $mapper;
				}

				throw new \RuntimeException('unknown service ' . $id);
			}
		);

		$policy = new PosAccessPolicy(
			appConfig: $appConfig,
			groupManager: $this->createMock(IGroupManager::class),
		);

		return new PosTransactionService($container,
			$appConfig,
			$policy,
			$this->createMock(LoggerInterface::class),
			$this->createMock(IEventDispatcher::class),
		);
	}//end service()

	/**
	 * Resolves an EMPTY posTransaction_schema config key to the numeric schema id
	 * via the SchemaMapper slug-fallback, and nests register/schema under `@self`
	 * in the line findAll() filter.
	 *
	 * Locks Phase-0 fixes 1 (slug-fallback) and 2 (`@self` nesting).
	 *
	 * @return void
	 */
	public function testSlugFallbackAndSelfNestingOnEmptySchemaConfig(): void {
		$objects = new Phase0FakeObjectService();
		// The fetched transaction (posTransaction_schema lookup) and one line.
		$objects->object = ['id' => 'txn-1', 'priceMode' => 'excl', 'reference' => 'R1'];
		$objects->rows = [
			['quantity' => 2, 'unitPrice' => 10.00, 'discount' => 0, 'taxRate' => 21],
		];

		// Register IS configured (16) but the *_schema keys are BLANK — the exact
		// deployed-box condition the fallback was written for.
		$mapper = new Phase0FakeSchemaMapper(
			[
				'posTransaction' => 41,
				'posTransactionLine' => 42,
			]
		);

		$service = $this->service(
			objects: $objects,
			mapper: $mapper,
			configMap: ['register' => '16'],
		);

		$service->recalculateTotals('txn-1');

		// The line findAll() must scope register/schema under @self (not flat),
		// with the slug-resolved NUMERIC schema id (42), and keep the custom
		// `transaction` filter at the top level.
		$this->assertNotEmpty($objects->findAllCalls);
		$filters = $objects->findAllCalls[0]['filters'];

		$this->assertArrayHasKey('@self', $filters);
		$this->assertSame('16', $filters['@self']['register']);
		$this->assertSame('42', $filters['@self']['schema']);
		$this->assertSame('txn-1', $filters['transaction']);

		// Flat register/schema keys must NOT be present (the regression).
		$this->assertArrayNotHasKey('register', $filters);
		$this->assertArrayNotHasKey('schema', $filters);
	}//end testSlugFallbackAndSelfNestingOnEmptySchemaConfig()

	/**
	 * When the numeric `<slug>_schema` config key IS populated, the configured id
	 * is used verbatim and the SchemaMapper fallback is never consulted.
	 *
	 * @return void
	 */
	public function testConfiguredSchemaIdUsedVerbatim(): void {
		$objects = new Phase0FakeObjectService();
		$objects->object = ['id' => 'txn-2', 'priceMode' => 'excl'];
		$objects->rows = [];

		// Mapper would throw if consulted (proves it is NOT used).
		$mapper = new Phase0FakeSchemaMapper([]);

		$service = $this->service(
			objects: $objects,
			mapper: $mapper,
			configMap: [
				'register' => '16',
				'posTransaction_schema' => '7',
				'posTransactionLine_schema' => '8',
			],
		);

		$service->recalculateTotals('txn-2');

		$filters = $objects->findAllCalls[0]['filters'];
		$this->assertSame('8', $filters['@self']['schema']);
	}//end testConfiguredSchemaIdUsedVerbatim()

	/**
	 * Strips null-valued keys (and the stale `@self` block) from the payload
	 * before persisting, so OpenRegister strict-type validation does not reject a
	 * null on a non-null-typed optional field on the confirm/recompute re-save.
	 *
	 * Locks Phase-0 fix 3 (null-key drop on re-save).
	 *
	 * @return void
	 */
	public function testSaveDropsNullKeysAndSelfBlock(): void {
		$objects = new Phase0FakeObjectService();
		// Re-fetched object carries an optional field that came back as null and a
		// stale @self block that must be stripped before persisting.
		$objects->object = [
			'id' => 'txn-3',
			'priceMode' => 'excl',
			'reference' => 'R3',
			'consentSyncStatus' => null,
			'parkedAt' => null,
			'@self' => ['id' => 'should-be-stripped'],
		];
		$objects->rows = [
			['quantity' => 1, 'unitPrice' => 50.00, 'discount' => 0, 'taxRate' => 21],
		];

		$mapper = new Phase0FakeSchemaMapper(
			[
				'posTransaction' => 41,
				'posTransactionLine' => 42,
			]
		);

		$service = $this->service(
			objects: $objects,
			mapper: $mapper,
			configMap: ['register' => '16'],
		);

		$service->recalculateTotals('txn-3');

		$this->assertNotEmpty($objects->saveCalls);
		$persisted = $objects->saveCalls[0]['object'];

		// Null-valued keys are dropped (the regression that failed every confirm
		// with "Property 'consentSyncStatus' should be type 'string' but is 'null'").
		$this->assertArrayNotHasKey('consentSyncStatus', $persisted);
		$this->assertArrayNotHasKey('parkedAt', $persisted);
		// The stale @self block is never written back.
		$this->assertArrayNotHasKey('@self', $persisted);
		// The save is addressed to the resolved UUID and the resolved schema id.
		$this->assertSame('txn-3', $objects->saveCalls[0]['uuid']);
		$this->assertSame('41', $objects->saveCalls[0]['schema']);

		// Server-authoritative totals were computed and persisted: 50 net @ 21%
		// => tax 10.50, total 60.50.
		$this->assertSame(50.00, $persisted['subtotal']);
		$this->assertSame(10.50, $persisted['totalTax']);
		$this->assertSame(60.50, $persisted['total']);
		$this->assertSame('excl', $persisted['priceMode']);
	}//end testSaveDropsNullKeysAndSelfBlock()
}//end class
