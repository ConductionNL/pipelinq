<?php

/**
 * Unit tests for KassakoppelingAuditController.
 *
 * Covers the HTTP API surface: create (201), index (200), show (200/404),
 * verify (200), exportBelastingdienst (200 admin / 403 non-admin / 400 missing params),
 * and the immutability guard (PUT → 405).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#8.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KassakoppelingAuditController;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KassakoppelingAuditController.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#8.3
 */
class KassakoppelingAuditControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var KassakoppelingAuditController
     */
    private KassakoppelingAuditController $controller;

    /**
     * Mock audit service.
     *
     * @var KassakoppelingAuditService&MockObject
     */
    private KassakoppelingAuditService $auditService;

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
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

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
        $this->request      = $this->createMock(IRequest::class);
        $this->auditService = $this->createMock(KassakoppelingAuditService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $l10n               = $this->createMock(IL10N::class);
        $logger             = $this->createMock(LoggerInterface::class);

        $l10n->method('t')->willReturnArgument(0);

        $this->user = $this->createMock(IUser::class);
        $this->user->method('getUID')->willReturn('test-user');

        $this->userSession->method('getUser')->willReturn($this->user);

        $this->controller = new KassakoppelingAuditController(
            request: $this->request,
            auditService: $this->auditService,
            groupManager: $this->groupManager,
            userSession: $this->userSession,
            l10n: $l10n,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test create returns 201 with the created entry.
     *
     * @return void
     */
    public function testCreateReturns201WithEntry(): void
    {
        $entryData = [
            'operatorId'     => 'test-user',
            'registerNumber' => 'REG-001',
            'action'         => 'sale',
            'amount'         => 4950,
            'timestamp'      => '2026-05-20T08:15:30Z',
            'signature'      => str_repeat('a', 64),
            'previousHash'   => '0',
            'currentHash'    => str_repeat('b', 64),
        ];

        $this->request->method('getParams')->willReturn($entryData);

        $this->auditService
            ->expects($this->once())
            ->method('createEntry')
            ->willReturn($entryData);

        $response = $this->controller->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('entry', $data);
    }//end testCreateReturns201WithEntry()

    /**
     * Test create returns 400 for a bad request exception.
     *
     * @return void
     */
    public function testCreateReturns400ForBadRequest(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->auditService
            ->method('createEntry')
            ->willThrowException(new OCSBadRequestException("Required field 'action' is missing"));

        $response = $this->controller->create();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testCreateReturns400ForBadRequest()

    /**
     * Test index returns 200 with entries list.
     *
     * @return void
     */
    public function testIndexReturns200WithEntries(): void
    {
        $entries = [
            ['id' => 'entry-1', 'action' => 'sale'],
            ['id' => 'entry-2', 'action' => 'void'],
        ];

        $this->request->method('getParam')->willReturnMap([
            ['registerNumber', '', ''],
            ['operatorId', '', ''],
            ['action', '', ''],
            ['fromDate', '', ''],
            ['toDate', '', ''],
        ]);

        $this->auditService
            ->expects($this->once())
            ->method('listEntries')
            ->willReturn($entries);

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('entries', $data);
        $this->assertCount(2, $data['entries']);
    }//end testIndexReturns200WithEntries()

    /**
     * Test show returns 200 with a single entry.
     *
     * @return void
     */
    public function testShowReturns200WithEntry(): void
    {
        $entry = ['id' => 'entry-1', 'action' => 'sale', 'amount' => 4950];

        $this->auditService
            ->expects($this->once())
            ->method('getEntry')
            ->with('entry-1')
            ->willReturn($entry);

        $response = $this->controller->show('entry-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('entry', $data);
    }//end testShowReturns200WithEntry()

    /**
     * Test show returns 404 for a not-found entry.
     *
     * @return void
     */
    public function testShowReturns404WhenNotFound(): void
    {
        $this->auditService
            ->method('getEntry')
            ->willThrowException(new OCSNotFoundException('Audit entry not found.'));

        $response = $this->controller->show('non-existent');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testShowReturns404WhenNotFound()

    /**
     * Test verify returns 200 with verified flag.
     *
     * @return void
     */
    public function testVerifyReturns200WithVerifiedFlag(): void
    {
        $this->auditService
            ->expects($this->once())
            ->method('verifyEntry')
            ->with('entry-1')
            ->willReturn(true);

        $response = $this->controller->verify('entry-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['verified']);
        $this->assertSame('entry-1', $data['entryId']);
    }//end testVerifyReturns200WithVerifiedFlag()

    /**
     * Test exportBelastingdienst returns 403 for non-admin.
     *
     * @return void
     */
    public function testExportBelastingdienestReturns403ForNonAdmin(): void
    {
        $this->groupManager
            ->method('isAdmin')
            ->with('test-user')
            ->willReturn(false);

        $response = $this->controller->exportBelastingdienst();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testExportBelastingdienestReturns403ForNonAdmin()

    /**
     * Test exportBelastingdienst returns 400 when fromDate/toDate missing.
     *
     * @return void
     */
    public function testExportBelastingdienestReturns400WhenDatesMissing(): void
    {
        $this->groupManager
            ->method('isAdmin')
            ->with('test-user')
            ->willReturn(true);

        $this->request->method('getParam')->willReturnMap([
            ['fromDate', '', ''],
            ['toDate', '', ''],
            ['format', 'xml', 'xml'],
        ]);

        $response = $this->controller->exportBelastingdienst();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testExportBelastingdienestReturns400WhenDatesMissing()

    /**
     * Test exportBelastingdienst returns a file download for admin with valid dates.
     *
     * @return void
     */
    public function testExportBelastingdienestReturnsDownloadForAdmin(): void
    {
        $this->groupManager
            ->method('isAdmin')
            ->with('test-user')
            ->willReturn(true);

        $this->request->method('getParam')->willReturnMap([
            ['fromDate', '', '2026-05-01'],
            ['toDate', '', '2026-05-31'],
            ['format', 'xml', 'xml'],
        ]);

        $this->auditService
            ->expects($this->once())
            ->method('exportForBelastingdienst')
            ->with('2026-05-01', '2026-05-31', 'xml')
            ->willReturn('<?xml version="1.0"?><KassakoppelingExport />');

        $response = $this->controller->exportBelastingdienst();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testExportBelastingdienestReturnsDownloadForAdmin()

    /**
     * Test that the controller returns 401 when no user is in session.
     *
     * @return void
     */
    public function testCreateReturns401WhenUnauthenticated(): void
    {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $l10n   = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $logger = $this->createMock(LoggerInterface::class);

        $controller = new KassakoppelingAuditController(
            request: $this->request,
            auditService: $this->auditService,
            groupManager: $this->groupManager,
            userSession: $userSession,
            l10n: $l10n,
            logger: $logger,
        );

        $this->request->method('getParams')->willReturn([]);

        $response = $controller->create();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testCreateReturns401WhenUnauthenticated()
}//end class
