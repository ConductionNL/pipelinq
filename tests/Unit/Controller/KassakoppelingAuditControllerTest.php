<?php

/**
 * Unit tests for KassakoppelingAuditController.
 *
 * Covers the controller's authorization posture and append-only contract
 * without a live Nextcloud: an unauthenticated caller is 401, a non-POS user is
 * 403, a successful create is 201, the Belastingdienst export is 403 for a
 * non-manager and a download for a manager, and any PUT/PATCH (update) is 405.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KassakoppelingAuditController;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test suite for the Kassakoppeling audit controller.
 */
class KassakoppelingAuditControllerTest extends TestCase
{
    /**
     * The audit service collaborator (mocked).
     *
     * @var KassakoppelingAuditService
     */
    private KassakoppelingAuditService $service;

    /**
     * The access policy collaborator (mocked).
     *
     * @var PosAccessPolicy
     */
    private PosAccessPolicy $policy;

    /**
     * The user session (mocked).
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * The request (mocked).
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Build the controller with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service     = $this->createMock(KassakoppelingAuditService::class);
        $this->policy      = $this->createMock(PosAccessPolicy::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->request     = $this->createMock(IRequest::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return KassakoppelingAuditController The controller.
     */
    private function controller(): KassakoppelingAuditController
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new KassakoppelingAuditController(
            $this->request,
            $this->service,
            $this->policy,
            $this->userSession,
            $l10n,
            $this->createMock(LoggerInterface::class),
        );
    }//end controller()

    /**
     * Wire the session to return a user with the given UID, or null.
     *
     * @param string|null $uid The UID, or null for an anonymous session.
     *
     * @return void
     */
    private function withUser(?string $uid): void
    {
        if ($uid === null) {
            $this->userSession->method('getUser')->willReturn(null);
            return;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end withUser()

    /**
     * An anonymous create is rejected with 401.
     *
     * @return void
     */
    public function testCreateRequiresAuth(): void
    {
        $this->withUser(null);

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testCreateRequiresAuth()

    /**
     * A non-POS user is forbidden from creating.
     *
     * @return void
     */
    public function testCreateForbiddenForNonPosUser(): void
    {
        $this->withUser('bob');
        $this->policy->method('isPosUser')->willReturn(false);

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testCreateForbiddenForNonPosUser()

    /**
     * A POS user's valid create returns 201 with the stored entry.
     *
     * @return void
     */
    public function testCreateReturns201(): void
    {
        $this->withUser('john');
        $this->policy->method('isPosUser')->willReturn(true);
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                $values = [
                    'registerNumber' => 'REG-001',
                    'action'         => 'sale',
                    'amount'         => 4950,
                ];

                return ($values[$key] ?? $default);
            }
        );
        $this->service->method('createEntry')->willReturn(['id' => 'abc', 'signature' => 'sig']);

        $response = $this->controller()->create();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('abc', $response->getData()['entry']['id']);
    }//end testCreateReturns201()

    /**
     * The export is forbidden for a non-manager (403).
     *
     * @return void
     */
    public function testExportForbiddenForNonManager(): void
    {
        $this->withUser('john');
        $this->policy->method('isManager')->willReturn(false);

        $response = $this->controller()->exportBelastingdienst();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testExportForbiddenForNonManager()

    /**
     * The export returns a download for a manager.
     *
     * @return void
     */
    public function testExportReturnsDownloadForManager(): void
    {
        $this->withUser('boss');
        $this->policy->method('isManager')->willReturn(true);
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, $default=null) {
                if ($key === 'format') {
                    return 'json';
                }

                return $default;
            }
        );
        $this->service->method('exportForBelastingdienst')->willReturn('{"entries":[]}');

        $response = $this->controller()->exportBelastingdienst();

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }//end testExportReturnsDownloadForManager()

    /**
     * Any update (PUT/PATCH) is rejected as 405 (append-only ledger).
     *
     * @return void
     */
    public function testUpdateIsMethodNotAllowed(): void
    {
        $response = $this->controller()->update('any-id');

        $this->assertSame(Http::STATUS_METHOD_NOT_ALLOWED, $response->getStatus());
    }//end testUpdateIsMethodNotAllowed()
}//end class
