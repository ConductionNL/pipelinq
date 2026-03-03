<?php

/**
 * Pipelinq NotesController.
 *
 * Controller for managing notes (comments) on Pipelinq entities.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NoteEventService;
use OCA\Pipelinq\Service\NotesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for managing notes on Pipelinq entities.
 */
class NotesController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param NotesService     $notesService     The notes service.
     * @param NoteEventService $noteEventService The note event service.
     * @param LoggerInterface  $logger           The logger.
     */
    public function __construct(
        IRequest $request,
        private NotesService $notesService,
        private NoteEventService $noteEventService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }//end __construct()

    /**
     * List notes for an entity.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return JSONResponse The response containing notes.
     *
     * @NoAdminRequired
     */
    public function list(string $objectType, string $objectId): JSONResponse
    {
        if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
            return new JSONResponse(['error' => 'Invalid object type'], 400);
        }

        try {
            $notes = $this->notesService->getNotes(
                objectType: $objectType,
                objectId: $objectId
            );
            return new JSONResponse(['notes' => $notes]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end list()

    /**
     * Create a note on an entity.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return JSONResponse The response containing the created note.
     *
     * @NoAdminRequired
     */
    public function create(string $objectType, string $objectId): JSONResponse
    {
        if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
            return new JSONResponse(['error' => 'Invalid object type'], 400);
        }

        $message = $this->request->getParam('message', '');
        if (trim($message) === '') {
            return new JSONResponse(['error' => 'Message is required'], 400);
        }

        try {
            $note = $this->notesService->addNote(
                objectType: $objectType,
                objectId: $objectId,
                message: $message
            );

            $this->noteEventService->triggerNoteEvents(
                objectType: $objectType,
                objectId: $objectId
            );

            return new JSONResponse(['note' => $note]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end create()

    /**
     * Delete all notes for an entity (cleanup on entity deletion).
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return JSONResponse The response.
     *
     * @NoAdminRequired
     */
    public function deleteAll(string $objectType, string $objectId): JSONResponse
    {
        if (in_array($objectType, NotesService::VALID_TYPES, true) === false) {
            return new JSONResponse(['error' => 'Invalid object type'], 400);
        }

        try {
            $this->notesService->deleteAllNotes(
                objectType: $objectType,
                objectId: $objectId
            );
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }//end deleteAll()

    /**
     * Delete a single note (own notes only).
     *
     * @param int $noteId The note ID.
     *
     * @return JSONResponse The response.
     *
     * @NoAdminRequired
     */
    public function deleteSingle(int $noteId): JSONResponse
    {
        try {
            $this->notesService->deleteNote(noteId: $noteId);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], 403);
        }
    }//end deleteSingle()
}//end class
