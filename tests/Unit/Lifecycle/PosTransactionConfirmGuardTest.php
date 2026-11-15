<?php

/**
 * Unit tests for PosTransactionConfirmGuard's decision ladder.
 *
 * Covers the access refusal, every fail-closed path of the non-empty-cart
 * precondition (no transaction id, no register, unresolvable line schema,
 * lookup failure) and the exact OpenRegister query the guard issues.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard
 * @uses   \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 */
class PosTransactionConfirmGuardTest extends TestCase {

	private const ACCESS_DENIAL = 'U mag deze transactie niet bevestigen. Alleen de eigen kassamedewerker, '
		. 'een lid van de POS-groep of een beheerder is gemachtigd.';

	private const EMPTY_CART = 'Voeg minimaal één artikel toe.';

	private const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * Services the container hands out, by id.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Ids requested from the container, in order.
	 *
	 * @var array<int, string>
	 */
	private array $requested = [];

	/**
	 * Build the guard over an app-config map with no admins and no POS group members.
	 *
	 * @param array<string,string> $config App-config values by key.
	 * @param LoggerInterface|null $logger Optional logger mock.
	 *
	 * @return PosTransactionConfirmGuard The guard.
	 */
	private function guard(array $config, ?LoggerInterface $logger = null): PosTransactionConfirmGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				$this->requested[] = $id;
				if (isset($this->services[$id]) === false) {
					throw new \RuntimeException('unknown service ' . $id);
				}

				return $this->services[$id];
			}
		);

		return new PosTransactionConfirmGuard(
			policy: new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager),
			container: $container,
			appConfig: $appConfig,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end guard()

	/**
	 * An ObjectService double that records findAll() configs and returns fixed rows.
	 *
	 * @param array<int,mixed> $rows The rows to return.
	 *
	 * @return object The double.
	 */
	private function objects(array $rows): object {
		return new class($rows) {
			/**
			 * Captured configs.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,mixed> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * Record and answer a query.
			 *
			 * @param array<string,mixed> $config The query config.
			 *
			 * @return array<int,mixed> The rows.
			 */
			public function findAll(array $config): array {
				$this->calls[] = $config;
				return $this->rows;
			}//end findAll()
		};
	}//end objects()

	/**
	 * A stranger is refused before any lookup happens.
	 *
	 * @return void
	 */
	public function testAStrangerIsRefusedWithoutALookup(): void {
		$guard = $this->guard(['register' => '7', 'posTransactionLine_schema' => '12']);

		$result = $guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'bob');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::ACCESS_DENIAL, $result->getMessage());
		$this->assertSame([], $this->requested);
	}//end testAStrangerIsRefusedWithoutALookup()

	/**
	 * With the line schema configured, the guard queries lines of this
	 * transaction scoped under `@self` and allows a non-empty cart.
	 *
	 * @return void
	 */
	public function testAConfiguredSchemaQueriesTheCartAndAllowsLines(): void {
		$objects = $this->objects([['id' => 'line-1']]);
		$this->services[self::OBJECT_SERVICE] = $objects;
		$guard = $this->guard(['register' => '7', 'posTransactionLine_schema' => '12']);

		$result = $guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertTrue($result->isAllowed());
		$this->assertSame([self::OBJECT_SERVICE], $this->requested);
		$this->assertSame(
			[['filters' => ['@self' => ['register' => '7', 'schema' => '12'], 'transaction' => 'tx-1']]],
			$objects->calls
		);
	}//end testAConfiguredSchemaQueriesTheCartAndAllowsLines()

	/**
	 * The uuid is used as the transaction filter when the object has no id.
	 *
	 * @return void
	 */
	public function testTheUuidIsUsedWhenThereIsNoId(): void {
		$objects = $this->objects([['id' => 'line-1']]);
		$this->services[self::OBJECT_SERVICE] = $objects;
		$guard = $this->guard(['register' => '7', 'posTransactionLine_schema' => '12']);

		$this->assertTrue($guard->check(['uuid' => 'u-9', 'cashier' => 'alice'], 'confirm', 'alice')->isAllowed());
		$this->assertSame('u-9', $objects->calls[0]['filters']['transaction']);
	}//end testTheUuidIsUsedWhenThereIsNoId()

	/**
	 * An empty result set is an empty cart.
	 *
	 * @return void
	 */
	public function testNoLinesIsAnEmptyCart(): void {
		$this->services[self::OBJECT_SERVICE] = $this->objects([]);
		$guard = $this->guard(['register' => '7', 'posTransactionLine_schema' => '12']);

		$result = $guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::EMPTY_CART, $result->getMessage());
	}//end testNoLinesIsAnEmptyCart()

	/**
	 * A transaction without id or uuid fails closed without any lookup.
	 *
	 * @return void
	 */
	public function testAMissingTransactionIdFailsClosed(): void {
		$guard = $this->guard(['register' => '7', 'posTransactionLine_schema' => '12']);

		$result = $guard->check(['cashier' => 'alice'], 'confirm', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::EMPTY_CART, $result->getMessage());
		$this->assertSame([], $this->requested);
	}//end testAMissingTransactionIdFailsClosed()

	/**
	 * A blank register fails closed without querying lines.
	 *
	 * @return void
	 */
	public function testABlankRegisterFailsClosed(): void {
		$guard = $this->guard(['posTransactionLine_schema' => '12']);

		$result = $guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::EMPTY_CART, $result->getMessage());
		$this->assertNotContains(self::OBJECT_SERVICE, $this->requested);
	}//end testABlankRegisterFailsClosed()

	/**
	 * A slug lookup that yields no schema object fails closed without querying lines.
	 *
	 * @return void
	 */
	public function testASlugLookupWithoutASchemaFailsClosed(): void {
		$mapper = new class {
			/**
			 * Captured find() arguments.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Answer a lookup with no schema.
			 *
			 * @param string|int         $id            The id, uuid or slug.
			 * @param array<int, string> $_extend       Extensions.
			 * @param bool               $_rbac         RBAC flag.
			 * @param bool               $_multitenancy Multitenancy flag.
			 *
			 * @return null Never a schema.
			 */
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): ?object {
				$this->calls[] = ['id' => $id, 'extend' => $_extend, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return null;
			}//end find()
		};
		$this->services[self::SCHEMA_MAPPER] = $mapper;
		$guard = $this->guard(['register' => '7']);

		$result = $guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::EMPTY_CART, $result->getMessage());
		$this->assertSame(
			[['id' => 'posTransactionLine', 'extend' => [], 'rbac' => false, 'multitenancy' => false]],
			$mapper->calls
		);
		$this->assertSame([self::SCHEMA_MAPPER], $this->requested);
	}//end testASlugLookupWithoutASchemaFailsClosed()

	/**
	 * A slug that resolves supplies the numeric schema id to the line query.
	 *
	 * @return void
	 */
	public function testAResolvedSlugScopesTheLineQuery(): void {
		$this->services[self::SCHEMA_MAPPER] = new class {
			/**
			 * Resolve the line slug to a schema exposing id 44.
			 *
			 * @param string|int         $id            The id, uuid or slug.
			 * @param array<int, string> $_extend       Extensions.
			 * @param bool               $_rbac         RBAC flag.
			 * @param bool               $_multitenancy Multitenancy flag.
			 *
			 * @return object The schema.
			 */
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return new class {
					/**
					 * The numeric id.
					 *
					 * @return int The id.
					 */
					public function getId(): int {
						return 44;
					}//end getId()
				};
			}//end find()
		};
		$objects = $this->objects([['id' => 'line-1']]);
		$this->services[self::OBJECT_SERVICE] = $objects;
		$guard = $this->guard(['register' => '7']);

		$this->assertTrue($guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'alice')->isAllowed());
		$this->assertSame(['register' => '7', 'schema' => '44'], $objects->calls[0]['filters']['@self']);
	}//end testAResolvedSlugScopesTheLineQuery()

	/**
	 * A slug lookup that throws is logged and fails closed without querying lines.
	 *
	 * @return void
	 */
	public function testASlugLookupFailureIsLoggedAndFailsClosed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pipelinq: confirm guard could not resolve line schema slug (fail closed)',
			['slug' => 'posTransactionLine', 'exception' => 'unknown service ' . self::SCHEMA_MAPPER]
		);

		$guard = $this->guard(['register' => '7'], $logger);

		$result = $guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::EMPTY_CART, $result->getMessage());
		$this->assertSame([self::SCHEMA_MAPPER], $this->requested);
	}//end testASlugLookupFailureIsLoggedAndFailsClosed()

	/**
	 * A failing cart lookup is logged and fails closed.
	 *
	 * @return void
	 */
	public function testALookupFailureIsLoggedAndFailsClosed(): void {
		$this->services[self::OBJECT_SERVICE] = new class {
			/**
			 * Always fail.
			 *
			 * @param array<string,mixed> $config The query config.
			 *
			 * @return array<int,mixed> Never returns.
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(array $config): array {
				throw new \RuntimeException('database gone');
			}//end findAll()
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pipelinq: confirm guard could not verify cart contents (fail closed)',
			['exception' => 'database gone', 'transaction' => 'tx-1']
		);

		$guard = $this->guard(['register' => '7', 'posTransactionLine_schema' => '12'], $logger);

		$result = $guard->check(['id' => 'tx-1', 'cashier' => 'alice'], 'confirm', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertSame(self::EMPTY_CART, $result->getMessage());
	}//end testALookupFailureIsLoggedAndFailsClosed()
}//end class
