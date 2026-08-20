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
use OCA\OpenRegister\Service\LanguageService;
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
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The recording LanguageService double injected into the service.
	 *
	 * @var RecordingLanguageService
	 */
	private RecordingLanguageService $languageService;

	/**
	 * Build a fresh recording ObjectService double for each test.
	 *
	 * The double extends OpenRegister's ObjectService (ADR-084) so it satisfies
	 * the `ObjectServiceInterface` constructor type-hint, and every override
	 * repeats the parent signature EXACTLY — PHP checks declaration
	 * compatibility at class-load time, so a narrowed override is a fatal that
	 * kills the run before the first test.
	 *
	 * @return RecordingObjectService The recording double.
	 */
	private function makeObjectServiceStub(): RecordingObjectService {
		return new RecordingObjectService();
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
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->languageService = new RecordingLanguageService();

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
	 * Wire the service under test with NAMED arguments, so a future parameter
	 * insertion cannot silently shift the wiring.
	 *
	 * @param ObjectServiceInterface|null $objectService The OR double; a fresh
	 *                                                   recording stub when null.
	 *
	 * @return ScheduledTaskService The service under test.
	 */
	private function makeService(?ObjectServiceInterface $objectService = null): ScheduledTaskService {
		return new ScheduledTaskService(
			appConfig: $this->appConfig,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			notificationService: $this->notificationService,
			logger: $this->logger,
			languageService: $this->languageService,
			objectService: ($objectService ?? $this->makeObjectServiceStub()),
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

		$stub = $this->makeObjectServiceStub();

		$service = $this->makeService(objectService: $stub);

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
		$stub = $this->makeObjectServiceStub();
		$stub->findAllReturn = [
			['id' => '1', 'status' => 'open', 'subject' => 'A'],
			['id' => '2', 'status' => 'open', 'subject' => 'B'],
		];

		$service = $this->makeService(objectService: $stub);

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

		$overdueTask = [
			'id' => 'task-overdue-1',
			'status' => 'open',
			'subject' => 'Overdue task',
			'deadline' => $pastDeadline,
			'attempts' => [],
		];

		// findAll is called twice: once for getOverdueTasks (returns our task),
		// once for getPendingTasks (returns empty, all future). getOverdueTasks
		// uses a '<' deadline filter; getPendingTasks uses '>='/'<='.
		$stub2 = $this->makeObjectServiceStub();
		$stub2->findAllCallback = static function (array $config) use ($overdueTask): array {
			$deadlineFilter = ($config['filters']['deadline'] ?? []);
			if (isset($deadlineFilter['<']) === true) {
				return [$overdueTask];
			}

			return [];
		};

		$service = $this->makeService(objectService: $stub2);
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

		$stub2 = $this->makeObjectServiceStub();
		$stub2->findAllCallback = static function (array $config) use ($overdueTask): array {
			$deadlineFilter = ($config['filters']['deadline'] ?? []);
			if (isset($deadlineFilter['<']) === true) {
				return [$overdueTask];
			}

			return [];
		};

		// The assignee MUST be escalated exactly once with the expiry details.
		$this->notificationService->expects($this->once())
			->method('notifyTaskExpired')
			->with('Overdue with assignee', 'alice', 'task-overdue-2', $pastDeadline);

		$service = $this->makeService(objectService: $stub2);
		$service->processScheduledTasks();

		$this->assertSame('expired', $stub2->lastSaveArgs['object']['status']);
	}//end testExpiredTaskEscalatesToAssignee()

	/**
	 * applyAcceptLanguage no-ops on an empty header (LanguageService is never touched).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function testApplyAcceptLanguageNoOpsOnEmptyHeader(): void {
		$service = $this->makeService();
		$service->applyAcceptLanguage(acceptLanguage: '');

		$this->assertSame(0, $this->languageService->callCount);
		$this->assertNull($this->languageService->capturedLanguage);
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
		$service = $this->makeService();
		$service->applyAcceptLanguage(acceptLanguage: 'nl-NL,nl;q=0.9,en;q=0.8');

		$this->assertSame('nl-NL', $this->languageService->capturedLanguage);
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
		$service = $this->makeService();
		$service->applyAcceptLanguage(acceptLanguage: 'en-US;q=0.9');

		$this->assertSame('en-US', $this->languageService->capturedLanguage);
	}//end testApplyAcceptLanguageStripsQWeightFromSingleEntry()

	/**
	 * applyAcceptLanguage silently swallows exceptions from the LanguageService.
	 *
	 * OR's LanguageService may not be usable on environments that do not have
	 * OpenRegister fully wired. The controller must not 500.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function testApplyAcceptLanguageSwallowsContainerException(): void {
		$this->languageService->throwOnSet = true;
		$this->logger->expects($this->once())->method('debug');

		$service = $this->makeService();
		// Must not throw.
		$service->applyAcceptLanguage(acceptLanguage: 'nl');
	}//end testApplyAcceptLanguageSwallowsContainerException()
}//end class

/**
 * A recording ObjectService double for the ScheduledTaskService tests.
 *
 * Extends OpenRegister's ObjectService (ADR-084) so it satisfies the
 * `ObjectServiceInterface` constructor type-hint; every override repeats the
 * parent signature EXACTLY because PHP checks declaration compatibility at
 * class-load time.
 */
class RecordingObjectService extends ObjectService {
	/**
	 * The arguments of the most recent saveObject() call.
	 *
	 * @var array<string, mixed>
	 */
	public array $lastSaveArgs = [];

	/**
	 * Rows findAll() returns when no callback is configured.
	 *
	 * @var array<int, mixed>
	 */
	public array $findAllReturn = [];

	/**
	 * Optional per-call findAll() behaviour, receiving the query config.
	 *
	 * @var callable|null
	 */
	public $findAllCallback = null;

	/**
	 * Whether deleteObject() was called.
	 *
	 * @var bool
	 */
	public bool $deleted = false;

	/**
	 * @param array<string, mixed> $config        The query configuration.
	 * @param bool                 $_rbac         Whether to enforce RBAC checks.
	 * @param bool                 $_multitenancy Whether to enforce tenant scoping.
	 *
	 * @return array<int, mixed> The matching rows.
	 */
	public function findAll(
		array $config = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		if ($this->findAllCallback !== null) {
			return ($this->findAllCallback)($config);
		}

		return $this->findAllReturn;
	}//end findAll()

	/**
	 * @param array<string, mixed>      $object        The data to persist.
	 * @param array<string, mixed>|null $extend        Additional field values.
	 * @param string|int|null           $register      Register slug or ID.
	 * @param string|int|null           $schema        Schema slug or ID.
	 * @param string|null               $uuid          UUID for update; null for create.
	 * @param bool                      $_rbac         Whether to enforce RBAC checks.
	 * @param bool                      $_multitenancy Whether to enforce tenant scoping.
	 * @param bool                      $silent        Whether to suppress side-effects.
	 * @param bool                      $_validation   Whether to validate against the schema.
	 * @param array<string, mixed>|null $uploadedFiles Files to attach.
	 * @param IUser|null                $currentUser   Acting user for folder access.
	 * @param bool                      $failIfExists  Whether a duplicate is an error.
	 *
	 * @return ObjectEntityInterface The stored object.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $silent = false,
		bool $_validation = true,
		?array $uploadedFiles = null,
		?IUser $currentUser = null,
		bool $failIfExists = false,
	): ObjectEntityInterface {
		$this->lastSaveArgs = [
			'object' => $object,
			'register' => $register,
			'schema' => $schema,
			'uuid' => $uuid,
		];

		$entity = new ObjectEntity();
		$entity->setUuid(($uuid ?? (string)($object['id'] ?? '')));
		$entity->setObject($object);

		return $entity;
	}//end saveObject()

	/**
	 * @param string          $uuid            The object UUID.
	 * @param string|int|null $register        Register slug or ID.
	 * @param string|int|null $schema          Schema slug or ID.
	 * @param bool            $_rbac           Whether to enforce RBAC checks.
	 * @param bool            $_multitenancy   Whether to enforce tenant scoping.
	 * @param bool            $_retentionSweep Whether this is a retention sweep.
	 * @param IUser|null      $currentUser     Acting user.
	 * @param bool            $permanent       Whether to delete permanently.
	 *
	 * @return bool Always true.
	 */
	public function deleteObject(
		string $uuid,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $_retentionSweep = false,
		?IUser $currentUser = null,
		bool $permanent = false,
	): bool {
		$this->deleted = true;

		return true;
	}//end deleteObject()
}//end class

/**
 * A recording LanguageService double.
 *
 * The service now takes an INJECTED `LanguageService` rather than resolving one
 * through the container, so the assertion moves from "the container was asked"
 * to "this exact tag was forwarded" — a strictly stronger check.
 */
class RecordingLanguageService extends LanguageService {
	/**
	 * The last language forwarded, or null when none was.
	 *
	 * @var string|null
	 */
	public ?string $capturedLanguage = null;

	/**
	 * How many times setPreferredLanguage() was called.
	 *
	 * @var int
	 */
	public int $callCount = 0;

	/**
	 * Whether setPreferredLanguage() should throw (OR unavailable).
	 *
	 * @var bool
	 */
	public bool $throwOnSet = false;

	/**
	 * Record (or refuse) the preferred language.
	 *
	 * @param string $language The BCP-47 tag.
	 *
	 * @return void
	 */
	public function setPreferredLanguage(string $language): void {
		$this->callCount++;
		if ($this->throwOnSet === true) {
			throw new \RuntimeException('OR not installed');
		}

		$this->capturedLanguage = $language;
	}//end setPreferredLanguage()
}//end class
