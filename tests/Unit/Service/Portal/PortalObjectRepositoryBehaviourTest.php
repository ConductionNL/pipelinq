<?php

/**
 * PortalObjectRepository: register and schema resolution, read scoping, and
 * writes that run only as the portal service account.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
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

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The repository pins every read and write to the portal register and the
 * asked-for schema, reads without per-user RBAC, and writes only as a usable
 * portal service account, restoring the previous session user afterwards.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalObjectRepository
 * @uses   \OCA\Pipelinq\Service\Portal\PortalServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccount
 * @uses   \OCA\Pipelinq\Service\Portal\PortalException
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Util\RowIdentity
 */
class PortalObjectRepositoryBehaviourTest extends TestCase {

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Every user set on the session, in order (uid or null).
	 *
	 * @var array<int, string|null>
	 */
	private array $volatile = [];

	/**
	 * The OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Whether the service account is in the service group.
	 *
	 * @var bool
	 */
	private bool $inGroup = true;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'portal_register' => 'portal-reg',
			'register' => 'main-reg',
			'portalAccount_schema' => 'acc-schema',
			'crmPortalDelegation_schema' => 'del-schema',
			'portal_service_account' => 'svc',
		];
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the repository with a real service account over mocked users,
	 * groups and session.
	 *
	 * @return PortalObjectRepository The repository.
	 */
	private function repository(): PortalObjectRepository {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$service = $this->createMock(IUser::class);
		$service->method('getUID')->willReturn('svc');
		$service->method('isEnabled')->willReturn(true);
		$previous = $this->createMock(IUser::class);
		$previous->method('getUID')->willReturn('previous');

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'svc' ? $service : null));

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $group): bool => ($this->inGroup === true && $uid === 'svc' && $group === PortalServiceAccount::GROUP)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($previous);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->volatile[] = $user?->getUID();
			}
		);

		$account = new PortalServiceAccount($appConfig, $users, $groups, $session, $this->logger);

		return new PortalObjectRepository($appConfig, $this->logger, $this->objectService, $account);
	}//end repository()

	/**
	 * An object entity serialising to the given payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntityInterface The entity.
	 */
	private function entity(array $payload): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * The dedicated portal register wins over the main register.
	 *
	 * @return void
	 */
	public function testTheRegisterIsThePortalRegister(): void {
		$this->assertSame('portal-reg', $this->repository()->registerId());
	}//end testTheRegisterIsThePortalRegister()

	/**
	 * Without a portal register the main register is used.
	 *
	 * @return void
	 */
	public function testTheRegisterFallsBackToTheMainRegister(): void {
		unset($this->config['portal_register']);
		$this->assertSame('main-reg', $this->repository()->registerId());
	}//end testTheRegisterFallsBackToTheMainRegister()

	/**
	 * With neither register configured the lookup refuses.
	 *
	 * @return void
	 */
	public function testNoRegisterAtAllRefuses(): void {
		unset($this->config['portal_register'], $this->config['register']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Portal register is not configured.');
		$this->repository()->registerId();
	}//end testNoRegisterAtAllRefuses()

	/**
	 * A pinned slug reads its pinned config key; any other slug reads
	 * `<slug>_schema`.
	 *
	 * @return void
	 */
	public function testSchemaIdsResolvePinnedAndDefaultKeys(): void {
		$repository = $this->repository();
		$this->assertSame('acc-schema', $repository->schemaId('crmPortalAccount'));
		$this->assertSame('del-schema', $repository->schemaId('crmPortalDelegation'));
	}//end testSchemaIdsResolvePinnedAndDefaultKeys()

	/**
	 * An unconfigured schema refuses and names the slug.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaRefuses(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Portal schema 'crmPortalNothing' is not configured.");
		$this->repository()->schemaId('crmPortalNothing');
	}//end testAnUnconfiguredSchemaRefuses()

	/**
	 * An empty id answers null without asking OpenRegister.
	 *
	 * @return void
	 */
	public function testFindWithAnEmptyIdAsksNothing(): void {
		$this->objectService->expects($this->never())->method('find');
		$this->assertNull($this->repository()->find('crmPortalAccount', ''));
	}//end testFindWithAnEmptyIdAsksNothing()

	/**
	 * A find is pinned to the portal register and schema, skips RBAC and
	 * organisation scoping, and answers the object as an array.
	 *
	 * @return void
	 */
	public function testFindIsPinnedAndReturnsAnArray(): void {
		$this->objectService->expects($this->once())
			->method('find')
			->with('a-1', $this->anything(), $this->anything(), 'portal-reg', 'acc-schema', false, false)
			->willReturn($this->entity(['id' => 'a-1', 'email' => 'x@example.org']));

		$this->assertSame(
			['id' => 'a-1', 'email' => 'x@example.org'],
			$this->repository()->find('crmPortalAccount', 'a-1')
		);
	}//end testFindIsPinnedAndReturnsAnArray()

	/**
	 * A missing object, a failing lookup and an unconfigured schema all
	 * answer null.
	 *
	 * @return void
	 */
	public function testFindAnswersNullWhenNothingIsFound(): void {
		$this->objectService->method('find')->willReturnCallback(
			static function (string $id): ?ObjectEntityInterface {
				if ($id === 'boom') {
					throw new RuntimeException('db down');
				}

				return null;
			}
		);

		$repository = $this->repository();
		$this->assertNull($repository->find('crmPortalAccount', 'missing'));
		$this->assertNull($repository->find('crmPortalAccount', 'boom'));
		$this->assertNull($repository->find('crmPortalNothing', 'a-1'));
	}//end testFindAnswersNullWhenNothingIsFound()

	/**
	 * findAll merges the caller's filters over the pinned register and
	 * schema, skips RBAC, and turns entities and arrays into arrays.
	 *
	 * @return void
	 */
	public function testFindAllMergesFiltersAndNormalisesRows(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				['filters' => ['register' => 'portal-reg', 'schema' => 'acc-schema', 'email' => 'x@example.org']],
				false,
				false
			)
			->willReturn([$this->entity(['id' => 'a-1']), ['id' => 'a-2']]);

		$this->assertSame(
			[['id' => 'a-1'], ['id' => 'a-2']],
			$this->repository()->findAll('crmPortalAccount', ['email' => 'x@example.org'])
		);
	}//end testFindAllMergesFiltersAndNormalisesRows()

	/**
	 * A failing findAll answers an empty list and logs a warning naming the
	 * schema.
	 *
	 * @return void
	 */
	public function testAFailingFindAllAnswersEmptyAndWarns(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Pipelinq portal: findAll failed',
				['schema' => 'crmPortalAccount', 'exception' => 'db down']
			);

		$this->assertSame([], $this->repository()->findAll('crmPortalAccount'));
	}//end testAFailingFindAllAnswersEmptyAndWarns()

	/**
	 * findOneBy answers the first match, or null when there is none.
	 *
	 * @return void
	 */
	public function testFindOneByAnswersTheFirstMatchOrNull(): void {
		$this->objectService->method('findAll')->willReturnOnConsecutiveCalls(
			[['id' => 'first'], ['id' => 'second']],
			[]
		);

		$repository = $this->repository();
		$this->assertSame(['id' => 'first'], $repository->findOneBy('crmPortalAccount', ['email' => 'x']));
		$this->assertNull($repository->findOneBy('crmPortalAccount', ['email' => 'y']));
	}//end testFindOneByAnswersTheFirstMatchOrNull()

	/**
	 * A save drops a client-supplied @self, is pinned to the portal register
	 * and schema, runs as the service account and restores the previous user.
	 *
	 * @return void
	 */
	public function testASaveRunsAsTheServiceAccountAndDropsSelf(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['email' => 'x@example.org'], [], 'portal-reg', 'acc-schema', 'a-1')
			->willReturnCallback(
				function (): ObjectEntityInterface {
					// The write happens while the service account is active.
					$this->assertSame(['svc'], $this->volatile);
					return $this->entity(['id' => 'a-1', 'email' => 'x@example.org']);
				}
			);

		$saved = $this->repository()->save(
			'crmPortalAccount',
			['email' => 'x@example.org', '@self' => ['owner' => 'attacker']],
			'a-1'
		);

		$this->assertSame(['id' => 'a-1', 'email' => 'x@example.org'], $saved);
		$this->assertSame(['svc', 'previous'], $this->volatile);
	}//end testASaveRunsAsTheServiceAccountAndDropsSelf()

	/**
	 * Without a usable service account a save refuses with 503 and writes
	 * nothing.
	 *
	 * @return void
	 */
	public function testASaveWithoutAServiceAccountRefusesWith503(): void {
		$this->inGroup = false;
		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->repository()->save('crmPortalAccount', ['email' => 'x@example.org']);
			$this->fail('A save without a service account must refuse.');
		} catch (PortalException $e) {
			$this->assertSame(503, $e->getStatus());
			$this->assertSame('portalUnavailable', $e->getErrorCode());
		}

		$this->assertSame([], $this->volatile);
	}//end testASaveWithoutAServiceAccountRefusesWith503()

	/**
	 * A failing save logs the cause, refuses with a generic message, and
	 * still restores the previous user.
	 *
	 * @return void
	 */
	public function testAFailingSaveRefusesGenericallyAndRestoresTheUser(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('constraint violated'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Pipelinq portal: saveObject failed',
				['schema' => 'crmPortalAccount', 'exception' => 'constraint violated']
			);

		try {
			$this->repository()->save('crmPortalAccount', ['email' => 'x@example.org']);
			$this->fail('A failing save must refuse.');
		} catch (RuntimeException $e) {
			$this->assertNotInstanceOf(PortalException::class, $e);
			$this->assertSame('Failed to persist portal object.', $e->getMessage());
		}

		$this->assertSame(['svc', 'previous'], $this->volatile);
	}//end testAFailingSaveRefusesGenericallyAndRestoresTheUser()

	/**
	 * requireWritable passes with a usable service account and refuses with
	 * 503 without one.
	 *
	 * @return void
	 */
	public function testRequireWritableFollowsTheServiceAccount(): void {
		$this->repository()->requireWritable();

		$this->config['portal_service_account'] = '';
		$this->expectException(PortalException::class);
		$this->repository()->requireWritable();
	}//end testRequireWritableFollowsTheServiceAccount()

	/**
	 * The public idOf prefers the @self id, then the row's own id.
	 *
	 * @return void
	 */
	public function testIdOfReadsSelfThenTheRow(): void {
		$repository = $this->repository();
		$this->assertSame('self-1', $repository->idOf(['@self' => ['id' => 'self-1'], 'id' => 'row-1']));
		$this->assertSame('row-1', $repository->idOf(['id' => 'row-1']));
		$this->assertNull($repository->idOf(['name' => 'nobody']));
	}//end testIdOfReadsSelfThenTheRow()
}//end class
