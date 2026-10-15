<?php

/**
 * Unit tests for SystemObjectStore.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use ArrayObject;
use JsonSerializable;
use OCA\Pipelinq\Service\SystemObjectStore;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every call runs as the system account with access checks on; a failure answers the fallback.
 *
 * @covers \OCA\Pipelinq\Service\SystemObjectStore
 * @uses   \OCA\Pipelinq\Service\SystemServiceAccount
 */
class SystemObjectStoreTest extends TestCase {

	/**
	 * OpenRegister's object service, in memory.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $objects;

	/**
	 * The user id the session holds right now, or null.
	 *
	 * @var string|null
	 */
	private ?string $active = 'visitor';

	/**
	 * The register app config names.
	 *
	 * @var string
	 */
	private string $register = '';

	/**
	 * Logged warnings.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Fresh in-memory OpenRegister, a signed-in visitor.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryObjectService();
		$this->objects->actor = fn (): ?string => $this->active;
	}//end setUp()

	/**
	 * Find, findAll and save run as pipelinq-system with `_rbac` on and `_multitenancy` off; the visitor comes back.
	 *
	 * @return void
	 */
	public function testEveryCallRunsAsTheSystemAccountWithAccessChecksOn(): void {
		$store = $this->build();
		$saved = $store->save(schemaSlug: 'contact', payload: ['email' => 'a@example.nl', '@self' => ['id' => 'x']]);
		$store->find(schemaSlug: 'contact', id: $store->idOf(payload: $saved));
		$store->findAll(schemaSlug: 'contact', filters: ['email' => 'a@example.nl']);

		$this->assertCount(3, $this->objects->calls);
		foreach ($this->objects->calls as $call) {
			$this->assertSame(SystemServiceAccount::USER_ID, $call['actor']);
			$this->assertTrue($call['rbac']);
			$this->assertFalse($call['multitenancy']);
			$this->assertSame('contact', $call['schema']);
		}

		$this->assertSame('visitor', $this->active);
		$this->assertArrayNotHasKey('@self', $this->objects->ofSchema('contact')[0]);
	}//end testEveryCallRunsAsTheSystemAccountWithAccessChecksOn()

	/**
	 * Save creates without an id, replaces with one, and treats an empty id as none.
	 *
	 * @return void
	 */
	public function testSaveCreatesOrReplaces(): void {
		$store = $this->build();
		$created = $store->save(schemaSlug: 'client', payload: ['name' => 'Gemeente Zuiddrecht'], id: '');
		$id = $store->idOf(payload: $created);
		$this->assertNotSame('', $id);

		$store->save(schemaSlug: 'client', payload: ['name' => 'Gemeente Zuiddrecht BV'], id: $id);
		$this->assertSame(['name' => 'Gemeente Zuiddrecht BV', 'uuid' => $id], $store->find(schemaSlug: 'client', id: $id));
	}//end testSaveCreatesOrReplaces()

	/**
	 * FindAll keeps only rows whose fields equal every filter, even when OpenRegister ignores one.
	 *
	 * @return void
	 */
	public function testFindAllRechecksEveryFilter(): void {
		$this->objects->put('contact', ['uuid' => 'c1', 'email' => 'a@example.nl']);
		$this->objects->put('contact', ['uuid' => 'c2', 'email' => 'b@example.nl']);
		$store = $this->build(service: new class($this->objects) {
			/**
			 * @param InMemoryObjectService $inner The rows.
			 */
			public function __construct(private InMemoryObjectService $inner) {
			}

			/**
			 * Ignores every filter, as OpenRegister does with a key it does not know.
			 *
			 * @param array<string, mixed> $config The query.
			 * @param bool                 $_rbac  Access checks.
			 * @param bool                 $_multitenancy Organisation scope.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$rows = $this->inner->findAll(config: ['filters' => ['schema' => 'contact']]);
				$rows[] = new class(['uuid' => 'c3', 'email' => 'a@example.nl']) implements JsonSerializable {
					/**
					 * @param array<string, mixed> $row The row.
					 */
					public function __construct(private array $row) {
					}

					/**
					 * @return array<string, mixed>
					 */
					public function jsonSerialize(): array {
						return $this->row;
					}
				};
				$rows[] = 'not a row';
				return $rows;
			}
		});

		$rows = $store->findAll(schemaSlug: 'contact', filters: ['email' => 'a@example.nl']);
		$this->assertSame(['c1', 'c3'], array_column($rows, 'uuid'));
	}//end testFindAllRechecksEveryFilter()

	/**
	 * An empty schema or id asks OpenRegister nothing.
	 *
	 * @return void
	 */
	public function testAnEmptySchemaOrIdAsksNothing(): void {
		$store = $this->build();
		$this->assertNull($store->find(schemaSlug: ' ', id: 'c1'));
		$this->assertNull($store->find(schemaSlug: 'contact', id: ''));
		$this->assertSame([], $store->findAll(schemaSlug: '', filters: []));
		$this->assertNull($store->save(schemaSlug: '', payload: ['name' => 'x']));
		$this->assertSame([], $this->objects->calls);
	}//end testAnEmptySchemaOrIdAsksNothing()

	/**
	 * A missing object is null; an object that serialises to nothing is an empty array.
	 *
	 * @return void
	 */
	public function testAMissingOrUnreadableObject(): void {
		$store = $this->build();
		$this->assertNull($store->find(schemaSlug: 'contact', id: 'absent'));

		$opaque = $this->build(service: new class {
			/**
			 * @return object An entity that serialises to a scalar.
			 */
			public function find(string $id, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): object {
				return new class implements JsonSerializable {
					/**
					 * @return string Not an array.
					 */
					public function jsonSerialize(): string {
						return 'scalar';
					}
				};
			}
		});
		$this->assertSame([], $opaque->find(schemaSlug: 'contact', id: 'c1'));
	}//end testAMissingOrUnreadableObject()

	/**
	 * Without OpenRegister, or when a call throws, the fallback comes back, a warning is logged and the visitor is restored.
	 *
	 * @return void
	 */
	public function testAFailureAnswersTheFallbackAndRestoresTheVisitor(): void {
		$throwing = $this->build(service: new class {
			/**
			 * @return never
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				throw new RuntimeException('refused');
			}
		});
		$this->assertNull($throwing->save(schemaSlug: 'contact', payload: ['email' => 'a@example.nl']));
		$this->assertSame('visitor', $this->active);

		$absent = $this->build(service: null);
		$this->assertNull($absent->find(schemaSlug: 'contact', id: 'c1'));
		$this->assertSame([], $absent->findAll(schemaSlug: 'contact', filters: []));

		$this->assertCount(3, $this->warnings);
		$this->assertSame(['operation' => 'save', 'schema' => 'contact', 'exception' => 'refused'], $this->warnings[0]);
	}//end testAFailureAnswersTheFallbackAndRestoresTheVisitor()

	/**
	 * The register comes from app config, and defaults to pipelinq.
	 *
	 * @return void
	 */
	public function testTheRegisterComesFromAppConfig(): void {
		$registers = new ArrayObject();
		$service = new class($registers) {
			/**
			 * @param ArrayObject<int, mixed> $registers Collects the register of every call.
			 */
			public function __construct(private ArrayObject $registers) {
			}

			/**
			 * @return null
			 */
			public function find(string $id, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$this->registers[] = $register;
				return null;
			}
		};

		$this->build(service: $service)->find(schemaSlug: 'contact', id: 'c1');
		$this->register = 'crm';
		$this->build(service: $service)->find(schemaSlug: 'contact', id: 'c1');
		$this->assertSame(['pipelinq', 'crm'], $registers->getArrayCopy());
	}//end testTheRegisterComesFromAppConfig()

	/**
	 * The id comes from id or uuid, on the object or in its `@self`.
	 *
	 * @return void
	 */
	public function testIdOf(): void {
		$store = $this->build();
		$this->assertSame('a', $store->idOf(payload: ['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', $store->idOf(payload: ['uuid' => 'b']));
		$this->assertSame('c', $store->idOf(payload: ['@self' => ['uuid' => 'c']]));
		$this->assertSame('', $store->idOf(payload: ['id' => '', '@self' => 'x']));
		$this->assertSame('', $store->idOf(payload: null));
	}//end testIdOf()

	/**
	 * A store over the given object service; the default is the in-memory one, null means OpenRegister is absent.
	 *
	 * @param object|null|false $service The object service, null for none, false for the in-memory one.
	 *
	 * @return SystemObjectStore
	 */
	private function build(object|null|false $service=false): SystemObjectStore {
		if ($service === false) {
			$service = $this->objects;
		}

		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		} else {
			$container->method('get')->willReturn($service);
		}

		$system = $this->createMock(IUser::class);
		$system->method('getUID')->willReturn(SystemServiceAccount::USER_ID);
		$visitor = $this->createMock(IUser::class);
		$visitor->method('getUID')->willReturn('visitor');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($system);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->active === 'visitor' ? $visitor : null);
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->active = $user?->getUID();
		});

		$account = new SystemServiceAccount(userManager: $users, userSession: $session, random: $this->createMock(ISecureRandom::class));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default): string => $key === 'register' ? $this->register : $default);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
			$this->warnings[] = $context;
		});

		return new SystemObjectStore(container: $container, appConfig: $appConfig, account: $account, logger: $logger);
	}//end build()
}//end class
