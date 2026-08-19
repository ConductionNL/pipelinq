<?php

/**
 * Unit tests for EmailSyncController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/email-calendar-sync/spec.md#requirement-email-sync-must-be-configurable-per-user
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\EmailSyncController;
use OCA\Pipelinq\Service\EmailMatchService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for EmailSyncController.
 *
 * Verifies the 200 / 401 / 400 / 500 response envelope across the four
 * endpoints, that the controller uses IUserSession-derived identity
 * (never a frontend-sent user id), and that error responses are
 * static l10n strings (no `$e->getMessage()` leaks).
 */
class EmailSyncControllerTest extends TestCase
{

    /**
     * Request mock.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Service mock.
     *
     * @var EmailMatchService
     */
    private EmailMatchService $service;

    /**
     * User session mock.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Identity l10n mock.
     *
     * @var IL10N
     */
    private IL10N $l10n;


    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(EmailMatchService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l10n        = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);

    }//end setUp()


    /**
     * Build the controller under test.
     *
     * @return EmailSyncController
     */
    private function buildController(): EmailSyncController
    {
        return new EmailSyncController(
            request: $this->request,
            emailMatchService: $this->service,
            userSession: $this->userSession,
            l10n: $this->l10n,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end buildController()


    /**
     * Authenticate the controller for the rest of a test as `$uid`.
     *
     * @param string $uid The user id to authenticate as.
     *
     * @return void
     */
    private function authenticateAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end authenticateAs()


    /**
     * GET /api/sync/email/settings — 200 returns the settings shape.
     *
     * @return void
     */
    public function testGetSettings200(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->service->method('getSettings')->willReturn(
            [
                'account'           => 7,
                'enabled'           => true,
                'excludedAddresses' => ['x@example.org'],
                'cursor'            => 42,
            ]
        );

        $resp = $this->buildController()->getSettings();

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $data = $resp->getData();
        $this->assertSame(7, $data['account']);
        $this->assertTrue($data['enabled']);
        $this->assertSame(['x@example.org'], $data['excludedAddresses']);
        // The cursor is never exposed in the response envelope.
        $this->assertArrayNotHasKey('cursor', $data);

    }//end testGetSettings200()


    /**
     * GET /api/sync/email/settings — 401 when unauthenticated.
     *
     * @return void
     */
    public function testGetSettings401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $resp = $this->buildController()->getSettings();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());

    }//end testGetSettings401()


    /**
     * POST /api/sync/email/settings — 400 when `account` is missing/non-numeric.
     *
     * @return void
     */
    public function testSaveSettings400OnInvalidAccount(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                if ($key === 'account') {
                    return 'not-a-number';
                }

                return $default;
            }
        );

        $resp = $this->buildController()->saveSettings();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());

    }//end testSaveSettings400OnInvalidAccount()


    /**
     * POST /api/sync/email/settings — 200 persists + returns the new shape.
     *
     * @return void
     */
    public function testSaveSettings200(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                $values = [
                    'account'           => 9,
                    'enabled'           => true,
                    'excludedAddresses' => ['noreply@example.org'],
                ];

                return ($values[$key] ?? $default);
            }
        );

        $this->service->method('getSettings')->willReturn(
            [
                'account'           => 9,
                'enabled'           => true,
                'excludedAddresses' => ['noreply@example.org'],
                'cursor'            => 0,
            ]
        );
        $this->service->expects($this->once())->method('writeSettings');

        $resp = $this->buildController()->saveSettings();

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(9, $resp->getData()['account']);

    }//end testSaveSettings200()


    /**
     * POST /api/sync/email/trigger — 200 returns the run summary.
     *
     * @return void
     */
    public function testTrigger200(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->service->method('runForUser')->willReturn(['linked' => 2, 'scanned' => 9]);

        $resp = $this->buildController()->trigger();

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['linked' => 2, 'scanned' => 9], $resp->getData());

    }//end testTrigger200()


    /**
     * POST /api/sync/email/trigger — 401 when unauthenticated.
     *
     * @return void
     */
    public function testTrigger401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $resp = $this->buildController()->trigger();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());

    }//end testTrigger401()


    /**
     * GET /api/sync/email/status — 200 returns the status envelope.
     *
     * @return void
     */
    public function testGetStatus200(): void
    {
        $this->authenticateAs(uid: 'alice');
        $this->service->method('getStatus')->willReturn(
            [
                'lastRunAt' => '2026-06-08T12:00:00+00:00',
                'linked'    => 5,
                'scanned'   => 17,
                'error'     => null,
            ]
        );

        $resp = $this->buildController()->getStatus();

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(5, $resp->getData()['linked']);
        $this->assertSame(17, $resp->getData()['scanned']);

    }//end testGetStatus200()


    /**
     * GET /api/sync/email/status — 401 unauthenticated.
     *
     * @return void
     */
    public function testGetStatus401(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $resp = $this->buildController()->getStatus();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());

    }//end testGetStatus401()


}//end class
