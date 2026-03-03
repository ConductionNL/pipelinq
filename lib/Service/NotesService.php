<?php

/**
 * Pipelinq NotesService.
 *
 * Service for managing notes (comments) on Pipelinq entities.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for managing notes on Pipelinq entities.
 */
class NotesService
{
    public const VALID_TYPES = [
        'pipelinq_client',
        'pipelinq_contact',
        'pipelinq_lead',
        'pipelinq_request',
    ];

    /**
     * Constructor.
     *
     * @param ICommentsManager $commentsManager The comments manager.
     * @param IUserSession     $userSession     The user session.
     * @param IUserManager     $userManager     The user manager.
     * @param LoggerInterface  $logger          The logger.
     */
    public function __construct(
        private ICommentsManager $commentsManager,
        private IUserSession $userSession,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get all notes for a given entity.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return array The notes.
     */
    public function getNotes(string $objectType, string $objectId): array
    {
        $currentUserId = $this->userSession->getUser()?->getUID();
        $comments      = $this->commentsManager->getForObject(
            objectType: $objectType,
            objectId: $objectId,
            limit: 200,
            offset: 0
        );

        $notes = [];
        foreach ($comments as $comment) {
            $authorId   = $comment->getActorId();
            $authorName = $authorId;

            $user = $this->userManager->get($authorId);
            if ($user !== null) {
                $authorName = $user->getDisplayName();
            }

            $notes[] = [
                'id'         => (int) $comment->getId(),
                'message'    => $comment->getMessage(),
                'authorId'   => $authorId,
                'authorName' => $authorName,
                'timestamp'  => $comment->getCreationDateTime()->format('c'),
                'isOwn'      => $authorId === $currentUserId,
            ];
        }

        // Reverse to show newest first.
        return array_reverse($notes);
    }//end getNotes()

    /**
     * Add a note to an entity.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     * @param string $message    The note message.
     *
     * @return array The created note.
     */
    public function addNote(string $objectType, string $objectId, string $message): array
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            throw new RuntimeException('No authenticated user');
        }

        $comment = $this->commentsManager->create(
            actorType: 'users',
            actorId: $userId,
            objectType: $objectType,
            objectId: $objectId
        );
        $comment->setMessage(trim($message));
        $comment->setVerb('comment');
        $this->commentsManager->save($comment);

        $user = $this->userManager->get($userId);

        return [
            'id'         => (int) $comment->getId(),
            'message'    => $comment->getMessage(),
            'authorId'   => $userId,
            'authorName' => $user?->getDisplayName() ?? $userId,
            'timestamp'  => $comment->getCreationDateTime()->format('c'),
            'isOwn'      => true,
        ];
    }//end addNote()

    /**
     * Delete a single note. Only the author may delete their own note.
     *
     * @param int $noteId The note ID.
     *
     * @return void
     */
    public function deleteNote(int $noteId): void
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            throw new RuntimeException('No authenticated user');
        }

        $comment = $this->commentsManager->get((string) $noteId);
        if ($comment->getActorId() !== $userId) {
            throw new RuntimeException('You can only delete your own notes');
        }

        $this->commentsManager->delete((string) $noteId);
    }//end deleteNote()

    /**
     * Delete all notes for an entity (used when entity is deleted).
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return void
     */
    public function deleteAllNotes(string $objectType, string $objectId): void
    {
        $this->commentsManager->deleteCommentsAtObject(
            objectType: $objectType,
            objectId: $objectId
        );
    }//end deleteAllNotes()
}//end class
