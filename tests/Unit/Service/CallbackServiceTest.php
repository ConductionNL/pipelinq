<?php

/**
 * Unit tests for CallbackService.
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

use OCA\Pipelinq\Service\CallbackService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CallbackService.
 */
class CallbackServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var CallbackService
     */
    private CallbackService $service;

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Mock user.
     *
     * @var IUser&MockObject
     */
    private IUser $user;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->user         = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('agent-001');
        $this->userSession->method('getUser')->willReturn($this->user);

        $this->service = new CallbackService(
            $this->groupManager,
            $this->userSession,
            $this->logger,
        );
    }//end setUp()

    /**
     * Test that addAttempt appends an entry to the attempts array.
     *
     * @return void
     */
    public function testAddAttemptAppendsEntry(): void
    {
        $taskData = ['attempts' => []];
        $result = $this->service->addAttempt($taskData, 'niet_bereikbaar', 'Voicemail ingesproken');

        $this->assertCount(1, $result['attempts']);
        $this->assertSame('niet_bereikbaar', $result['attempts'][0]['result']);
        $this->assertSame('Voicemail ingesproken', $result['attempts'][0]['notes']);
        $this->assertSame('agent-001', $result['attempts'][0]['agentUserId']);
        $this->assertArrayHasKey('timestamp', $result['attempts'][0]);
    }//end testAddAttemptAppendsEntry()

    /**
     * Test that addAttempt preserves existing attempts.
     *
     * @return void
     */
    public function testAddAttemptPreservesExisting(): void
    {
        $taskData = [
            'attempts' => [
                ['timestamp' => '2024-01-01T10:00:00+00:00', 'result' => 'niet_bereikbaar', 'notes' => ''],
            ],
        ];
        $result = $this->service->addAttempt($taskData, 'bereikt', 'Burger gesproken');

        $this->assertCount(2, $result['attempts']);
        $this->assertSame('niet_bereikbaar', $result['attempts'][0]['result']);
        $this->assertSame('bereikt', $result['attempts'][1]['result']);
    }//end testAddAttemptPreservesExisting()

    /**
     * Test that addAttempt handles missing attempts key.
     *
     * @return void
     */
    public function testAddAttemptHandlesMissingAttemptsKey(): void
    {
        $taskData = ['subject' => 'Test'];
        $result = $this->service->addAttempt($taskData, 'niet_bereikbaar');

        $this->assertCount(1, $result['attempts']);
    }//end testAddAttemptHandlesMissingAttemptsKey()

    /**
     * Test threshold reached with 3 unsuccessful attempts.
     *
     * @return void
     */
    public function testIsAttemptThresholdReachedTrue(): void
    {
        $taskData = [
            'attempts' => [
                ['result' => 'niet_bereikbaar'],
                ['result' => 'niet_bereikt'],
                ['result' => 'geen_gehoor'],
            ],
        ];

        $this->assertTrue($this->service->isAttemptThresholdReached($taskData));
    }//end testIsAttemptThresholdReachedTrue()

    /**
     * Test threshold not reached with fewer than 3 unsuccessful attempts.
     *
     * @return void
     */
    public function testIsAttemptThresholdReachedFalse(): void
    {
        $taskData = [
            'attempts' => [
                ['result' => 'niet_bereikbaar'],
                ['result' => 'bereikt'],
                ['result' => 'niet_bereikt'],
            ],
        ];

        $this->assertFalse($this->service->isAttemptThresholdReached($taskData));
    }//end testIsAttemptThresholdReachedFalse()

    /**
     * Test threshold with empty attempts.
     *
     * @return void
     */
    public function testIsAttemptThresholdReachedEmpty(): void
    {
        $this->assertFalse($this->service->isAttemptThresholdReached(['attempts' => []]));
    }//end testIsAttemptThresholdReachedEmpty()

    /**
     * Test validateClaim eligible when user is in assigned group.
     *
     * @return void
     */
    public function testValidateClaimEligible(): void
    {
        $taskData = ['assigneeGroupId' => 'afdeling-burgerzaken', 'assigneeUserId' => null];
        $this->groupManager->method('isInGroup')->with('agent-001', 'afdeling-burgerzaken')->willReturn(true);

        $result = $this->service->validateClaim($taskData);

        $this->assertTrue($result['eligible']);
    }//end testValidateClaimEligible()

    /**
     * Test validateClaim ineligible when user is not in group.
     *
     * @return void
     */
    public function testValidateClaimNotInGroup(): void
    {
        $taskData = ['assigneeGroupId' => 'afdeling-vergunningen', 'assigneeUserId' => null];
        $this->groupManager->method('isInGroup')->with('agent-001', 'afdeling-vergunningen')->willReturn(false);

        $result = $this->service->validateClaim($taskData);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('not a member', $result['reason']);
    }//end testValidateClaimNotInGroup()

    /**
     * Test validateClaim fails when task already claimed.
     *
     * @return void
     */
    public function testValidateClaimAlreadyClaimed(): void
    {
        $taskData = ['assigneeGroupId' => 'group-1', 'assigneeUserId' => 'other-user'];

        $result = $this->service->validateClaim($taskData);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('already claimed', $result['reason']);
    }//end testValidateClaimAlreadyClaimed()

    /**
     * Test validateClaim fails when no group assigned.
     *
     * @return void
     */
    public function testValidateClaimNoGroup(): void
    {
        $taskData = ['assigneeGroupId' => null, 'assigneeUserId' => null];

        $result = $this->service->validateClaim($taskData);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('not assigned to a group', $result['reason']);
    }//end testValidateClaimNoGroup()

    /**
     * Test valid status transition from open to in_behandeling.
     *
     * @return void
     */
    public function testValidateStatusTransitionValid(): void
    {
        $result = $this->service->validateStatusTransition('open', 'in_behandeling');
        $this->assertTrue($result['valid']);
    }//end testValidateStatusTransitionValid()

    /**
     * Test valid status transition from in_behandeling to afgerond.
     *
     * @return void
     */
    public function testValidateStatusTransitionToAfgerond(): void
    {
        $result = $this->service->validateStatusTransition('in_behandeling', 'afgerond');
        $this->assertTrue($result['valid']);
    }//end testValidateStatusTransitionToAfgerond()

    /**
     * Test invalid status transition from open to afgerond (skip).
     *
     * @return void
     */
    public function testValidateStatusTransitionInvalid(): void
    {
        $result = $this->service->validateStatusTransition('open', 'afgerond');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not allowed', $result['reason']);
    }//end testValidateStatusTransitionInvalid()

    /**
     * Test valid reopen transition from afgerond to open.
     *
     * @return void
     */
    public function testValidateStatusTransitionReopen(): void
    {
        $result = $this->service->validateStatusTransition('afgerond', 'open');
        $this->assertTrue($result['valid']);
    }//end testValidateStatusTransitionReopen()

    /**
     * Test applyClaim sets current user and updates status.
     *
     * @return void
     */
    public function testApplyClaimSetsUserAndStatus(): void
    {
        $taskData = ['assigneeGroupId' => 'group-1', 'assigneeUserId' => null, 'status' => 'open'];

        $result = $this->service->applyClaim($taskData);

        $this->assertSame('agent-001', $result['assigneeUserId']);
        $this->assertNull($result['assigneeGroupId']);
        $this->assertSame('in_behandeling', $result['status']);
    }//end testApplyClaimSetsUserAndStatus()

    /**
     * Test applyCompletion sets status and timestamps.
     *
     * @return void
     */
    public function testApplyCompletionSetsStatusAndTimestamp(): void
    {
        $taskData = ['status' => 'in_behandeling'];

        $result = $this->service->applyCompletion($taskData, 'Burger geinformeerd');

        $this->assertSame('afgerond', $result['status']);
        $this->assertSame('Burger geinformeerd', $result['resultText']);
        $this->assertArrayHasKey('completedAt', $result);
    }//end testApplyCompletionSetsStatusAndTimestamp()

    /**
     * Test applyReassignment to user clears group.
     *
     * @return void
     */
    public function testApplyReassignmentToUser(): void
    {
        $taskData = ['assigneeUserId' => 'old-user', 'assigneeGroupId' => null];

        $result = $this->service->applyReassignment($taskData, 'new-user', 'user');

        $this->assertSame('new-user', $result['assigneeUserId']);
        $this->assertNull($result['assigneeGroupId']);
    }//end testApplyReassignmentToUser()

    /**
     * Test applyReassignment to group clears user.
     *
     * @return void
     */
    public function testApplyReassignmentToGroup(): void
    {
        $taskData = ['assigneeUserId' => 'some-user', 'assigneeGroupId' => null];

        $result = $this->service->applyReassignment($taskData, 'new-group', 'group');

        $this->assertNull($result['assigneeUserId']);
        $this->assertSame('new-group', $result['assigneeGroupId']);
    }//end testApplyReassignmentToGroup()
}//end class
