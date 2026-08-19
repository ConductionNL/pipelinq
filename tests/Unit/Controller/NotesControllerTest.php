<?php

/**
 * Unit tests for NotesController.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\NotesController;
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\SettingsService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for NotesController.
 */
class NotesControllerTest extends TestCase
{

    /**
     * The controller under test.
     *
     * @var NotesController
     */
    private NotesController $controller;

    /**
     * Mock notes service.
     *
     * @var NotesService
     */
    private NotesService $notesService;

    /**
     * The register/schema each objectExists() lookup was scoped to.
     *
     * @var array<int, array{register: string, schema: string}>
     */
    private array $findCalls = [];

    /**
     * The ticket payload find() hands back (drives the ticketType check).
     *
     * @var array<string, mixed>
     */
    private array $ticketPayload = ['ticketType' => 'request'];

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request            = $this->createMock(IRequest::class);
        $this->notesService = $this->createMock(NotesService::class);
        $noteEventService   = $this->createMock(NoteEventService::class);
        $userSession        = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');
        $userSession->method('getUser')->willReturn($user);
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        // Provide a settings service that returns valid register + schema IDs
        // so objectExists() can scope the OR lookup to the correct entity.
        // `request` is no longer a schema of its own (unify-ticket-supertype):
        // the `pipelinq_request` slug resolves through TicketService instead.
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')->willReturn(
                [
                    'register'       => 'reg-123',
                    'client_schema'  => 'schema-client',
                    'contact_schema' => 'schema-contact',
                    'lead_schema'    => 'schema-lead',
                ]
                );

        $ticketService = $this->createMock(TicketService::class);
        $ticketService->method('getRegisterId')->willReturn('reg-123');
        $ticketService->method('getSchemaId')->willReturn('schema-ticket');

        // Object service mock: find() returns a non-null ObjectEntity for any scoped
        // lookup, which makes objectExists() return true so subsequent controller logic
        // runs; the scope of every lookup is recorded so the tests can assert which
        // register+schema an object type resolved to.
        $objectServiceMock = $this->createMock(\OCA\OpenRegister\Service\ObjectService::class);
        $entityMock        = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
        $entityMock->method('getObject')->willReturnCallback(fn (): array => $this->ticketPayload);
        $objectServiceMock->method('find')->willReturnCallback(
            function (string $id, string $register='', string $schema='') use ($entityMock) {
                $this->findCalls[] = ['register' => $register, 'schema' => $schema];
                return $entityMock;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectServiceMock);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(true);

        $this->controller = new NotesController(
            $request,
            $this->notesService,
            $noteEventService,
            $userSession,
            $l10n,
            $logger,
            $container,
            $groupManager,
            $settingsService,
            $ticketService,
        );
    }//end setUp()

    /**
     * Test list returns 400 for invalid type.
     *
     * @return void
     */
    public function testListReturns400ForInvalidType(): void
    {
        $response = $this->controller->list('invalid_type', '123');

        $this->assertSame(400, $response->getStatus());
    }//end testListReturns400ForInvalidType()

    /**
     * Test list returns notes for valid type.
     *
     * @return void
     */
    public function testListReturnsNotes(): void
    {
        $this->notesService->method('getNotes')->willReturn(
                [
                    ['id' => '1', 'message' => 'Test note'],
                ]
                );

        $response = $this->controller->list('pipelinq_client', '123');

        $data = $response->getData();
        $this->assertCount(1, $data['notes']);
    }//end testListReturnsNotes()

    /**
     * The external `pipelinq_request` slug stays valid and resolves to the unified
     * ticket schema (unify-ticket-supertype) rather than a `request_schema`.
     *
     * @return void
     */
    public function testRequestSlugResolvesToTicketSchema(): void
    {
        $this->notesService->method('getNotes')->willReturn([]);

        $response = $this->controller->list('pipelinq_request', '123');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(
            [['register' => 'reg-123', 'schema' => 'schema-ticket']],
            $this->findCalls
        );
    }//end testRequestSlugResolvesToTicketSchema()

    /**
     * A ticket of a different subtype is not a `pipelinq_request`: the ticketType
     * discriminator must fail the existence check (404), since schema scoping alone
     * no longer distinguishes the subtypes.
     *
     * @return void
     */
    public function testRequestSlugRejectsOtherTicketSubtype(): void
    {
        $this->ticketPayload = ['ticketType' => 'complaint'];

        $response = $this->controller->list('pipelinq_request', '123');

        $this->assertSame(404, $response->getStatus());
    }//end testRequestSlugRejectsOtherTicketSubtype()

    /**
     * Test deleteAll returns 400 for invalid type.
     *
     * @return void
     */
    public function testDeleteAllReturns400ForInvalidType(): void
    {
        $response = $this->controller->deleteAll('bad_type', '123');

        $this->assertSame(400, $response->getStatus());
    }//end testDeleteAllReturns400ForInvalidType()

    /**
     * Test deleteAll returns success.
     *
     * @return void
     */
    public function testDeleteAllReturnsSuccess(): void
    {
        $this->notesService->expects($this->once())->method('deleteAllNotes');

        $response = $this->controller->deleteAll('pipelinq_lead', '456');

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testDeleteAllReturnsSuccess()

    /**
     * Test deleteSingle returns success.
     *
     * @return void
     */
    public function testDeleteSingleReturnsSuccess(): void
    {
        $this->notesService->expects($this->once())->method('deleteNote');

        $response = $this->controller->deleteSingle(1);

        $data = $response->getData();
        $this->assertTrue($data['success']);
    }//end testDeleteSingleReturnsSuccess()

    /**
     * Test deleteSingle returns 403 on permission error.
     *
     * @return void
     */
    public function testDeleteSingleReturns403OnPermissionError(): void
    {
        $this->notesService->method('deleteNote')
            ->willThrowException(new \RuntimeException('You can only delete your own notes'));

        $response = $this->controller->deleteSingle(1);

        $this->assertSame(403, $response->getStatus());
    }//end testDeleteSingleReturns403OnPermissionError()

    /**
     * deleteAll must refuse a non-admin.
     *
     * This pins the half of the check that survives dropping
     * #[NoAdminRequired] from the method. With the attribute gone,
     * SecurityMiddleware rejects a non-admin before the controller is even
     * reached — which is the point of that change — but middleware is not
     * exercised by a unit test, so the in-body isAdmin() guard is what this
     * asserts. If someone later removes the body check on the grounds that
     * "the framework handles it", this test fails.
     *
     * deleteAll wipes every note on an entity the caller need not own, so it
     * is the worst of the seven to get wrong.
     *
     * @return void
     */
    public function testDeleteAllRefusesNonAdmin(): void
    {
        $request     = $this->createMock(IRequest::class);
        $userSession = $this->createMock(IUserSession::class);
        $user        = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('regular-user');
        $userSession->method('getUser')->willReturn($user);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getSettings')->willReturn(
                [
                    'register'      => 'reg-123',
                    'client_schema' => 'schema-client',
                ]
                );

        $ticketService = $this->createMock(TicketService::class);
        $ticketService->method('getRegisterId')->willReturn('reg-123');
        $ticketService->method('getSchemaId')->willReturn('schema-ticket');

        // The notes service must never be reached: the guard has to reject
        // BEFORE any delete is attempted. Asserting on the outcome alone
        // would still pass if the guard ran after the wipe.
        $notesService = $this->createMock(NotesService::class);
        $notesService->expects($this->never())->method('deleteAllNotes');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);

        $controller = new NotesController(
            $request,
            $notesService,
            $this->createMock(NoteEventService::class),
            $userSession,
            $l10n,
            $this->createMock(\Psr\Log\LoggerInterface::class),
            $this->createMock(ContainerInterface::class),
            $groupManager,
            $settingsService,
            $ticketService,
        );

        $response = $controller->deleteAll('pipelinq_client', 'obj-1');

        $this->assertSame(403, $response->getStatus());
    }//end testDeleteAllRefusesNonAdmin()
}//end class
