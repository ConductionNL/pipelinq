<?php

/**
 * A real SystemObjectStore over an InMemoryListObjectStore, for tests whose rows live in that fake.
 *
 * The store, the system account and the access flags are the real classes;
 * only OpenRegister's object service is an adapter onto the list store, so
 * a test sees every row the intake wrote in one place. The adapter refuses a
 * call with access checks off, so a test using it fails if a write slips
 * back to `_rbac: false`.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCA\Pipelinq\Service\SystemObjectStore;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * For a PHPUnit TestCase.
 */
trait BuildsSystemObjectStore {

	/**
	 * Every call that reached OpenRegister: method, schema and the user it ran as.
	 *
	 * @var array<int, array{method: string, schema: string, actor: string|null}>
	 */
	private array $systemStoreCalls = [];

	/**
	 * The user id the session holds; null is an anonymous visitor.
	 *
	 * @var string|null
	 */
	private ?string $systemStoreActive = null;

	/**
	 * A SystemObjectStore whose OpenRegister is the given list store.
	 *
	 * @param InMemoryListObjectStore $list The rows.
	 *
	 * @return SystemObjectStore
	 */
	private function systemStoreOver(InMemoryListObjectStore $list): SystemObjectStore {
		$record = function (string $method, string $schema): void {
			$this->systemStoreCalls[] = ['method' => $method, 'schema' => $schema, 'actor' => $this->systemStoreActive];
		};
		$service = new class($list, $record) {
			/**
			 * @param InMemoryListObjectStore $list   The rows.
			 * @param \Closure                $record Records each call with its actor.
			 */
			public function __construct(private InMemoryListObjectStore $list, private \Closure $record) {
			}

			/**
			 * @param bool $_rbac Must stay on.
			 *
			 * @return void
			 */
			private function refuseOff(bool $_rbac): void {
				if ($_rbac === false) {
					throw new RuntimeException('a call with access checks off reached OpenRegister');
				}
			}

			public function find(string $id, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$this->refuseOff(_rbac: $_rbac);
				($this->record)('find', (string)$schema);
				return $this->list->find(schemaSlug: (string)$schema, id: $id);
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->refuseOff(_rbac: $_rbac);
				$filters = (array)($config['filters'] ?? []);
				$schema = (string)($filters['schema'] ?? '');
				unset($filters['register'], $filters['schema']);
				($this->record)('findAll', $schema);
				return $this->list->findAll(schemaSlug: $schema, filters: $filters);
			}

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$this->refuseOff(_rbac: $_rbac);
				($this->record)('saveObject', (string)$schema);
				return $this->list->save(schemaSlug: (string)$schema, payload: $object, id: $uuid);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		$system = $this->createMock(IUser::class);
		$system->method('getUID')->willReturn(SystemServiceAccount::USER_ID);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($system);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => null);
		$session->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $user): void {
			$this->systemStoreActive = $user?->getUID();
		});
		$account = new SystemServiceAccount(userManager: $users, userSession: $session, random: $this->createMock(ISecureRandom::class));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default) => $default);

		return new SystemObjectStore(container: $container, appConfig: $appConfig, account: $account, logger: $this->createMock(LoggerInterface::class));
	}//end systemStoreOver()
}//end trait
