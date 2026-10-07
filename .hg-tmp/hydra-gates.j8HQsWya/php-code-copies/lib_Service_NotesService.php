<?php

   
                         
  
                                                              
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\Comments\ICommentsManager;
use OCP\IUserManager;
use OCP\IUserSession;
use RuntimeException;

   
                                                   
  
                                                                                           
   
class NotesService {
	public const VALID_TYPES = [
		'pipelinq_client',
		'pipelinq_contact',
		'pipelinq_lead',
		'pipelinq_request',
	];

	   
                
   
                                                                  
                                                      
                                                      
    
	public function __construct(
		private ICommentsManager $commentsManager,
		private IUserSession $userSession,
		private IUserManager $userManager,
	) {
	}                   

	   
                                     
   
                                              
                                          
   
                            
   
                                             
    
	public function getNotes(string $objectType, string $objectId): array {
		$currentUserId = $this->userSession->getUser()?->getUID();
		$comments = $this->commentsManager->getForObject(
			objectType: $objectType,
			objectId: $objectId,
			limit: 200,
			offset: 0
		);

		$notes = [];
		foreach ($comments as $comment) {
			$authorId = $comment->getActorId();
			$authorName = $authorId;

			$user = $this->userManager->get($authorId);
			if ($user !== null) {
				$authorName = $user->getDisplayName();
			}

			$notes[] = [
				'id' => (int)$comment->getId(),
				'message' => $comment->getMessage(),
				'authorId' => $authorId,
				'authorName' => $authorName,
				'timestamp' => $comment->getCreationDateTime()->format('c'),
				'isOwn' => $authorId === $currentUserId,
			];
		}

		                                
		return array_reverse($notes);
	}                

	   
                            
   
                                              
                                          
                                            
   
                                   
   
                                             
    
	public function addNote(string $objectType, string $objectId, string $message): array {
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
			'id' => (int)$comment->getId(),
			'message' => $comment->getMessage(),
			'authorId' => $userId,
			'authorName' => $user?->getDisplayName() ?? $userId,
			'timestamp' => $comment->getCreationDateTime()->format('c'),
			'isOwn' => true,
		];
	}               

	   
                                                                    
   
                                   
   
                
   
                                             
    
	public function deleteNote(int $noteId): void {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			throw new RuntimeException('No authenticated user');
		}

		$comment = $this->commentsManager->get((string)$noteId);
		if ($comment->getActorId() !== $userId) {
			throw new RuntimeException('You can only delete your own notes');
		}

		$this->commentsManager->delete((string)$noteId);
	}                  

	   
                                                                 
   
                                              
                                          
   
                
   
                                             
    
	public function deleteAllNotes(string $objectType, string $objectId): void {
		$this->commentsManager->deleteCommentsAtObject(
			objectType: $objectType,
			objectId: $objectId
		);
	}                      
}           
