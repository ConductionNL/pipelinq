<?php

/**
 * ListObjectStore Unit Tests.
 *
 * Pins how marketing list records are read and written through OpenRegister:
 * the register and schema used, RBAC and multitenancy off, exact-match
 * filtering of what the store returns, and that every failure is soft.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ListObjectStore.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\ListObjectStore
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class ListObjectStoreTest extends TestCase {

	/**
	 * Calls the object service fake received, as [method, args].
	 *
	 * @var array<int,array{0:string,1:array}>
	 */
	public array $calls = [];

	/**
	 * What the fake throws, or null.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $failure = null;

	/**
	 * Rows findAll answers.
	 *
	 * @var array
	 */
	public array $rows = [];

	/**
	 * Messages logged, as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array}>
	 */
	private array $logged = [];

	/**
	 * A recording OpenRegister object service.
	 *
	 * @return object
	 */
	private function objectService(): object {
		$test = $this;
		return new class ($test) {
			public function __construct(private ListObjectStoreTest $test) {
			}

			private function record(string $method, array $args): void {
				$this->test->calls[] = [$method, $args];
				if ($this->test->failure !== null) {
					throw $this->test->failure;
				}
			}

			public function find(string $id, $register, $schema, bool $_rbac = true, bool $_multitenancy = true): ?object {
				$this->record('find', compact('id', 'register', 'schema', '_rbac', '_multitenancy'));
				if ($id === 'missing') {
					return null;
				}

				return new class ($id) {
					public function __construct(private string $id) {
					}

					public function getObject(): array {
						return ['id' => $this->id, 'name' => 'Nieuwsbrief'];
					}
				};
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$this->record('findAll', compact('config', '_rbac', '_multitenancy'));
				return $this->test->rows;
			}

			public function saveObject(array $object, $register, $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->record('saveObject', compact('object', 'register', 'schema', 'uuid', '_rbac', '_multitenancy'));
				return $object + ['id' => ($uuid ?? 'new-id')];
			}

			public function deleteObject(string $uuid, $register, $schema, bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->record('deleteObject', compact('uuid', 'register', 'schema', '_rbac', '_multitenancy'));
				return true;
			}
		};
	}//end objectService()

	/**
	 * The store under test.
	 *
	 * @param array<string,string> $config      App config values.
	 * @param bool                 $withService Whether OpenRegister is installed.
	 *
	 * @return ListObjectStore
	 */
	private function store(array $config = [], bool $withService = true): ListObjectStore {
		$service = $this->objectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($service, $withService): object {
				if ($withService === true && $name === 'OCA\OpenRegister\Service\ObjectService') {
					return $service;
				}

				throw new RuntimeException('not installed');
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new ListObjectStore($container, $appConfig, $logger);
	}//end store()

	/**
	 * A configured schema slug wins over the default.
	 *
	 * @return void
	 */
	public function testSchemaSlugPrefersTheConfiguredOne(): void {
		$this->assertSame('mailing-list', $this->store(['list_schema' => 'mailing-list'])->schemaSlug('list_schema', 'list'));
		$this->assertSame('list', $this->store()->schemaSlug('list_schema', 'list'));
	}//end testSchemaSlugPrefersTheConfiguredOne()

	/**
	 * A record is read from the configured register, unscoped, as an array.
	 *
	 * @return void
	 */
	public function testFindReadsUnscopedFromTheRegister(): void {
		$found = $this->store(['register' => 'crm'])->find('list', 'l-1');

		$this->assertSame(['id' => 'l-1', 'name' => 'Nieuwsbrief'], $found);
		$this->assertSame(
			['find', ['id' => 'l-1', 'register' => 'crm', 'schema' => 'list', '_rbac' => false, '_multitenancy' => false]],
			$this->calls[0]
		);
	}//end testFindReadsUnscopedFromTheRegister()

	/**
	 * Without a configured register the pipelinq register is used.
	 *
	 * @return void
	 */
	public function testTheRegisterDefaultsToPipelinq(): void {
		$this->store()->find('list', 'l-1');
		$this->assertSame('pipelinq', $this->calls[0][1]['register']);
	}//end testTheRegisterDefaultsToPipelinq()

	/**
	 * A blank id or schema, a missing record or a failed lookup is null; only
	 * the failure is logged.
	 *
	 * @return void
	 */
	public function testFindMissesAreNull(): void {
		$store = $this->store();

		$this->assertNull($store->find('list', ''));
		$this->assertNull($store->find('', 'l-1'));
		$this->assertSame([], $this->calls);
		$this->assertNull($store->find('list', 'missing'));
		$this->assertSame([], $this->logged);

		$this->failure = new RuntimeException('gone');
		$this->assertNull($store->find('list', 'l-2'));
		$this->assertSame(['info', 'ListObjectStore.find: not found', ['schema' => 'list', 'id' => 'l-2', 'exception' => 'gone']], $this->logged[0]);
	}//end testFindMissesAreNull()

	/**
	 * findAll scopes the query to the register and schema, then keeps only rows
	 * that match every filter exactly (as strings) and are not empty.
	 *
	 * @return void
	 */
	public function testFindAllKeepsOnlyExactMatches(): void {
		$this->rows = [
			['id' => 'a', 'list' => 'l-1', 'status' => 'subscribed'],
			['id' => 'b', 'list' => 'l-1', 'status' => 'unsubscribed'],
			['id' => 'c', 'list' => 'l-10', 'status' => 'subscribed'],
			[],
			['id' => 'd', 'list' => 'l-1', 'status' => 'subscribed'],
		];

		$rows = $this->store()->findAll('subscription', ['list' => 'l-1', 'status' => 'subscribed']);

		$this->assertSame(['a', 'd'], array_column($rows, 'id'));
		$this->assertSame(
			['filters' => ['list' => 'l-1', 'status' => 'subscribed', 'register' => 'pipelinq', 'schema' => 'subscription']],
			$this->calls[0][1]['config']
		);
		$this->assertFalse($this->calls[0][1]['_rbac']);
		$this->assertFalse($this->calls[0][1]['_multitenancy']);
	}//end testFindAllKeepsOnlyExactMatches()

	/**
	 * A failed query is logged with the filter names only and answers no rows.
	 *
	 * @return void
	 */
	public function testAFailedQueryIsEmpty(): void {
		$this->failure = new RuntimeException('db down');

		$this->assertSame([], $this->store()->findAll('subscription', ['email' => 'a@example.org']));
		$this->assertSame(
			['warning', 'ListObjectStore.findAll: query failed', ['schema' => 'subscription', 'filters' => ['email'], 'exception' => 'db down']],
			$this->logged[0]
		);
	}//end testAFailedQueryIsEmpty()

	/**
	 * Saving writes unscoped under the given id; an empty id creates a new record.
	 *
	 * @return void
	 */
	public function testSaveWritesUnscoped(): void {
		$store = $this->store();

		$this->assertSame(['name' => 'X', 'id' => 'l-1'], $store->save('list', ['name' => 'X'], 'l-1'));
		$this->assertSame(['name' => 'Y', 'id' => 'new-id'], $store->save('list', ['name' => 'Y'], ''));
		$this->assertSame(
			['object' => ['name' => 'X'], 'register' => 'pipelinq', 'schema' => 'list', 'uuid' => 'l-1', '_rbac' => false, '_multitenancy' => false],
			$this->calls[0][1]
		);
		$this->assertNull($this->calls[1][1]['uuid']);
	}//end testSaveWritesUnscoped()

	/**
	 * Delete removes by uuid unscoped; a blank id deletes nothing.
	 *
	 * @return void
	 */
	public function testDeleteRemovesByUuid(): void {
		$store = $this->store();

		$this->assertTrue($store->delete('list', 'l-1'));
		$this->assertFalse($store->delete('list', ''));
		$this->assertSame(
			[['deleteObject', ['uuid' => 'l-1', 'register' => 'pipelinq', 'schema' => 'list', '_rbac' => false, '_multitenancy' => false]]],
			$this->calls
		);
	}//end testDeleteRemovesByUuid()

	/**
	 * Without OpenRegister, and on write or delete failures, every call is soft
	 * and logged.
	 *
	 * @return void
	 */
	public function testFailuresAreSoft(): void {
		$absent = $this->store(withService: false);
		$this->assertNull($absent->find('list', 'l-1'));
		$this->assertSame([], $absent->findAll('list'));
		$this->assertNull($absent->save('list', []));
		$this->assertFalse($absent->delete('list', 'l-1'));
		$this->assertSame('ListObjectStore.context: OpenRegister unavailable', $this->logged[0][1]);

		$this->logged = [];
		$this->failure = new RuntimeException('disk full');
		$store = $this->store();
		$this->assertNull($store->save('list', ['name' => 'X'], 'l-1'));
		$this->assertFalse($store->delete('list', 'l-1'));
		$this->assertSame(
			[
				['warning', 'ListObjectStore.save: write failed', ['schema' => 'list', 'id' => 'l-1', 'exception' => 'disk full']],
				['warning', 'ListObjectStore.delete: delete failed', ['schema' => 'list', 'id' => 'l-1', 'exception' => 'disk full']],
			],
			$this->logged
		);
	}//end testFailuresAreSoft()

	/**
	 * idOf reads uuid, id, then slug, then the same under @self.
	 *
	 * @return void
	 */
	public function testIdOf(): void {
		$store = $this->store();

		$this->assertSame('u', $store->idOf(['uuid' => 'u', 'id' => 'i']));
		$this->assertSame('i', $store->idOf(['uuid' => '', 'id' => 'i']));
		$this->assertSame('7', $store->idOf(['id' => 7]));
		$this->assertSame('s', $store->idOf(['slug' => 's']));
		$this->assertSame('x', $store->idOf(['id' => ['nested'], '@self' => ['id' => 'x']]));
		$this->assertSame('', $store->idOf(['@self' => 'flat']));
		$this->assertSame('', $store->idOf(null));
	}//end testIdOf()
}//end class
