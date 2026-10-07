<?php

   
                            
  
                                                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                           
  
                                                                 
                                                      
  
                                                               
                                                                                
   
class CallbackService {
	   
                                                            
   
            
    
	public const ATTEMPT_THRESHOLD = 3;

	   
                                                                                
   
               
    
	private const TASK_SCHEMA_SLUG = 'crmTask';

	   
                                                                             
                                                                  
                                                                                  
   
                                                                            
   
                                     
    
	public const ALLOWED_TRANSITIONS = [
		'open' => ['in_progress'],
		'in_progress' => ['completed', 'expired'],
		'completed' => ['open'],
		'expired' => ['open'],
	];

	   
                                                           
   
                      
    
	private const UNSUCCESSFUL_RESULTS = [
		'niet_bereikbaar',
		'niet_bereikt',
		'geen_gehoor',
		'voicemail',
	];

	   
                
   
                                                         
                                                      
                                              
                                                                                            
    
	public function __construct(
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private SchemaLifecycleGraph $lifecycleGraph = new SchemaLifecycleGraph(),
	) {
	}                   

	   
                                                                         
   
                                                                              
                                                                                
                                                                               
                                                                   
   
                                                                          
    
	private function transitionGraph(): array {
		$graph = $this->lifecycleGraph->adjacencyFor(schemaSlug: self::TASK_SCHEMA_SLUG);
		if ($graph === []) {
			return self::ALLOWED_TRANSITIONS;
		}

		return $graph;
	}                       

	   
                                                      
   
                                                              
                                             
                                                
   
                                                                             
   
                                                                
    
	public function addAttempt(array $taskData, string $result, string $notes = ''): array {
		$attempts = $taskData['attempts'] ?? [];

		$user = $this->userSession->getUser();
		$agentUserId = 'system';
		if ($user !== null) {
			$agentUserId = $user->getUID();
		}

		$attempts[] = [
			'timestamp' => (new DateTime())->format(DateTime::ATOM),
			'result' => $result,
			'notes' => $notes,
			'agentUserId' => $agentUserId,
		];

		$taskData['attempts'] = $attempts;

		return $taskData;
	}                  

	   
                                                               
   
                                                              
   
                                                              
   
                                                        
   
                                                                
    
	public function isAttemptThresholdReached(array $taskData): bool {
		$attempts = $taskData['attempts'] ?? [];
		$unsuccessfulCount = 0;

		foreach ($attempts as $attempt) {
			if (in_array($attempt['result'] ?? '', self::UNSUCCESSFUL_RESULTS, true) === true) {
				$unsuccessfulCount++;
			}
		}

		return $unsuccessfulCount >= self::ATTEMPT_THRESHOLD;
	}                                 

	   
                                                                      
   
                                                              
   
                                                                    
   
                                                                
    
	public function validateClaim(array $taskData): array {
		$groupId = $taskData['assigneeGroupId'] ?? null;

		if (empty($groupId) === true) {
			return [
				'eligible' => false,
				'reason' => 'Task is not assigned to a group',
			];
		}

		if (empty($taskData['assigneeUserId']) === false) {
			return [
				'eligible' => false,
				'reason' => 'Task is already claimed by a user',
			];
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return [
				'eligible' => false,
				'reason' => 'No authenticated user',
			];
		}

		$isMember = $this->groupManager->isInGroup($user->getUID(), $groupId);

		if ($isMember === false) {
			return [
				'eligible' => false,
				'reason' => 'User is not a member of the assigned group',
			];
		}

		return [
			'eligible' => true,
			'reason' => '',
		];
	}                     

	   
                                                    
   
                                                         
                                                       
   
                                                                 
   
                                                                
    
	public function validateStatusTransition(string $currentStatus, string $targetStatus): array {
		$graph = $this->transitionGraph();
		$allowed = $graph[$currentStatus] ?? [];

		if (in_array($targetStatus, $allowed, true) === false) {
			return [
				'valid' => false,
				'reason' => "Transition from '{$currentStatus}' to '{$targetStatus}' is not allowed",
			];
		}

		return [
			'valid' => true,
			'reason' => '',
		];
	}                                

	   
                                                                                
   
                                                              
   
                                                        
   
                                                                
    
	public function applyClaim(array $taskData): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $taskData;
		}

		$taskData['assigneeUserId'] = $user->getUID();
		$taskData['assigneeGroupId'] = null;
		$taskData['status'] = 'in_progress';

		return $taskData;
	}                  

	   
                                                                        
   
                                                              
                                                         
   
                                                        
   
                                                                
    
	public function applyCompletion(array $taskData, string $resultText): array {
		$taskData['status'] = 'completed';
		$taskData['completedAt'] = (new DateTime())->format(DateTime::ATOM);
		$taskData['resultText'] = $resultText;

		return $taskData;
	}                       

	   
                                 
   
                                                              
                                                
                                                                  
   
                                                        
   
                                                                
    
	public function applyReassignment(array $taskData, string $assignee, string $assigneeType): array {
		if ($assigneeType === 'user') {
			$taskData['assigneeUserId'] = $assignee;
			$taskData['assigneeGroupId'] = null;

			return $taskData;
		}

		$taskData['assigneeGroupId'] = $assignee;
		$taskData['assigneeUserId'] = null;

		return $taskData;
	}                         
}           
