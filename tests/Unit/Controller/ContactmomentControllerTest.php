<?php

/**
 * Unit tests for ContactmomentController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactmomentController;
use OCA\Pipelinq\Service\ContactmomentService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContactmomentController.
 */
class ContactmomentControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var ContactmomentController
     */
    private ContactmomentController $controller;

    /**
     * Mock contactmoment service.
     *
     * @var ContactmomentService
     */
    private ContactmomentService $contactmomentService;

    /**
     * Mock user session.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request                    = $this->createMock(IRequest::class);
        $this->contactmomentService = $this->createMock(ContactmomentService::class);
        $this->userSession          = $this->createMock(IUserSession::class);
        $l10n                       = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new ContactmomentController(
            $request,
            $this->contactmomentService,
            $this->userSession,
            $l10n,
        );
    }//end setUp()

    /**
     * Test destroy returns 401 when no user is authenticated.
     *
     * @return void
     */
    public function testDestroyReturns401WhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->controller->destroy('test-id');

        $this->assertSame(401, $response->getStatus());
    }//end testDestroyReturns401WhenNoUser()

    /**
     * Test destroy returns 200 on successful deletion.
     *
     * @return void
     */
    public function testDestroyReturns200OnSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->contactmomentService
            ->expects($this->once())
            ->method('delete')
            ->with('test-id', 'agent-user')
            ->willReturn(true);

        $response = $this->controller->destroy('test-id');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testDestroyReturns200OnSuccess()

    /**
     * Test destroy returns 404 when contactmoment not found.
     *
     * @return void
     */
    public function testDestroyReturns404WhenNotFound(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->contactmomentService
            ->method('delete')
            ->willThrowException(new DoesNotExistException('Not found'));

        $response = $this->controller->destroy('nonexistent-id');

        $this->assertSame(404, $response->getStatus());
    }//end testDestroyReturns404WhenNotFound()

    /**
     * Test destroy returns 403 when user lacks permission.
     *
     * @return void
     */
    public function testDestroyReturns403WhenForbidden(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('other-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->contactmomentService
            ->method('delete')
            ->willThrowException(new NotPermittedException('Not permitted'));

        $response = $this->controller->destroy('test-id');

        $this->assertSame(403, $response->getStatus());
    }//end testDestroyReturns403WhenForbidden()

    /**
     * Test destroy returns 500 on unexpected error.
     *
     * @return void
     */
    public function testDestroyReturns500OnError(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('agent-user');
        $this->userSession->method('getUser')->willReturn($user);

        $this->contactmomentService
            ->method('delete')
            ->willThrowException(new \RuntimeException('Unexpected error'));

        $response = $this->controller->destroy('test-id');

        $this->assertSame(500, $response->getStatus());
    }//end testDestroyReturns500OnError()
}//end class
