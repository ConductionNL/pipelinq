<?php

/**
 * Unit tests for ContactSyncController.
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

use OCA\Pipelinq\Controller\ContactSyncController;
use OCA\Pipelinq\Service\ContactSyncService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContactSyncController.
 */
class ContactSyncControllerTest extends TestCase
{
    /**
     * The controller under test.
     *
     * @var ContactSyncController
     */
    private ContactSyncController $controller;

    /**
     * Mock sync service.
     *
     * @var ContactSyncService
     */
    private ContactSyncService $syncService;

    /**
     * Mock request.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->syncService = $this->createMock(ContactSyncService::class);
        $userSession       = $this->createMock(IUserSession::class);
        $user              = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $userSession->method('getUser')->willReturn($user);
        $l10n              = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new ContactSyncController(
            $this->request,
            $this->syncService,
            $userSession,
            $l10n,
        );
    }//end setUp()

    /**
     * Test search returns results.
     *
     * @return void
     */
    public function testSearchReturnsResults(): void
    {
        $this->request->method('getParam')->willReturn('test query');
        $this->syncService->method('searchContacts')->willReturn([
            ['FN' => 'Test User'],
        ]);

        $response = $this->controller->search();

        $this->assertSame(200, $response->getStatus());
    }//end testSearchReturnsResults()
}//end class
