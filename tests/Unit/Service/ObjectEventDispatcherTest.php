<?php

/**
 * Unit tests for ObjectEventDispatcher.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ActivityService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\ObjectEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ObjectEventDispatcher.
 */
class ObjectEventDispatcherTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ObjectEventDispatcher
     */
    private ObjectEventDispatcher $dispatcher;

    /**
     * Mock notification service.
     *
     * @var NotificationService
     */
    private NotificationService $notifyService;

    /**
     * Mock activity service.
     *
     * @var ActivityService
     */
    private ActivityService $activityService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->notifyService   = $this->createMock(NotificationService::class);
        $this->activityService = $this->createMock(ActivityService::class);
        $userSession           = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $userSession->method('getUser')->willReturn($user);

        $this->dispatcher = new ObjectEventDispatcher(
            $this->notifyService,
            $this->activityService,
            $userSession,
        );
    }//end setUp()

    /**
     * Test dispatchCreated publishes activity and notifies.
     *
     * @return void
     */
    public function testDispatchCreatedPublishesAndNotifies(): void
    {
        $this->activityService->expects($this->once())->method('publishCreated');
        $this->notifyService->expects($this->once())->method('notifyAssignment');

        $this->dispatcher->dispatchCreated('lead', 'Deal', '123', 'user2');
    }//end testDispatchCreatedPublishesAndNotifies()

    /**
     * Test dispatchCreated with empty assignee skips notification.
     *
     * @return void
     */
    public function testDispatchCreatedNoNotifyOnEmptyAssignee(): void
    {
        $this->activityService->expects($this->once())->method('publishCreated');
        $this->notifyService->expects($this->never())->method('notifyAssignment');

        $this->dispatcher->dispatchCreated('lead', 'Deal', '123', '');
    }//end testDispatchCreatedNoNotifyOnEmptyAssignee()

    /**
     * Test dispatchStageChange dispatches to both services.
     *
     * @return void
     */
    public function testDispatchStageChange(): void
    {
        $this->activityService->expects($this->once())->method('publishStageChanged');
        $this->notifyService->expects($this->once())->method('notifyStageChange');

        $this->dispatcher->dispatchStageChange('Deal', '123', 'Won', 'user2');
    }//end testDispatchStageChange()

    /**
     * Test dispatchStatusChange dispatches to both services.
     *
     * @return void
     */
    public function testDispatchStatusChange(): void
    {
        $this->activityService->expects($this->once())->method('publishStatusChanged');
        $this->notifyService->expects($this->once())->method('notifyStatusChange');

        $this->dispatcher->dispatchStatusChange('Request', '456', 'completed', 'user2');
    }//end testDispatchStatusChange()

    /**
     * Test dispatchDealWon dispatches to both services.
     *
     * @return void
     */
    public function testDispatchDealWon(): void
    {
        $this->activityService->expects($this->once())->method('publishDealWon');
        $this->notifyService->expects($this->once())->method('notifyDealWon');

        $this->dispatcher->dispatchDealWon('Deal', '50000', '123', 'user2');
    }//end testDispatchDealWon()

    /**
     * Test dispatchDealLost dispatches to both services.
     *
     * @return void
     */
    public function testDispatchDealLost(): void
    {
        $this->activityService->expects($this->once())->method('publishDealLost');
        $this->notifyService->expects($this->once())->method('notifyDealLost');

        $this->dispatcher->dispatchDealLost('Deal', '123', 'user2');
    }//end testDispatchDealLost()
}//end class
