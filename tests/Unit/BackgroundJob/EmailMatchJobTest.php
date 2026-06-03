<?php

/**
 * Unit tests for EmailMatchJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\EmailMatchJob;
use OCA\Pipelinq\Service\EmailLeafLinkAdapter;
use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\MailMessageProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the matching job's per-user pipeline, dedup, and error isolation.
 */
class EmailMatchJobTest extends TestCase
{

    /**
     * The matching service mock.
     *
     * @var EmailMatchService&MockObject
     */
    private EmailMatchService $matchService;

    /**
     * The candidate-message provider mock.
     *
     * @var MailMessageProvider&MockObject
     */
    private MailMessageProvider $provider;

    /**
     * The email-leaf link adapter mock.
     *
     * @var EmailLeafLinkAdapter&MockObject
     */
    private EmailLeafLinkAdapter $linkAdapter;

    /**
     * Set up the mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->matchService = $this->createMock(EmailMatchService::class);
        $this->provider     = $this->createMock(MailMessageProvider::class);
        $this->linkAdapter  = $this->createMock(EmailLeafLinkAdapter::class);
    }//end setUp()

    /**
     * Build the job under test.
     *
     * @return EmailMatchJob The job.
     */
    private function job(): EmailMatchJob
    {
        return new EmailMatchJob(
            $this->createMock(ITimeFactory::class),
            $this->createMock(IUserManager::class),
            $this->matchService,
            $this->provider,
            $this->linkAdapter,
            $this->createMock(LoggerInterface::class)
        );
    }//end job()

    /**
     * A matched message is linked via the leaf and counted.
     *
     * @return void
     */
    public function testMatchedMessageIsLinkedViaLeaf(): void
    {
        $this->matchService->method('isSyncEnabled')->willReturn(true);
        $this->matchService->method('getSyncAccount')->willReturn(5);
        $this->matchService->method('isExcluded')->willReturn(false);
        $this->matchService->method('resolveAddress')->willReturn([['type' => 'contact', 'uuid' => 'uuid-1']]);

        $this->provider->method('getCandidateMessages')->willReturn(
            [
                ['accountId' => 5, 'messageId' => 42, 'messageUid' => 'uid-42', 'addresses' => ['a@example.nl']],
            ]
        );

        $this->linkAdapter->expects($this->once())
            ->method('linkMessage')
            ->with('uuid-1', 5, 42, 'uid-42')
            ->willReturn(true);

        // Run completed successfully → recordRun called with linked=1, no error.
        $this->matchService->expects($this->once())
            ->method('recordRun')
            ->with('alice', 1, null);

        $this->job()->runForUser('alice');
    }//end testMatchedMessageIsLinkedViaLeaf()

    /**
     * The job skips users who have not enabled sync.
     *
     * @return void
     */
    public function testDisabledUserIsSkipped(): void
    {
        $this->matchService->method('isSyncEnabled')->willReturn(false);
        $this->provider->expects($this->never())->method('getCandidateMessages');
        $this->linkAdapter->expects($this->never())->method('linkMessage');

        $this->job()->runForUser('bob');
    }//end testDisabledUserIsSkipped()

    /**
     * A message matching one entity via two addresses links it exactly once.
     *
     * @return void
     */
    public function testDuplicateEntityLinkedOnce(): void
    {
        $this->matchService->method('isSyncEnabled')->willReturn(true);
        $this->matchService->method('getSyncAccount')->willReturn(5);
        $this->matchService->method('isExcluded')->willReturn(false);
        $this->matchService->method('resolveAddress')->willReturn([['type' => 'contact', 'uuid' => 'uuid-1']]);

        $this->provider->method('getCandidateMessages')->willReturn(
            [
                ['accountId' => 5, 'messageId' => 7, 'messageUid' => '', 'addresses' => ['from@example.nl', 'to@example.nl']],
            ]
        );

        // Same entity resolved by both addresses → exactly one link attempt.
        $this->linkAdapter->expects($this->once())->method('linkMessage')->willReturn(true);

        $this->job()->runForUser('alice');
    }//end testDuplicateEntityLinkedOnce()

    /**
     * Excluded addresses never produce a link.
     *
     * @return void
     */
    public function testExcludedAddressIsSkipped(): void
    {
        $this->matchService->method('isSyncEnabled')->willReturn(true);
        $this->matchService->method('getSyncAccount')->willReturn(5);
        $this->matchService->method('isExcluded')->willReturn(true);
        $this->matchService->expects($this->never())->method('resolveAddress');

        $this->provider->method('getCandidateMessages')->willReturn(
            [
                ['accountId' => 5, 'messageId' => 9, 'messageUid' => '', 'addresses' => ['noreply@example.nl']],
            ]
        );

        $this->linkAdapter->expects($this->never())->method('linkMessage');

        $this->job()->runForUser('alice');
    }//end testExcludedAddressIsSkipped()

    /**
     * A message from a different account than the user's is never linked.
     *
     * @return void
     */
    public function testForeignAccountMessageIsSkipped(): void
    {
        $this->matchService->method('isSyncEnabled')->willReturn(true);
        $this->matchService->method('getSyncAccount')->willReturn(5);

        $this->provider->method('getCandidateMessages')->willReturn(
            [
                ['accountId' => 99, 'messageId' => 1, 'messageUid' => '', 'addresses' => ['a@example.nl']],
            ]
        );

        $this->linkAdapter->expects($this->never())->method('linkMessage');

        $this->job()->runForUser('alice');
    }//end testForeignAccountMessageIsSkipped()

    /**
     * A throwing provider is caught and recorded as a failed run (no crash).
     *
     * @return void
     */
    public function testUserRunErrorIsIsolated(): void
    {
        $this->matchService->method('isSyncEnabled')->willReturn(true);
        $this->matchService->method('getSyncAccount')->willReturn(5);
        $this->provider->method('getCandidateMessages')->willThrowException(new RuntimeException('boom'));

        // Failure recorded with a STATIC message — never the raw exception text.
        $this->matchService->expects($this->once())
            ->method('recordRun')
            ->with('alice', 0, 'Sync failed');

        // Must not throw.
        $this->job()->runForUser('alice');
    }//end testUserRunErrorIsIsolated()
}//end class
