<?php

/**
 * ListObjectStore: register-scoped, session-free object access.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every call carries the register, the schema and the session-free flags;
 * failures come back as null, [] or false and are logged.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\ListObjectStore
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class ListObjectStoreTest extends TestCase {

	/**
	 * The mocked ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * App-config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Whether the container fails to resolve the ObjectService.
	 *
	 * @var boolean
	 */
	private bool $unavailable = false;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the store under test.
	 *
	 * @return ListObjectStore
	 */
	private function store(): ListObjectStore {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('pipelinq', $app);
				return ($this->config[$key] ?? $default);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->assertSame('OCA\\OpenRegister\\Service\\ObjectService', $id);
				if ($this->unavailable === true) {
					throw new RuntimeException('no openregister');
				}

				return $this->objects;
			}
		);

		return new ListObjectStore($container, $appConfig, $this->logger);
	}//end store()

	/**
	 * An entity carrying the given payload.
	 *
	 * @param string               $uuid The uuid.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * A configured slug wins; an empty one falls back to the default.
	 *
	 * @return void
	 */
	public function testSchemaSlugPrefersTheConfiguredValue(): void {
		$this->assertSame('mailing-list', $this->store()->schemaSlug(configKey: 'list_schema', default: 'mailing-list'));

		$this->config['list_schema'] = 'custom-list';
		$this->assertSame('custom-list', $this->store()->schemaSlug(configKey: 'list_schema', default: 'mailing-list'));
	}//end testSchemaSlugPrefersTheConfiguredValue()

	/**
	 * Find passes the default register, the schema and both flags off, and
	 * normalises the entity to its serialised payload.
	 *
	 * @return void
	 */
	public function testFindCallsWithSessionFreeFlagsAndNormalises(): void {
		$this->objects->expects($this->once())->method('find')
			->with('abc', [], false, 'pipelinq', 'list', false, false)
			->willReturn(self::entity('abc', ['name' => 'News']));

		$this->assertSame(
			[
				'name' => 'News',
				'@self' => ['id' => 'abc', 'name' => 'abc', 'register' => null, 'schema' => null],
				'id' => 'abc',
			],
			$this->store()->find(schemaSlug: 'list', id: 'abc')
		);
	}//end testFindCallsWithSessionFreeFlagsAndNormalises()

	/**
	 * A configured register replaces the default slug.
	 *
	 * @return void
	 */
	public function testFindUsesTheConfiguredRegister(): void {
		$this->config['register'] = 'crm';
		$this->objects->expects($this->once())->method('find')
			->with('abc', [], false, 'crm', 'list', false, false)
			->willReturn(null);

		$this->assertNull($this->store()->find(schemaSlug: 'list', id: 'abc'));
	}//end testFindUsesTheConfiguredRegister()

	/**
	 * An empty id or schema never reaches OpenRegister; a thrown lookup is
	 * logged at info and comes back null.
	 *
	 * @return void
	 */
	public function testFindRefusesBlankInputAndSwallowsFailures(): void {
		$this->objects->expects($this->once())->method('find')->willThrowException(new RuntimeException('gone'));
		$this->logger->expects($this->once())->method('info')->with(
			'ListObjectStore.find: not found',
			['schema' => 'list', 'id' => 'x', 'exception' => 'gone']
		);

		$store = $this->store();
		$this->assertNull($store->find(schemaSlug: 'list', id: ''));
		$this->assertNull($store->find(schemaSlug: '', id: 'x'));
		$this->assertNull($store->find(schemaSlug: 'list', id: 'x'));
	}//end testFindRefusesBlankInputAndSwallowsFailures()

	/**
	 * An unreachable OpenRegister yields null, [], null and false, each
	 * logged as a warning.
	 *
	 * @return void
	 */
	public function testUnavailableOpenRegisterDegradesEveryCall(): void {
		$this->unavailable = true;
		$this->logger->expects($this->exactly(4))->method('warning')->with(
			'ListObjectStore.context: OpenRegister unavailable',
			['exception' => 'no openregister']
		);

		$store = $this->store();
		$this->assertNull($store->find(schemaSlug: 'list', id: 'a'));
		$this->assertSame([], $store->findAll(schemaSlug: 'list'));
		$this->assertNull($store->save(schemaSlug: 'list', payload: ['a' => 1]));
		$this->assertFalse($store->delete(schemaSlug: 'list', id: 'a'));
	}//end testUnavailableOpenRegisterDegradesEveryCall()

	/**
	 * FindAll merges register and schema into the filters, then drops rows
	 * that do not match the requested filters and rows with no payload.
	 *
	 * @return void
	 */
	public function testFindAllReappliesTheFiltersInPhp(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(
				['filters' => ['list' => 'L1', 'status' => 'active', 'register' => 'pipelinq', 'schema' => 'member']],
				false,
				false
			)
			->willReturn(
				[
					['list' => 'L1', 'status' => 'active', 'email' => 'a@example.org'],
					['list' => 'L2', 'status' => 'active', 'email' => 'b@example.org'],
					['list' => 'L1', 'status' => 'pending', 'email' => 'c@example.org'],
					[],
					'not a row',
					self::entity('u-4', ['list' => 'L1', 'status' => 'active', 'email' => 'd@example.org']),
				]
			);

		$rows = $this->store()->findAll(schemaSlug: 'member', filters: ['list' => 'L1', 'status' => 'active']);

		$this->assertSame(['a@example.org', 'd@example.org'], array_column($rows, 'email'));
		$this->assertSame('u-4', $rows[1]['id']);
	}//end testFindAllReappliesTheFiltersInPhp()

	/**
	 * FindAll on an empty schema returns nothing without a query; a failing
	 * query is logged with the filter keys only.
	 *
	 * @return void
	 */
	public function testFindAllFailureIsLoggedAndEmpty(): void {
		$this->objects->expects($this->once())->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'ListObjectStore.findAll: query failed',
			['schema' => 'member', 'filters' => ['list'], 'exception' => 'db down']
		);

		$store = $this->store();
		$this->assertSame([], $store->findAll(schemaSlug: ''));
		$this->assertSame([], $store->findAll(schemaSlug: 'member', filters: ['list' => 'L1']));
	}//end testFindAllFailureIsLoggedAndEmpty()

	/**
	 * Save without an id (or with an empty one) creates; with an id it
	 * updates that uuid. Flags are always off.
	 *
	 * @return void
	 */
	public function testSaveCreatesOrUpdates(): void {
		$calls = [];
		$this->objects->expects($this->exactly(3))->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid, bool $rbac, bool $mt) use (&$calls): ObjectEntity {
				$calls[] = [$object, $register, $schema, $uuid, $rbac, $mt];
				return self::entity(($uuid ?? 'new-id'), $object);
			}
		);

		$store = $this->store();
		$this->assertSame('new-id', $store->save(schemaSlug: 'list', payload: ['n' => 1])['id']);
		$this->assertSame('new-id', $store->save(schemaSlug: 'list', payload: ['n' => 2], id: '')['id']);
		$this->assertSame('u-9', $store->save(schemaSlug: 'list', payload: ['n' => 3], id: 'u-9')['id']);

		$this->assertSame(
			[
				[['n' => 1], 'pipelinq', 'list', null, false, false],
				[['n' => 2], 'pipelinq', 'list', null, false, false],
				[['n' => 3], 'pipelinq', 'list', 'u-9', false, false],
			],
			$calls
		);
	}//end testSaveCreatesOrUpdates()

	/**
	 * A failed write is logged and returns null; an empty schema never writes.
	 *
	 * @return void
	 */
	public function testSaveFailureReturnsNull(): void {
		$this->objects->expects($this->once())->method('saveObject')->willThrowException(new RuntimeException('invalid'));
		$this->logger->expects($this->once())->method('warning')->with(
			'ListObjectStore.save: write failed',
			['schema' => 'list', 'id' => 'u-1', 'exception' => 'invalid']
		);

		$store = $this->store();
		$this->assertNull($store->save(schemaSlug: '', payload: []));
		$this->assertNull($store->save(schemaSlug: 'list', payload: ['a' => 1], id: 'u-1'));
	}//end testSaveFailureReturnsNull()

	/**
	 * Delete forwards uuid, register, schema and flags, and reports the result.
	 *
	 * @return void
	 */
	public function testDeleteForwardsAndReportsTheResult(): void {
		$this->objects->expects($this->exactly(2))->method('deleteObject')
			->with('u-1', 'pipelinq', 'list', false, false)
			->willReturnOnConsecutiveCalls(true, false);

		$store = $this->store();
		$this->assertTrue($store->delete(schemaSlug: 'list', id: 'u-1'));
		$this->assertFalse($store->delete(schemaSlug: 'list', id: 'u-1'));
		$this->assertFalse($store->delete(schemaSlug: 'list', id: ''));
	}//end testDeleteForwardsAndReportsTheResult()

	/**
	 * A failed delete is logged and reports false.
	 *
	 * @return void
	 */
	public function testDeleteFailureReturnsFalse(): void {
		$this->objects->method('deleteObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning')->with(
			'ListObjectStore.delete: delete failed',
			['schema' => 'list', 'id' => 'u-1', 'exception' => 'locked']
		);

		$this->assertFalse($this->store()->delete(schemaSlug: 'list', id: 'u-1'));
	}//end testDeleteFailureReturnsFalse()

	/**
	 * The id comes from uuid, id, slug in that order, then from @self, and
	 * blank or non-scalar values are skipped.
	 *
	 * @return void
	 */
	public function testIdOfFollowsTheFallbackOrder(): void {
		$store = $this->store();

		$this->assertSame('', $store->idOf(payload: null));
		$this->assertSame('', $store->idOf(payload: []));
		$this->assertSame('u', $store->idOf(payload: ['uuid' => 'u', 'id' => 'i', 'slug' => 's']));
		$this->assertSame('i', $store->idOf(payload: ['uuid' => '', 'id' => 'i', 'slug' => 's']));
		$this->assertSame('42', $store->idOf(payload: ['uuid' => ['x'], 'id' => 42]));
		$this->assertSame('s', $store->idOf(payload: ['slug' => 's', '@self' => ['uuid' => 'self-u']]));
		$this->assertSame('self-i', $store->idOf(payload: ['id' => '', '@self' => ['uuid' => '', 'id' => 'self-i']]));
		$this->assertSame('', $store->idOf(payload: ['@self' => 'not-an-array']));
		$this->assertSame('', $store->idOf(payload: ['@self' => ['uuid' => null]]));
	}//end testIdOfFollowsTheFallbackOrder()
}//end class
