<?php

/**
 * Unit tests for KassakoppelingAuditController.
 *
 * Asserts the HTTP surface of the Kassakoppeling audit log: every action
 * requires an authenticated user (401 otherwise), delegates the work to
 * KassakoppelingAuditService with the request body translated into the
 * service signature, the export endpoint enforces an in-body admin gate
 * (403 for non-admin users + 400 for a missing date range) and the OCS
 * exception types raised by the service map to the right HTTP status codes
 * without leaking server-side detail on a 500.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KassakoppelingAuditController;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the controller's mocks.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Each endpoint × edge case is
 *  asserted independently for clarity.
 */
class KassakoppelingAuditControllerTest extends TestCase
{

    /**
     * Controller under test.
     *
     * @var KassakoppelingAuditController
     */
    private KassakoppelingAuditController $controller;

    /**
     * Mock audit service.
     *
     * @var KassakoppelingAuditService&MockObject
     */
    private KassakoppelingAuditService $service;

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Mock group manager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * Wire the controller with mocks; l10n echoes its input.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request      = $this->createMock(IRequest::class);
        $this->service      = $this->createMock(KassakoppelingAuditService::class);
        $this->userSession  = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $text): string => $text);

        $this->controller = new KassakoppelingAuditController(
            $this->request,
            $this->service,
            $this->userSession,
            $this->groupManager,
            $l10n,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Resolve the session to a user with the given uid.
     *
     * @param string $uid The acting uid.
     *
     * @return void
     */
    private function loginAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end loginAs()

    /**
     * Every action returns 401 when there is no session.
     *
     * @return void
     */
    public function testCreateRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->service->expects($this->never())->method('createEntry');

        $response = $this->controller->create();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateRequiresAuthentication()

    /**
     * create() delegates to the service with the session uid as the default
     * operatorId and returns 201 on success.
     *
     * @return void
     */
    public function testCreateDelegatesAndReturns201(): void
    {
        $this->loginAs('user_john');
        $this->request->method('getParam')->willReturnMap([
            ['operatorId', 'user_john', 'user_john'],
            ['registerNumber', '', 'REG-001'],
            ['action', '', 'sale'],
            ['amount', 0, 4950],
            ['itemCount', null, 3],
            ['taxAmount', null, 870],
            ['timestamp', '', '2026-05-20T08:15:30+00:00'],
            ['transactionUuid', null, 'uuid-txn-001'],
            ['description', null, 'Regular sale'],
        ]);

        $this->service->expects($this->once())
            ->method('createEntry')
            ->with([
                'operatorId'      => 'user_john',
                'registerNumber'  => 'REG-001',
                'action'          => 'sale',
                'amount'          => 4950,
                'itemCount'       => 3,
                'taxAmount'       => 870,
                'timestamp'       => '2026-05-20T08:15:30+00:00',
                'transactionUuid' => 'uuid-txn-001',
                'description'     => 'Regular sale',
            ])
            ->willReturn(['id' => 'aud-1', 'signature' => 'sig']);

        $response = $this->controller->create();
        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('aud-1', $response->getData()['entry']['id']);

    }//end testCreateDelegatesAndReturns201()

    /**
     * A service OCSBadRequestException maps to HTTP 422.
     *
     * @return void
     */
    public function testCreateBadRequestMapsTo422(): void
    {
        $this->loginAs('user_john');
        $this->request->method('getParam')->willReturn('');
        $this->service->method('createEntry')
            ->willThrowException(new OCSBadRequestException('bad'));

        $response = $this->controller->create();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testCreateBadRequestMapsTo422()

    /**
     * index() forwards the whitelisted filter map.
     *
     * @return void
     */
    public function testIndexForwardsFilters(): void
    {
        $this->loginAs('user_john');
        $this->request->method('getParam')->willReturnMap([
            ['registerNumber', '', 'REG-001'],
            ['operatorId', '', ''],
            ['action', '', 'sale'],
            ['from', '', '2026-05-01'],
            ['to', '', '2026-05-31'],
        ]);

        $this->service->expects($this->once())
            ->method('listEntries')
            ->with([
                'registerNumber' => 'REG-001',
                'operatorId'     => '',
                'action'         => 'sale',
                'from'           => '2026-05-01',
                'to'             => '2026-05-31',
            ])
            ->willReturn([['id' => 'aud-1']]);

        $response = $this->controller->index();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['entries']);

    }//end testIndexForwardsFilters()

    /**
     * show() maps a missing entry to HTTP 404.
     *
     * @return void
     */
    public function testShowNotFoundMapsTo404(): void
    {
        $this->loginAs('user_john');
        $this->service->method('getEntry')
            ->willThrowException(new OCSNotFoundException('missing'));

        $response = $this->controller->show('does-not-exist');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testShowNotFoundMapsTo404()

    /**
     * verify() returns the structured service result on success.
     *
     * @return void
     */
    public function testVerifyReturnsServiceResult(): void
    {
        $this->loginAs('user_john');
        $this->service->expects($this->once())
            ->method('verifyEntry')
            ->with(id: 'aud-1')
            ->willReturn([
                'verified'       => true,
                'signatureValid' => true,
                'hashValid'      => true,
                'entry'          => ['id' => 'aud-1'],
            ]);

        $response = $this->controller->verify('aud-1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['verified']);

    }//end testVerifyReturnsServiceResult()

    /**
     * verify() maps an unexpected exception to a generic 500.
     *
     * @return void
     */
    public function testVerifyUnexpectedExceptionMapsTo500(): void
    {
        $this->loginAs('user_john');
        $this->service->method('verifyEntry')
            ->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->verify('aud-1');
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());

    }//end testVerifyUnexpectedExceptionMapsTo500()

    /**
     * export() returns 403 when the acting user is not a Nextcloud admin.
     *
     * @return void
     */
    public function testExportRejectsNonAdmin(): void
    {
        $this->loginAs('user_john');
        $this->groupManager->method('isAdmin')->with('user_john')->willReturn(false);
        $this->service->expects($this->never())->method('exportForBelastingdienst');

        $response = $this->controller->export();
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testExportRejectsNonAdmin()

    /**
     * export() returns 400 when from/to are missing even for admin users.
     *
     * @return void
     */
    public function testExportRequiresDateRange(): void
    {
        $this->loginAs('admin');
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
        $this->request->method('getParam')->willReturn('');
        $this->service->expects($this->never())->method('exportForBelastingdienst');

        $response = $this->controller->export();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testExportRequiresDateRange()

    /**
     * export() returns a DataDownloadResponse on a clean admin call.
     *
     * @return void
     */
    public function testExportReturnsDownloadForAdmin(): void
    {
        $this->loginAs('admin');
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['from', '', '2026-05-01'],
            ['to', '', '2026-05-31'],
            ['format', 'xml', 'xml'],
        ]);

        $this->service->expects($this->once())
            ->method('exportForBelastingdienst')
            ->with(fromDate: '2026-05-01', toDate: '2026-05-31', format: 'xml')
            ->willReturn([
                'body'        => '<KassakoppelingExport/>',
                'contentType' => 'application/xml',
                'filename'    => 'kassakoppeling-export-2026-05-01-to-2026-05-31.xml',
                'entryCount'  => 1,
            ]);

        $response = $this->controller->export();
        $this->assertInstanceOf(DataDownloadResponse::class, $response);

    }//end testExportReturnsDownloadForAdmin()

    /**
     * export() maps a service OCSBadRequestException to HTTP 422.
     *
     * @return void
     */
    public function testExportBadServiceInputMapsTo422(): void
    {
        $this->loginAs('admin');
        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->request->method('getParam')->willReturnMap([
            ['from', '', '2026-05-31'],
            ['to', '', '2026-05-01'],
            ['format', 'xml', 'xml'],
        ]);

        $this->service->method('exportForBelastingdienst')
            ->willThrowException(new OCSBadRequestException('range'));

        $response = $this->controller->export();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testExportBadServiceInputMapsTo422()

}//end class
