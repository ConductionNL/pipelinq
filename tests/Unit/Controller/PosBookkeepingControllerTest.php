<?php

/**
 * Unit tests for PosBookkeepingController.
 *
 * Asserts the HTTP surface of the POS end-of-day submission endpoint: each
 * action requires an authenticated user (401 otherwise), delegates the
 * server-authoritative work to PosBookkeepingService with the manager uid
 * taken from the session (never the body), maps the service's OCS exceptions
 * to 404 / 403 / 422 and never leaks internal error detail on 500.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosBookkeepingController;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosBookkeepingController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the controller's mocks.
 */
class PosBookkeepingControllerTest extends TestCase
{

    private PosBookkeepingController $controller;

    /**
     * @var PosBookkeepingService&MockObject
     */
    private PosBookkeepingService $service;

    /**
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * Wire the controller with mocks; l10n echoes its input.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(PosBookkeepingService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(fn (string $text): string => $text);

        $this->controller = new PosBookkeepingController(
            $this->request,
            $this->service,
            $this->userSession,
            $l10n,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Make the session resolve to a user with the given uid.
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
     * post() returns 401 when no user is in the session.
     *
     * @return void
     */
    public function testPostRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->service->expects($this->never())->method('postToShillinq');

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testPostRequiresAuthentication()

    /**
     * post() returns 400 when the body is missing the outboundMessageId.
     *
     * @return void
     */
    public function testPostRejectsEmptyOutboundId(): void
    {
        $this->loginAs('boss');
        $this->request->method('getParam')->willReturn('');

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testPostRejectsEmptyOutboundId()

    /**
     * post() with a valid outboundMessageId delegates with the session uid.
     *
     * @return void
     */
    public function testPostDelegatesWithSessionUid(): void
    {
        $this->loginAs('boss');
        $this->request->method('getParam')->willReturn('out-1');

        $this->service->expects($this->once())
            ->method('postToShillinq')
            ->with('out-1', 'boss')
            ->willReturn(['id' => 'out-1', 'status' => 'posted']);

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('posted', $data['outbound']['status']);
    }//end testPostDelegatesWithSessionUid()

    /**
     * post() maps OCSForbiddenException to 403 (manager-gate / IDOR).
     *
     * @return void
     */
    public function testPostMapsForbiddenTo403(): void
    {
        $this->loginAs('clerk');
        $this->request->method('getParam')->willReturn('out-1');

        $this->service->method('postToShillinq')
            ->willThrowException(new OCSForbiddenException('not a manager'));

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testPostMapsForbiddenTo403()

    /**
     * post() maps OCSNotFoundException to 404 (outbound not found).
     *
     * @return void
     */
    public function testPostMapsNotFoundTo404(): void
    {
        $this->loginAs('boss');
        $this->request->method('getParam')->willReturn('out-missing');

        $this->service->method('postToShillinq')
            ->willThrowException(new OCSNotFoundException('not found'));

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testPostMapsNotFoundTo404()

    /**
     * post() maps OCSBadRequestException to 422 (precondition failed).
     *
     * @return void
     */
    public function testPostMapsBadRequestTo422(): void
    {
        $this->loginAs('boss');
        $this->request->method('getParam')->willReturn('out-1');

        $this->service->method('postToShillinq')
            ->willThrowException(new OCSBadRequestException('endpoint not configured'));

        $response = $this->controller->post();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testPostMapsBadRequestTo422()

    /**
     * post() maps unexpected exceptions to 500 without leaking internal detail.
     *
     * @return void
     */
    public function testPostMapsUnexpectedTo500(): void
    {
        $this->loginAs('boss');
        $this->request->method('getParam')->willReturn('out-1');

        $this->service->method('postToShillinq')
            ->willThrowException(new \RuntimeException('SECRET TOKEN xyz'));

        $response = $this->controller->post();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        // No internal detail in the response body.
        $this->assertStringNotContainsString('SECRET TOKEN', json_encode($data));
    }//end testPostMapsUnexpectedTo500()
}//end class
