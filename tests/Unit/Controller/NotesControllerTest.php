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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\NotesController;
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotesService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

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
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $request            = $this->createMock(IRequest::class);
        $this->notesService = $this->createMock(NotesService::class);
        $noteEventService   = $this->createMock(NoteEventService::class);
        $l10n               = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new NotesController(
            $request,
            $this->notesService,
            $noteEventService,
            $l10n,
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
        $this->notesService->method('getNotes')->willReturn([
            ['id' => '1', 'message' => 'Test note'],
        ]);

        $response = $this->controller->list('pipelinq_client', '123');

        $data = $response->getData();
        $this->assertCount(1, $data['notes']);
    }//end testListReturnsNotes()

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
}//end class
