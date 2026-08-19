<?php

/**
 * Unit tests for NotesService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\NotesService;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NotesService.
 */
class NotesServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var NotesService
     */
    private NotesService $service;

    /**
     * Mock comments manager.
     *
     * @var ICommentsManager
     */
    private ICommentsManager $commentsManager;

    /**
     * Mock user session.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Mock user manager.
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->commentsManager = $this->createMock(ICommentsManager::class);
        $this->userSession     = $this->createMock(IUserSession::class);
        $this->userManager     = $this->createMock(IUserManager::class);

        $this->service = new NotesService(
            $this->commentsManager,
            $this->userSession,
            $this->userManager,
        );
    }//end setUp()

    /**
     * Test valid types constant contains expected entity types.
     *
     * @return void
     */
    public function testValidTypesContainsExpectedTypes(): void
    {
        $this->assertContains('pipelinq_client', NotesService::VALID_TYPES);
        $this->assertContains('pipelinq_contact', NotesService::VALID_TYPES);
        $this->assertContains('pipelinq_lead', NotesService::VALID_TYPES);
        $this->assertContains('pipelinq_request', NotesService::VALID_TYPES);
        $this->assertCount(4, NotesService::VALID_TYPES);
    }//end testValidTypesContainsExpectedTypes()

    /**
     * Test getNotes returns notes in reverse chronological order.
     *
     * @return void
     */
    public function testGetNotesReturnsReversed(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $comment1 = $this->createMock(IComment::class);
        $comment1->method('getId')->willReturn('1');
        $comment1->method('getMessage')->willReturn('First note');
        $comment1->method('getActorId')->willReturn('admin');
        $comment1->method('getCreationDateTime')->willReturn(new \DateTime('2024-01-01'));

        $comment2 = $this->createMock(IComment::class);
        $comment2->method('getId')->willReturn('2');
        $comment2->method('getMessage')->willReturn('Second note');
        $comment2->method('getActorId')->willReturn('admin');
        $comment2->method('getCreationDateTime')->willReturn(new \DateTime('2024-01-02'));

        $this->commentsManager->method('getForObject')->willReturn([$comment1, $comment2]);

        $mockUser = $this->createMock(IUser::class);
        $mockUser->method('getDisplayName')->willReturn('Admin User');
        $this->userManager->method('get')->willReturn($mockUser);

        $result = $this->service->getNotes('pipelinq_client', '123');

        $this->assertCount(2, $result);
        $this->assertSame('Second note', $result[0]['message']);
        $this->assertSame('First note', $result[1]['message']);
    }//end testGetNotesReturnsReversed()

    /**
     * Test getNotes sets isOwn flag correctly.
     *
     * @return void
     */
    public function testGetNotesIsOwnFlag(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $ownComment = $this->createMock(IComment::class);
        $ownComment->method('getId')->willReturn('1');
        $ownComment->method('getMessage')->willReturn('My note');
        $ownComment->method('getActorId')->willReturn('admin');
        $ownComment->method('getCreationDateTime')->willReturn(new \DateTime());

        $otherComment = $this->createMock(IComment::class);
        $otherComment->method('getId')->willReturn('2');
        $otherComment->method('getMessage')->willReturn('Other note');
        $otherComment->method('getActorId')->willReturn('other_user');
        $otherComment->method('getCreationDateTime')->willReturn(new \DateTime());

        $this->commentsManager->method('getForObject')->willReturn([$ownComment, $otherComment]);
        $this->userManager->method('get')->willReturn(null);

        $result = $this->service->getNotes('pipelinq_lead', '456');

        // Reversed order, so otherComment is first.
        $this->assertFalse($result[0]['isOwn']);
        $this->assertTrue($result[1]['isOwn']);
    }//end testGetNotesIsOwnFlag()

    /**
     * Test addNote throws when no user is authenticated.
     *
     * @return void
     */
    public function testAddNoteThrowsWithoutUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No authenticated user');

        $this->service->addNote('pipelinq_client', '123', 'Test note');
    }//end testAddNoteThrowsWithoutUser()

    /**
     * Test addNote trims the message.
     *
     * @return void
     */
    public function testAddNoteTrimsMessage(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $user->method('getDisplayName')->willReturn('Admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->userManager->method('get')->willReturn($user);

        $comment = $this->createMock(IComment::class);
        $comment->method('getId')->willReturn('1');
        $comment->method('getMessage')->willReturn('Trimmed message');
        $comment->method('getCreationDateTime')->willReturn(new \DateTime());

        $comment->expects($this->once())->method('setMessage')->with('Trimmed message');
        $comment->expects($this->once())->method('setVerb')->with('comment');

        $this->commentsManager->method('create')->willReturn($comment);

        $result = $this->service->addNote('pipelinq_client', '123', '  Trimmed message  ');

        $this->assertTrue($result['isOwn']);
    }//end testAddNoteTrimsMessage()

    /**
     * Test deleteNote throws when not the author.
     *
     * @return void
     */
    public function testDeleteNoteThrowsWhenNotAuthor(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $comment = $this->createMock(IComment::class);
        $comment->method('getActorId')->willReturn('other_user');
        $this->commentsManager->method('get')->willReturn($comment);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('You can only delete your own notes');

        $this->service->deleteNote(1);
    }//end testDeleteNoteThrowsWhenNotAuthor()

    /**
     * Test deleteNote throws without authentication.
     *
     * @return void
     */
    public function testDeleteNoteThrowsWithoutAuth(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->service->deleteNote(1);
    }//end testDeleteNoteThrowsWithoutAuth()

    /**
     * Test deleteAllNotes delegates to comments manager.
     *
     * @return void
     */
    public function testDeleteAllNotesDelegates(): void
    {
        $this->commentsManager
            ->expects($this->once())
            ->method('deleteCommentsAtObject')
            ->with('pipelinq_client', '123');

        $this->service->deleteAllNotes('pipelinq_client', '123');
    }//end testDeleteAllNotesDelegates()
}//end class
