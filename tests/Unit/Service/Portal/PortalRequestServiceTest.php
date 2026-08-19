<?php

/**
 * Unit tests for PortalRequestService — scoping, internal-note filtering, rate limit.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the portal request surface.
 */
class PortalRequestServiceTest extends TestCase
{
    /**
     * The fake portal repository.
     *
     * @var FakePortalObjectRepository
     */
    private FakePortalObjectRepository $portalRepo;

    /**
     * The fake main-register reader.
     *
     * @var FakeMainRegisterReader
     */
    private FakeMainRegisterReader $reader;

    /**
     * The service under test.
     *
     * @var PortalRequestService
     */
    private PortalRequestService $service;

    /**
     * "now" timestamp.
     *
     * @var int
     */
    private int $now = 1000000;

    /**
     * Set up the request service over fakes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->portalRepo = new FakePortalObjectRepository();
        $this->reader     = new FakeMainRegisterReader();
        $this->reader->markConfigured('request');

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $time->method('getDateTime')->willReturnCallback(
            fn (): \DateTime => (new \DateTime())->setTimestamp($this->now)
        );

        $audit       = $this->createMock(\OCA\Pipelinq\Service\Portal\PortalAuditService::class);
        $delegations = new PortalDelegationService($this->portalRepo, $audit, $time);
        $scope       = new PortalScopeResolver($this->portalRepo, $delegations);
        $dispatcher  = $this->createMock(IEventDispatcher::class);
        $logger      = $this->createMock(LoggerInterface::class);

        $this->service = new PortalRequestService(
            $this->reader,
            $scope,
            $audit,
            $dispatcher,
            $time,
            $logger
        );
    }//end setUp()

    /**
     * Only the customer's own requests (by linked contact) are listed.
     *
     * @return void
     */
    public function testOnlyOwnRequestsListed(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        $this->reader->seed('request', 'r1', ['contact' => 'contact-a', 'title' => 'Mine', 'requestedAt' => '2026-05-01T00:00:00Z']);
        $this->reader->seed('request', 'r2', ['contact' => 'contact-x', 'title' => 'Theirs', 'requestedAt' => '2026-05-02T00:00:00Z']);

        $page = $this->service->getForAccount($account);
        $this->assertSame(1, $page['total']);
        $this->assertSame('Mine', $page['items'][0]['subject']);
    }//end testOnlyOwnRequestsListed()

    /**
     * A request belonging to another contact returns null detail (IDOR → 404).
     *
     * @return void
     */
    public function testCrossCustomerRequestDetailNull(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        $this->reader->seed('request', 'r-x', ['contact' => 'contact-x', 'title' => 'Theirs']);

        $this->assertNull($this->service->getDetailForAccount($account, 'r-x', false));
    }//end testCrossCustomerRequestDetailNull()

    /**
     * Internal notes are stripped server-side from the detail.
     *
     * @return void
     */
    public function testInternalNotesFiltered(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        $this->reader->seed('request', 'r1', [
            'contact' => 'contact-a',
            'title'   => 'Mine',
            'notes'   => [
                ['visibility' => 'customer', 'message' => 'visible-1', 'createdAt' => '2026-05-01T00:00:00Z'],
                ['visibility' => 'internal', 'message' => 'secret', 'createdAt' => '2026-05-01T01:00:00Z'],
                ['visibility' => 'customer', 'message' => 'visible-2', 'createdAt' => '2026-05-01T02:00:00Z'],
            ],
        ]);

        $detail   = $this->service->getDetailForAccount($account, 'r1', false);
        $messages = array_column($detail['notes'], 'message');
        $this->assertSame(['visible-1', 'visible-2'], $messages);
        $this->assertNotContains('secret', $messages);
    }//end testInternalNotesFiltered()

    /**
     * The assignee is hidden unless the tenant exposes it.
     *
     * @return void
     */
    public function testAssigneeHiddenByDefault(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        $this->reader->seed('request', 'r1', ['contact' => 'contact-a', 'assignee' => 'm.bakker']);

        $detail = $this->service->getDetailForAccount($account, 'r1', false);
        $this->assertArrayNotHasKey('assignee', $detail);
        $this->assertTrue($detail['assigneeHidden']);
    }//end testAssigneeHiddenByDefault()

    /**
     * Submitting persists a portal-tagged request and returns an ETA.
     *
     * @return void
     */
    public function testSubmitCreatesRequest(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        $result  = $this->service->submit($account, 'tenant-a', 'Subject', 'Body', [], 'cat-1');

        $this->assertArrayHasKey('requestId', $result);
        $stored = $this->reader->find('request', $result['requestId']);
        $this->assertSame('portal', $stored['submittedVia']);
        $this->assertSame('contact-a', $stored['contact']);
    }//end testSubmitCreatesRequest()

    /**
     * The 6th submission within the hour is rate-limited (429).
     *
     * @return void
     */
    public function testSubmitRateLimited(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        for ($i = 0; $i < 5; $i++) {
            $this->service->submit($account, 'tenant-a', 'S'.$i, 'B', [], 'cat-1');
        }

        try {
            $this->service->submit($account, 'tenant-a', 'S6', 'B', [], 'cat-1');
            $this->fail('Expected rate limit');
        } catch (PortalException $e) {
            $this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $e->getStatus());
            $this->assertSame('rateLimited', $e->getErrorCode());
        }
    }//end testSubmitRateLimited()

    /**
     * An attachment over 25 MB is rejected with 413.
     *
     * @return void
     */
    public function testOversizeAttachmentRejected(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        try {
            $this->service->submit($account, 'tenant-a', 'S', 'B', [['id' => 'f1', 'size' => (30 * 1024 * 1024)]], 'cat-1');
            $this->fail('Expected file-too-large');
        } catch (PortalException $e) {
            $this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $e->getStatus());
            $this->assertSame('fileTooLarge', $e->getErrorCode());
        }
    }//end testOversizeAttachmentRejected()

    /**
     * A reply to an awaiting-customer request unpauses it.
     *
     * @return void
     */
    public function testReplyUnpausesAwaitingCustomer(): void
    {
        $account = $this->portalRepo->seed('portalAccount', 'acc-a', ['linkedContactId' => 'contact-a']);
        $this->reader->seed('request', 'r1', ['contact' => 'contact-a', 'status' => 'awaiting-customer']);

        $this->service->addReply($account, 'tenant-a', 'r1', 'Here is my reply');
        $stored = $this->reader->find('request', 'r1');
        $this->assertSame('in-progress', $stored['status']);
        $this->assertSame('customer', $stored['notes'][0]['visibility']);
    }//end testReplyUnpausesAwaitingCustomer()
}//end class
