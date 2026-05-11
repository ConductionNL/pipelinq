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
class ScheduledTaskServiceTest extends TestCase
{

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
     * Build a fresh stub ObjectService double for each test.
     *
     * @return object Stub object exposing the methods we exercise.
     */
    private function makeObjectServiceStub(): object
    {
        return new class {

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
             * @param  array<string, mixed> $config
             * @return array<int, mixed>
             */
            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                return $this->findAllReturn;
            }//end findAll()

            /**
             * @param  array<string, mixed>|object $object
             * @param  array<int|string, mixed>    $extend
             * @return mixed
             */
            public function saveObject(
                array | object $object,
                ?array $extend=[],
                $register=null,
                $schema=null,
                ?string $uuid=null,
                bool $_rbac=true,
                bool $_multitenancy=true,
                bool $silent=false,
                ?array $uploadedFiles=null
            ) {
                $this->lastSaveArgs = [
                    'object'   => $object,
                    'register' => $register,
                    'schema'   => $schema,
                    'uuid'     => $uuid,
                ];

                if ($this->saveReturn === []) {
                    return $object;
                }

                return $this->saveReturn;
            }//end saveObject()

            /**
             * @return mixed
             */
            public function findObject(
                string $id,
                $register,
                $schema,
                bool $_rbac=true,
                bool $_multitenancy=true
            ) {
                return null;
            }//end findObject()

            public function deleteObject(string $id, bool $_rbac=true, bool $_multitenancy=true): bool
            {
                return true;
            }//end deleteObject()
        };
    }//end makeObjectServiceStub()

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
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
    private function makeService(): ScheduledTaskService
    {
        return new ScheduledTaskService(
            $this->appConfig,
            $this->userSession,
            $this->groupManager,
            $this->notificationService,
            $this->container,
            $this->logger,
        );
    }//end makeService()

    /**
     * createdBy must always be set from session, never from request body.
     *
     * @return void
     */
    public function testCreateSetsCreatedByFromSessionNotPayload(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $stub = $this->makeObjectServiceStub();
        $this->container->method('get')->willReturn($stub);

        $service = $this->makeService();

        $result = $service->createScheduledTask(
                [
                    'type'      => 'terugbelverzoek',
                    'subject'   => 'Bel terug',
                    'deadline'  => '2099-01-01T10:00:00+00:00',
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
    public function testCreateRejectsMissingSubject(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->createScheduledTask(
                [
                    'type'     => 'terugbelverzoek',
                    'deadline' => '2099-01-01T10:00:00+00:00',
                ]
                );
    }//end testCreateRejectsMissingSubject()

    /**
     * Invalid type must throw InvalidArgumentException.
     *
     * @return void
     */
    public function testCreateRejectsInvalidType(): void
    {
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->createScheduledTask(
                [
                    'type'     => 'unknown',
                    'subject'  => 'X',
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
    public function testGetPendingTasksReturnsItems(): void
    {
        $stub = $this->makeObjectServiceStub();
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
    public function testAuthorizeAllowsAssignee(): void
    {
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
    public function testAuthorizeAllowsAdmin(): void
    {
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
    public function testAuthorizeRejectsUnrelatedUser(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $service = $this->makeService();

        $this->expectException(OCSForbiddenException::class);
        $service->authorizeTaskMutation(
            [
                'assigneeUserId'  => 'bob',
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
    public function testAuthorizeAllowsGroupMember(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')
            ->with('dave', 'team-a')
            ->willReturn(true);

        $service = $this->makeService();
        $service->authorizeTaskMutation(
            [
                'assigneeUserId'  => 'someone-else',
                'assigneeGroupId' => 'team-a',
            ],
            'dave'
        );
        $this->addToAssertionCount(1);
    }//end testAuthorizeAllowsGroupMember()
}//end class
