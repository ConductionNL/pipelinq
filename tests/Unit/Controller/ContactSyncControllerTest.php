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
use Psr\Log\LoggerInterface;

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
        $logger            = $this->createMock(LoggerInterface::class);

        $this->controller = new ContactSyncController(
            $this->request,
            $this->syncService,
            $userSession,
            $l10n,
            $logger,
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

    /**
     * The contact-FIRST create returns 201 with the created object when the
     * service provisions a contact and saves the client with the resolved
     * contactsUid.
     *
     * @return void
     */
    public function testCreateReturnsCreatedWithContactsUid(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['objectType', 'client', 'client'],
                ['object', [], ['name' => 'Acme BV', 'type' => 'organization', 'email' => 'a@b.test']],
            ]
        );

        $created = [
            'id'          => 'obj-1',
            'name'        => 'Acme BV',
            'email'       => 'a@b.test',
            'contactsUid' => 'uid-123',
        ];
        $this->syncService->expects($this->once())
            ->method('createWithContact')
            ->with('client', ['name' => 'Acme BV', 'type' => 'organization', 'email' => 'a@b.test'])
            ->willReturn($created);

        $response = $this->controller->create();

        $this->assertSame(201, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['success']);
        $this->assertSame('uid-123', $data['object']['contactsUid']);
        $this->assertSame('Acme BV', $data['object']['name']);
    }//end testCreateReturnsCreatedWithContactsUid()

    /**
     * The create endpoint rejects a missing name with 400 before touching the
     * service.
     *
     * @return void
     */
    public function testCreateRejectsMissingName(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['objectType', 'client', 'client'],
                ['object', [], ['type' => 'organization']],
            ]
        );

        $this->syncService->expects($this->never())->method('createWithContact');

        $response = $this->controller->create();

        $this->assertSame(400, $response->getStatus());
    }//end testCreateRejectsMissingName()

    /**
     * The create endpoint rejects an invalid objectType with 400.
     *
     * @return void
     */
    public function testCreateRejectsInvalidObjectType(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['objectType', 'client', 'invoice'],
                ['object', [], ['name' => 'Acme BV']],
            ]
        );

        $this->syncService->expects($this->never())->method('createWithContact');

        $response = $this->controller->create();

        $this->assertSame(400, $response->getStatus());
    }//end testCreateRejectsInvalidObjectType()

    /**
     * When provisioning fails (e.g. Contacts disabled) the service throws a
     * RuntimeException and the endpoint surfaces a clean 400 with the message
     * instead of an opaque 500.
     *
     * @return void
     */
    public function testCreateSurfacesProvisionFailureAs400(): void
    {
        $this->request->method('getParam')->willReturnMap(
            [
                ['objectType', 'client', 'client'],
                ['object', [], ['name' => 'Acme BV']],
            ]
        );

        $this->syncService->method('createWithContact')
            ->willThrowException(new \RuntimeException('Could not provision the Nextcloud contact'));

        $response = $this->controller->create();

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('Could not provision the Nextcloud contact', $response->getData()['error']);
    }//end testCreateSurfacesProvisionFailureAs400()
}//end class
