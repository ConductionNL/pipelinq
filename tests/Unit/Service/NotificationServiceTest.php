<?php

/**
 * Unit tests for NotificationService.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\NotificationService;
use OCP\IConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NotificationService.
 */
class NotificationServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var NotificationService
     */
    private NotificationService $service;

    /**
     * Mock notification manager.
     *
     * @var IManager
     */
    private IManager $notificationManager;

    /**
     * Mock config.
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Mock logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->notificationManager = $this->createMock(originalClassName: IManager::class);
        $this->config = $this->createMock(originalClassName: IConfig::class);
        $this->logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new NotificationService(
            notificationManager: $this->notificationManager,
            config: $this->config,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test notifyAssignment skips when author equals assignee.
     *
     * @return void
     */
    public function testNotifyAssignmentSkipsSelfAssignment(): void
    {
        $this->notificationManager->expects($this->never())->method('createNotification');

        $this->service->notifyAssignment(
            entityType: 'lead',
            title: 'Test Lead',
            assigneeUserId: 'admin',
            objectId: '123',
            author: 'admin'
        );
    }//end testNotifyAssignmentSkipsSelfAssignment()

    /**
     * Test notifyAssignment sends notification for lead.
     *
     * @return void
     */
    public function testNotifyAssignmentSendsForLead(): void
    {
        $notification = $this->createMock(originalClassName: INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->config->method('getUserValue')->willReturn('true');
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $notification->expects($this->once())->method('setSubject')
            ->with('lead_assigned', $this->anything());

        $this->service->notifyAssignment(
            entityType: 'lead',
            title: 'Test Lead',
            assigneeUserId: 'user2',
            objectId: '123',
            author: 'admin'
        );
    }//end testNotifyAssignmentSendsForLead()

    /**
     * Test notifyAssignment sends request_assigned for requests.
     *
     * @return void
     */
    public function testNotifyAssignmentSendsForRequest(): void
    {
        $notification = $this->createMock(originalClassName: INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->config->method('getUserValue')->willReturn('true');
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $notification->expects($this->once())->method('setSubject')
            ->with('request_assigned', $this->anything());

        $this->service->notifyAssignment(
            entityType: 'request',
            title: 'Test Request',
            assigneeUserId: 'user2',
            objectId: '456',
            author: 'admin'
        );
    }//end testNotifyAssignmentSendsForRequest()

    /**
     * Test notification is suppressed when user setting is false.
     *
     * @return void
     */
    public function testNotificationSuppressedByUserSetting(): void
    {
        $this->config->method('getUserValue')->willReturn('false');
        $this->notificationManager->expects($this->never())->method('notify');

        $this->service->notifyAssignment(
            entityType: 'lead',
            title: 'Test',
            assigneeUserId: 'user2',
            objectId: '123',
            author: 'admin'
        );
    }//end testNotificationSuppressedByUserSetting()

    /**
     * Test notifyStageChange skips self-notification.
     *
     * @return void
     */
    public function testNotifyStageChangeSkipsSelf(): void
    {
        $this->notificationManager->expects($this->never())->method('createNotification');

        $this->service->notifyStageChange(
            title: 'Test Lead',
            newStage: 'Won',
            assigneeUserId: 'admin',
            objectId: '123',
            author: 'admin'
        );
    }//end testNotifyStageChangeSkipsSelf()

    /**
     * Test notifyStatusChange skips self-notification.
     *
     * @return void
     */
    public function testNotifyStatusChangeSkipsSelf(): void
    {
        $this->notificationManager->expects($this->never())->method('createNotification');

        $this->service->notifyStatusChange(
            title: 'Test Request',
            newStatus: 'completed',
            assigneeUserId: 'admin',
            objectId: '456',
            author: 'admin'
        );
    }//end testNotifyStatusChangeSkipsSelf()

    /**
     * Test notifyNoteAdded skips self-notification.
     *
     * @return void
     */
    public function testNotifyNoteAddedSkipsSelf(): void
    {
        $this->notificationManager->expects($this->never())->method('createNotification');

        $this->service->notifyNoteAdded(
            entityType: 'lead',
            entityTitle: 'Test Lead',
            assigneeUserId: 'admin',
            objectId: '123',
            author: 'admin'
        );
    }//end testNotifyNoteAddedSkipsSelf()

    /**
     * Test notifyDealLost sends a lead_lost notification to assignee.
     *
     * @return void
     *
     * @spec openspec/changes/reverse-2026-05-26-be-activity-notify/tasks.md#task-2
     */
    public function testNotifyDealLostSendsNotification(): void
    {
        $notification = $this->createMock(originalClassName: INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->config->method('getUserValue')->willReturn('true');
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $notification->expects($this->once())->method('setSubject')
            ->with(
                'lead_lost',
                $this->callback(
                    callback: function ($params) {
                        return $params['title'] === 'Lost Deal' && $params['author'] === 'admin';
                    }
                )
            );

        $this->service->notifyDealLost(
            title: 'Lost Deal',
            assigneeUserId: 'user2',
            objectId: '42',
            author: 'admin'
        );
    }//end testNotifyDealLostSendsNotification()

    /**
     * Test sendNotification dispatches an arbitrary notification subject.
     *
     * @return void
     *
     * @spec openspec/changes/reverse-2026-05-26-be-activity-notify/tasks.md#task-3
     */
    public function testSendNotificationDispatchesArbitrarySubject(): void
    {
        $notification = $this->createMock(originalClassName: INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->expects($this->once())->method('notify');

        $notification->expects($this->once())->method('setSubject')
            ->with(
                'custom_event',
                $this->callback(
                    callback: function ($params) {
                        return $params['key'] === 'value';
                    }
                )
            );

        $this->service->sendNotification(
            userId: 'user1',
            subject: 'custom_event',
            parameters: ['key' => 'value'],
            objectType: 'lead',
            objectId: '99'
        );
    }//end testSendNotificationDispatchesArbitrarySubject()
}//end class
