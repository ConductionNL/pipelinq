<?php

/**
 * Unit tests for EmailSyncController.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-5.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\EmailSyncController;
use OCA\Pipelinq\Service\EmailSyncService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EmailSyncController.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-5.2
 */
class EmailSyncControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var EmailSyncController
     */
    private EmailSyncController $controller;

    /**
     * Mock request.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * Mock email sync service.
     *
     * @var EmailSyncService&MockObject
     */
    private EmailSyncService $emailSyncService;

    /**
     * Mock user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Mock l10n.
     *
     * @var IL10N&MockObject
     */
    private IL10N $l10n;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test with an authenticated user.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->emailSyncService = $this->createMock(EmailSyncService::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->l10n             = $this->createMock(IL10N::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->l10n->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new EmailSyncController(
            $this->request,
            $this->emailSyncService,
            $this->userSession,
            $this->l10n,
            $this->logger,
        );
    }//end setUp()

    // --- getSettings ---

    /**
     * Test GET /api/sync/email/settings returns 200 for authenticated user.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testGetSettingsReturns200ForAuthenticatedUser(): void
    {
        $this->emailSyncService->method('isSyncEnabled')->willReturn(false);
        $this->emailSyncService->method('getSyncAccounts')->willReturn([]);
        $this->emailSyncService->method('getExcludedAddresses')->willReturn([]);

        $response = $this->controller->getSettings();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('enabled', $response->getData());
    }//end testGetSettingsReturns200ForAuthenticatedUser()

    /**
     * Test GET /api/sync/email/settings returns 401 when unauthenticated.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testGetSettingsReturns401WhenUnauthenticated(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new EmailSyncController(
            $this->request,
            $this->emailSyncService,
            $this->userSession,
            $this->l10n,
            $this->logger,
        );

        $response = $controller->getSettings();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testGetSettingsReturns401WhenUnauthenticated()

    // --- saveSettings ---

    /**
     * Test POST /api/sync/email/settings saves settings and returns 200.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testSaveSettingsReturns200OnSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['enabled', null, true],
                ['accounts', [], [1]],
                ['excludedAddresses', [], []],
            ]);

        $this->emailSyncService->method('isSyncEnabled')->willReturn(true);
        $this->emailSyncService->method('getSyncAccounts')->willReturn([1]);
        $this->emailSyncService->method('getExcludedAddresses')->willReturn([]);

        $response = $this->controller->saveSettings();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testSaveSettingsReturns200OnSuccess()

    /**
     * Test POST /api/sync/email/settings returns 400 when enabled param is missing.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testSaveSettingsReturns400WhenEnabledMissing(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['enabled', null, null],
                ['accounts', [], []],
                ['excludedAddresses', [], []],
            ]);

        $response = $this->controller->saveSettings();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSaveSettingsReturns400WhenEnabledMissing()

    /**
     * Test POST /api/sync/email/settings returns 400 when accounts is not an array.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testSaveSettingsReturns400WhenAccountsInvalid(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['enabled', null, true],
                ['accounts', [], 'not-an-array'],
                ['excludedAddresses', [], []],
            ]);

        $response = $this->controller->saveSettings();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSaveSettingsReturns400WhenAccountsInvalid()

    /**
     * Test POST /api/sync/email/settings returns 401 when unauthenticated.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testSaveSettingsReturns401WhenUnauthenticated(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new EmailSyncController(
            $this->request,
            $this->emailSyncService,
            $this->userSession,
            $this->l10n,
            $this->logger,
        );

        $response = $controller->saveSettings();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSaveSettingsReturns401WhenUnauthenticated()

    // --- triggerSync ---

    /**
     * Test POST /api/sync/email/trigger returns 200 on success.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testTriggerSyncReturns200(): void
    {
        $response = $this->controller->triggerSync();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('triggered', $response->getData());
    }//end testTriggerSyncReturns200()

    /**
     * Test POST /api/sync/email/trigger returns 401 when unauthenticated.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testTriggerSyncReturns401WhenUnauthenticated(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new EmailSyncController(
            $this->request,
            $this->emailSyncService,
            $this->userSession,
            $this->l10n,
            $this->logger,
        );

        $response = $controller->triggerSync();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testTriggerSyncReturns401WhenUnauthenticated()

    // --- getStatus ---

    /**
     * Test GET /api/sync/email/status returns 200 with status fields.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testGetStatusReturns200(): void
    {
        $this->emailSyncService->method('getLastSyncTime')->willReturn('2026-03-20T10:00:00+01:00');
        $this->emailSyncService->method('getLastSyncCount')->willReturn(5);
        $this->emailSyncService->method('getLastSyncError')->willReturn(null);

        $response = $this->controller->getStatus();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertArrayHasKey('lastRun', $data);
        $this->assertArrayHasKey('linked', $data);
        $this->assertArrayHasKey('lastError', $data);
    }//end testGetStatusReturns200()

    /**
     * Test GET /api/sync/email/status returns 401 when unauthenticated.
     *
     * @return void
     * @spec   openspec/changes/email-calendar-sync/tasks.md#task-5.2
     */
    public function testGetStatusReturns401WhenUnauthenticated(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);

        $controller = new EmailSyncController(
            $this->request,
            $this->emailSyncService,
            $this->userSession,
            $this->l10n,
            $this->logger,
        );

        $response = $controller->getStatus();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testGetStatusReturns401WhenUnauthenticated()
}//end class
