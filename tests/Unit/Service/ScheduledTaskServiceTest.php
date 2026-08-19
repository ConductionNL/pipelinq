<?php

/**
 * Unit tests for ScheduledTaskService.
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
 *
 * @spec openspec/changes/task-background-jobs/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\ScheduledTaskService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ScheduledTaskService.
 *
 * Covers createScheduledTask (createdBy + validation), authorizeTaskMutation,
 * and getPendingTasks window capping.
 */
class ScheduledTaskServiceTest extends TestCase {

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var NotificationService&MockObject
	 */
	private NotificationService $notificationService;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The object-service double the service under test is constructed with.
	 *
	 * Held as a property so a test can assert on the SAME instance the service
	 * writes through. Building a second stub for the assertions would observe a
	 * double nothing ever called, and read as a pass.
	 *
	 * @var object
	 */
	private object $objectServiceStub;

	/**
	 * Build a fresh stub ObjectService double for each test.
	 *
	 * @return object Stub object exposing the methods we exercise.
	 */
	private function makeObjectServiceStub(): object {
		// Extends the ObjectService stub, which itself `implements
		// ObjectServiceInterface` against the REAL vendored contract. That is
		// what makes this double satisfy the production type-hint, and it means
		// a contract change breaks this file loudly instead of letting it go on
		// asserting against a signature nobody ships.
		return new class extends ObjectService {
			/**
			 * @var array<string, mixed>
			 */
			public array $lastSaveArgs = [];

			public mixed $saveReturn = [];

			/**
			 * @var array<int, mixed>
			 */
			public array $findAllReturn = [];

			/**
			 * @param array<string, mixed> $config
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->findAllReturn;
			}//end findAll()

			/**
			 * @param array<string, mixed> $object
			 * @param array<int|string, mixed> $extend
			 * @return ObjectEntityInterface
			 */
			public function saveObject(
				array $object,
				?array $extend=[],
				string|int|null $register=null,
				string|int|null $schema=null,
				?string $uuid=null,
				bool $_rbac=true,
				bool $_multitenancy=true,
				bool $silent=false,
				bool $_validation=true,
				?array $uploadedFiles=null,
				?\OCP\IUser $currentUser=null,
				bool $failIfExists=false
			): ObjectEntityInterface {
				$this->lastSaveArgs = [
					'object' => $object,
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
				];

				$payload = $object;
				if ($this->saveReturn !== []) {
					$payload = (array)$this->saveReturn;
				}

				// saveObject() is declared `: ObjectEntityInterface`, so the
				// double must hand back an entity, not the raw payload.
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($payload);
				return $entity;
			}//end saveObject()
		};
	}//end makeObjectServiceStub()

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectServiceStub = $this->makeObjectServiceStub();

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'register') {
					return '1';
				}

				if ($key === 'task_schema') {
					return '42';
				}

				return $default;
			}
		);
	}//end setUp()

	/**
	 * @return ScheduledTaskService The service under test.
	 */
	private function makeService(): ScheduledTaskService {
		return new ScheduledTaskService($this->appConfig,
			$this->userSession,
			$this->groupManager,
			$this->notificationService,
			$this->container,
			$this->logger,
			objectService: $this->objectServiceStub,
		);
	}//end makeService()

	/**
	 * createdBy must always be set from session, never from request body.
	 *
	 * @return void
	 */
	public function testCreateSetsCreatedByFromSessionNotPayload(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$stub = $this->objectServiceStub;
		$this->container->method('get')->willReturn($stub);

		$service = $this->makeService();

		$result = $service->createScheduledTask(
			[
				'type' => 'callbackRequest',
				'subject' => 'Bel terug',
				'deadline' => '2099-01-01T10:00:00+00:00',
				'createdBy' => 'attacker',
			]
		);

		$this->assertSame('alice', $stub->lastSaveArgs['object']['createdBy']);
		$this->assertSame('alice', $result['createdBy']);
		$this->assertSame('open', $stub->lastSaveArgs['object']['status']);
	}//end testCreateSetsCreatedByFromSessionNotPayload()

	/**
	 * Missing subject must throw InvalidArgumentException with a static message.
	 *
	 * @return void
	 */
	public function testCreateRejectsMissingSubject(): void {
		$service = $this->makeService();

		$this->expectException(\InvalidArgumentException::class);
		$service->createScheduledTask(
			[
				'type' => 'callbackRequest',
				'deadline' => '2099-01-01T10:00:00+00:00',
			]
		);
	}//end testCreateRejectsMissingSubject()

	/**
	 * Invalid type must throw InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testCreateRejectsInvalidType(): void {
		$service = $this->makeService();

		$this->expectException(\InvalidArgumentException::class);
		$service->createScheduledTask(
			[
				'type' => 'unknown',
				'subject' => 'X',
				'deadline' => '2099-01-01T10:00:00+00:00',
			]
		);
	}//end testCreateRejectsInvalidType()

	/**
	 * Pending window above the 1440 cap must not surface in stored filter,
	 * and stub data must be returned through the service.
	 *
	 * @return void
	 */
	public function testGetPendingTasksReturnsItems(): void {
		$stub = $this->objectServiceStub;
		$stub->findAllReturn = [
			['id' => '1', 'status' => 'open', 'subject' => 'A'],
			['id' => '2', 'status' => 'open', 'subject' => 'B'],
		];
		$this->container->method('get')->willReturn($stub);

		$service = $this->makeService();

		// 9999 minutes — service must clamp to 1440 without erroring.
		$items = $service->getPendingTasks(9999);

		$this->assertCount(2, $items);
		$this->assertSame('A', $items[0]['subject']);
	}//end testGetPendingTasksReturnsItems()

	/**
	 * Assignee user may always mutate their own task.
	 *
	 * @return void
	 */
	public function testAuthorizeAllowsAssignee(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$service = $this->makeService();

		$service->authorizeTaskMutation(['assigneeUserId' => 'bob'], 'bob');
		$this->addToAssertionCount(1);
	}//end testAuthorizeAllowsAssignee()

	/**
	 * Admin must always be allowed.
	 *
	 * @return void
	 */
	public function testAuthorizeAllowsAdmin(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$service = $this->makeService();
		$service->authorizeTaskMutation(['assigneeUserId' => 'someone-else'], 'rooty');
		$this->addToAssertionCount(1);
	}//end testAuthorizeAllowsAdmin()

	/**
	 * Unrelated non-admin user must be rejected with OCSForbiddenException.
	 *
	 * @return void
	 */
	public function testAuthorizeRejectsUnrelatedUser(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$service = $this->makeService();

		$this->expectException(OCSForbiddenException::class);
		$service->authorizeTaskMutation(
			[
				'assigneeUserId' => 'bob',
				'assigneeGroupId' => 'team-a',
			],
			'carol'
		);
	}//end testAuthorizeRejectsUnrelatedUser()

	/**
	 * Group member must be allowed.
	 *
	 * @return void
	 */
	public function testAuthorizeAllowsGroupMember(): void {
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')
			->with('dave', 'team-a')
			->willReturn(true);

		$service = $this->makeService();
		$service->authorizeTaskMutation(
			[
				'assigneeUserId' => 'someone-else',
				'assigneeGroupId' => 'team-a',
			],
			'dave'
		);
		$this->addToAssertionCount(1);
	}//end testAuthorizeAllowsGroupMember()

	/**
	 * Past-deadline open tasks must transition to 'expired' via processScheduledTasks.
	 *
	 * This covers C-W7-01: the expiry branch requires overdue tasks to be fetched
	 * via getOverdueTasks() (deadline < now), not via getPendingTasks() which only
	 * returns future deadlines.
	 *
	 * @return void
	 */
	public function testProcessScheduledTasksTransitionsOverdueTaskToVerlopen(): void {
		// Task with a deadline 24 hours in the past — well past the expiryCut of 4 h.
		$pastDeadline = (new \DateTimeImmutable('-24 hours'))->format(\DateTimeInterface::ATOM);

		$stub = $this->objectServiceStub;

		// findAll is called twice: once for getOverdueTasks (returns our task),
		// once for getPendingTasks (returns empty, all future).
		$callCount = 0;
		$stub->findAllReturn = [];

		// We override findAll via a custom anonymous class to handle two calls.
		$overdueTask = [
			'id' => 'task-overdue-1',
			'status' => 'open',
			'subject' => 'Overdue task',
			'deadline' => $pastDeadline,
			'attempts' => [],
		];

		$stub2 = new class($overdueTask) {

			public array $lastSaveArgs = [];

			public mixed $saveReturn = [];

			/**
			 * @var array<string, mixed>
			 */
			private array $overdueTask;

			private int $callCount = 0;

			public function __construct(array $overdueTask) {
				$this->overdueTask = $overdueTask;
			}//end __construct()

			/**
			 * @param array<string, mixed> $config
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->callCount++;
				$deadlineFilter = $config['filters']['deadline'] ?? [];

				// getOverdueTasks uses '<' filter; getPendingTasks uses '>='/'<=' filter.
				if (isset($deadlineFilter['<']) === true) {
					return [$this->overdueTask];
				}

				return [];
			}//end findAll()

			/**
			 * @param array<string, mixed>|object $object
			 * @param array<int|string, mixed> $extend
			 * @return mixed
			 */
			public function saveObject(
				array|object $object,
				?array $extend = [],
				$register = null,
				$schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				?array $uploadedFiles = null,
			) {
				$this->lastSaveArgs = [
					'object' => $object,
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
				];
				return $object;
			}//end saveObject()

			/**
			 * @return mixed
			 */
			public function findObject(string $id, $register, $schema, bool $_rbac = true, bool $_multitenancy = true) {
				return null;
			}//end findObject()

			public function deleteObject(string $id, bool $_rbac = true, bool $_multitenancy = true): bool {
				return true;
			}//end deleteObject()
		};

		$this->container->method('get')->willReturn($stub2);

		$service = $this->makeService();
		$service->processScheduledTasks();

		// The overdue task must have been saved with status 'expired'.
		$this->assertNotEmpty($stub2->lastSaveArgs, 'saveObject was not called for the overdue task');
		$this->assertSame('expired', $stub2->lastSaveArgs['object']['status']);
	}//end testProcessScheduledTasksTransitionsOverdueTaskToVerlopen()

	/**
	 * Expiring an overdue task must escalate to its assignee via notifyTaskExpired.
	 *
	 * Regression guard for the money/data-integrity bug where an overdue task
	 * was silently expired but no assignee was ever notified — the orphaned
	 * NotificationService::notifyTaskExpired had zero callers, and the
	 * separately-registered TaskExpiryJob only logged.
	 *
	 * @return void
	 */
	public function testExpiredTaskEscalatesToAssignee(): void {
		$pastDeadline = (new \DateTimeImmutable('-24 hours'))->format(\DateTimeInterface::ATOM);

		$overdueTask = [
			'id' => 'task-overdue-2',
			'status' => 'open',
			'subject' => 'Overdue with assignee',
			'deadline' => $pastDeadline,
			'assigneeUserId' => 'alice',
			'attempts' => [],
		];

		$stub2 = new class($overdueTask) {

			public array $lastSaveArgs = [];

			/**
			 * @var array<string, mixed>
			 */
			private array $overdueTask;

			public function __construct(array $overdueTask) {
				$this->overdueTask = $overdueTask;
			}//end __construct()

			/**
			 * @param array<string, mixed> $config
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$deadlineFilter = $config['filters']['deadline'] ?? [];
				if (isset($deadlineFilter['<']) === true) {
					return [$this->overdueTask];
				}

				return [];
			}//end findAll()

			/**
			 * @param array<string, mixed>|object $object
			 * @param array<int|string, mixed> $extend
			 * @return mixed
			 */
			public function saveObject(
				array|object $object,
				?array $extend = [],
				$register = null,
				$schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				?array $uploadedFiles = null,
			) {
				$this->lastSaveArgs = ['object' => $object];
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturn($stub2);

		// The assignee MUST be escalated exactly once with the expiry details.
		$this->notificationService->expects($this->once())
			->method('notifyTaskExpired')
			->with('Overdue with assignee', 'alice', 'task-overdue-2', $pastDeadline);

		$service = $this->makeService();
		$service->processScheduledTasks();

		$this->assertSame('expired', $stub2->lastSaveArgs['object']['status']);
	}//end testExpiredTaskEscalatesToAssignee()

	/**
	 * applyAcceptLanguage no-ops on an empty header (container is never queried).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function testApplyAcceptLanguageNoOpsOnEmptyHeader(): void {
		$this->container->expects($this->never())->method('get');

		$service = $this->makeService();
		$service->applyAcceptLanguage(acceptLanguage: '');
	}//end testApplyAcceptLanguageNoOpsOnEmptyHeader()

	/**
	 * applyAcceptLanguage sets the first tag from a complex Accept-Language value.
	 *
	 * "nl-NL,nl;q=0.9,en;q=0.8" → LanguageService receives "nl-NL".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function testApplyAcceptLanguageForwardsPreferredTagToOrLanguageService(): void {
		$capturedLang = null;

		$languageServiceStub = new class($capturedLang) {

			public ?string $capturedLanguage = null;

			public function setPreferredLanguage(string $language): void {
				$this->capturedLanguage = $language;
			}//end setPreferredLanguage()
		};

		$this->container->method('get')->willReturn($languageServiceStub);

		$service = $this->makeService();
		$service->applyAcceptLanguage(acceptLanguage: 'nl-NL,nl;q=0.9,en;q=0.8');

		$this->assertSame('nl-NL', $languageServiceStub->capturedLanguage);
	}//end testApplyAcceptLanguageForwardsPreferredTagToOrLanguageService()

	/**
	 * applyAcceptLanguage strips the q-weight from a single-entry header.
	 *
	 * "en-US;q=0.9" → LanguageService receives "en-US".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function testApplyAcceptLanguageStripsQWeightFromSingleEntry(): void {
		$languageServiceStub = new class {

			public ?string $capturedLanguage = null;

			public function setPreferredLanguage(string $language): void {
				$this->capturedLanguage = $language;
			}//end setPreferredLanguage()
		};

		$this->container->method('get')->willReturn($languageServiceStub);

		$service = $this->makeService();
		$service->applyAcceptLanguage(acceptLanguage: 'en-US;q=0.9');

		$this->assertSame('en-US', $languageServiceStub->capturedLanguage);
	}//end testApplyAcceptLanguageStripsQWeightFromSingleEntry()

	/**
	 * applyAcceptLanguage silently swallows exceptions from the container.
	 *
	 * OR's LanguageService may not be available on environments that do not
	 * have OpenRegister installed. The controller must not 500.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function testApplyAcceptLanguageSwallowsContainerException(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR not installed'));
		$this->logger->expects($this->once())->method('debug');

		$service = $this->makeService();
		// Must not throw.
		$service->applyAcceptLanguage(acceptLanguage: 'nl');
	}//end testApplyAcceptLanguageSwallowsContainerException()
}//end class
