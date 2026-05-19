<?php

/**
 * Unit tests for ActivityService.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ActivityService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ActivityService.
 */
class ActivityServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ActivityService
     */
    private ActivityService $service;

    /**
     * Mock activity manager.
     *
     * @var IManager
     */
    private IManager $activityManager;

    /**
     * Mock user session.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

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
        $this->activityManager = $this->createMock(IManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new ActivityService(
            $this->activityManager,
            $this->userSession,
            $this->logger,
        );
    }//end setUp()

    /**
     * Helper to set up user and event mocks.
     *
     * @return IEvent The mock event.
     */
    private function setupEventExpectation(): IEvent
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $event = $this->createMock(IEvent::class);
        $event->method('setApp')->willReturnSelf();
        $event->method('setType')->willReturnSelf();
        $event->method('setAuthor')->willReturnSelf();
        $event->method('setTimestamp')->willReturnSelf();
        $event->method('setSubject')->willReturnSelf();
        $event->method('setObject')->willReturnSelf();
        $event->method('setAffectedUser')->willReturnSelf();

        $this->activityManager->method('generateEvent')->willReturn($event);

        return $event;
    }//end setupEventExpectation()

    /**
     * Test publishCreated for lead publishes lead_created event.
     *
     * @return void
     */
    public function testPublishCreatedForLead(): void
    {
        $event = $this->setupEventExpectation();
        $event->expects($this->once())->method('setSubject')
            ->with('lead_created', $this->anything());
        $this->activityManager->expects($this->once())->method('publish');

        $this->service->publishCreated('lead', 'Test Lead', '123');
    }//end testPublishCreatedForLead()

    /**
     * Test publishCreated for request publishes request_created event.
     *
     * @return void
     */
    public function testPublishCreatedForRequest(): void
    {
        $event = $this->setupEventExpectation();
        $event->expects($this->once())->method('setSubject')
            ->with('request_created', $this->anything());

        $this->service->publishCreated('request', 'Test Request', '456');
    }//end testPublishCreatedForRequest()

    /**
     * Test publishAssigned publishes lead_assigned for lead entity type.
     *
     * @return void
     */
    public function testPublishAssignedForLead(): void
    {
        $event = $this->setupEventExpectation();
        $event->expects($this->once())->method('setSubject')
            ->with('lead_assigned', $this->anything());
        $event->expects($this->once())->method('setAffectedUser')
            ->with('user2');

        $this->service->publishAssigned('lead', 'Deal', 'user2', '789');
    }//end testPublishAssignedForLead()

    /**
     * Test publishStageChanged publishes the correct event.
     *
     * @return void
     */
    public function testPublishStageChanged(): void
    {
        $event = $this->setupEventExpectation();
        $event->expects($this->once())->method('setSubject')
            ->with('lead_stage_changed', $this->callback(function ($params) {
                return $params['title'] === 'Deal' && $params['stage'] === 'Won';
            }));

        $this->service->publishStageChanged('Deal', 'Won', '123');
    }//end testPublishStageChanged()

    /**
     * Test publishStatusChanged publishes request_status_changed.
     *
     * @return void
     */
    public function testPublishStatusChanged(): void
    {
        $event = $this->setupEventExpectation();
        $event->expects($this->once())->method('setSubject')
            ->with('request_status_changed', $this->callback(function ($params) {
                return $params['status'] === 'completed';
            }));

        $this->service->publishStatusChanged('Request', 'completed', '456');
    }//end testPublishStatusChanged()

    /**
     * Test publishNoteAdded publishes note_added event.
     *
     * @return void
     */
    public function testPublishNoteAdded(): void
    {
        $event = $this->setupEventExpectation();
        $event->expects($this->once())->method('setSubject')
            ->with('note_added', $this->anything());

        $this->service->publishNoteAdded('lead', 'Deal', '123');
    }//end testPublishNoteAdded()

    /**
     * Test publish handles exception gracefully.
     *
     * @return void
     */
    public function testPublishHandlesException(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $this->activityManager->method('generateEvent')
            ->willThrowException(new \RuntimeException('Test error'));

        $this->logger->expects($this->once())->method('error');

        // Should not throw.
        $this->service->publishCreated('lead', 'Test', '123');
    }//end testPublishHandlesException()
}//end class
