<?php

/**
 * Unit tests for NoteEventService.
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
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for NoteEventService.
 */
class NoteEventServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var NoteEventService
     */
    private NoteEventService $service;

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
        $notificationService = $this->createMock(NotificationService::class);
        $activityService     = $this->createMock(ActivityService::class);
        $settingsService     = $this->createMock(SettingsService::class);
        $userSession         = $this->createMock(IUserSession::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->service = new NoteEventService(
            $notificationService,
            $activityService,
            $settingsService,
            $userSession,
            $this->logger,
        );
    }//end setUp()

    /**
     * Test triggerNoteEvents skips unknown object type.
     *
     * @return void
     */
    public function testTriggerSkipsUnknownObjectType(): void
    {
        // Should not throw; unknown type just returns early.
        $this->logger->expects($this->never())->method('warning');

        $this->service->triggerNoteEvents('unknown_type', '123');
    }//end testTriggerSkipsUnknownObjectType()

    /**
     * Test type map contains expected types.
     *
     * Each known pipelinq_* objectType should be handled without throwing
     * and without logging a warning — fetchEntityData returns null when
     * register/schema settings are empty (the default in this test), and
     * triggerNoteEvents returns early on null entity data.
     *
     * @return void
     */
    public function testTypeMapContainsExpectedTypes(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $this->service->triggerNoteEvents(objectType: 'pipelinq_client', objectId: '123');
        $this->service->triggerNoteEvents(objectType: 'pipelinq_contact', objectId: '123');
        $this->service->triggerNoteEvents(objectType: 'pipelinq_lead', objectId: '123');
        $this->service->triggerNoteEvents(objectType: 'pipelinq_request', objectId: '123');
    }//end testTypeMapContainsExpectedTypes()
}//end class
