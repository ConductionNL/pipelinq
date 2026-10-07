<?php

   
                                  
  
                                                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IUserSession;

   
                                                                     
  
                                                                      
   
class ObjectEventDispatcher {
	   
                
   
                                                                       
                                                                 
                                                      
    
	public function __construct(
		private NotificationService $notifyService,
		private ActivityService $activityService,
		private IUserSession $userSession,
	) {
	}                   

	   
                                              
   
                                              
                                          
                                          
                                                 
   
                
                                                                         
    
	public function dispatchCreated(string $entityType, string $title, string $objectId, string $assignee): void {
		$this->activityService->publishCreated(
			entityType: $entityType,
			title: $title,
			objectId: $objectId,
			affectedUser: $this->nullIfEmpty(value: $assignee)
		);

		if ($assignee !== '') {
			$author = $this->getCurrentUser();
			$this->notifyService->notifyAssignment(
				entityType: $entityType,
				title: $title,
				assigneeUserId: $assignee,
				objectId: $objectId,
				author: $author
			);
		}
	}                       

	   
                                    
   
                                              
                                          
                                          
                                                     
   
                
                                                                         
    
	public function dispatchAssigneeChange(
		string $entityType,
		string $title,
		string $objectId,
		string $assignee,
	): void {
		$this->activityService->publishAssigned(
			entityType: $entityType,
			title: $title,
			newAssignee: $assignee,
			objectId: $objectId
		);
		$this->notifyService->notifyAssignment(
			entityType: $entityType,
			title: $title,
			assigneeUserId: $assignee,
			objectId: $objectId,
			author: $this->getCurrentUser()
		);
	}                              

	   
                                            
   
                                          
                                          
                                               
                                                 
   
                
                                                                         
    
	public function dispatchStageChange(string $title, string $objectId, string $newStage, string $assignee): void {
		$this->activityService->publishStageChanged(
			title: $title,
			newStage: $newStage,
			objectId: $objectId,
			affectedUser: $this->nullIfEmpty(value: $assignee)
		);

		if ($assignee !== '') {
			$this->notifyService->notifyStageChange(
				title: $title,
				newStage: $newStage,
				assigneeUserId: $assignee,
				objectId: $objectId,
				author: $this->getCurrentUser()
			);
		}
	}                           

	   
                                                
   
                                          
                                          
                                                 
                                                 
   
                
                                                                         
    
	public function dispatchStatusChange(string $title, string $objectId, string $newStatus, string $assignee): void {
		$this->activityService->publishStatusChanged(
			title: $title,
			newStatus: $newStatus,
			objectId: $objectId,
			affectedUser: $this->nullIfEmpty(value: $assignee)
		);

		if ($assignee !== '') {
			$this->notifyService->notifyStatusChange(
				title: $title,
				newStatus: $newStatus,
				assigneeUserId: $assignee,
				objectId: $objectId,
				author: $this->getCurrentUser()
			);
		}
	}                            

	   
                                        
   
                                        
                                        
                                          
                                                 
   
                
                                                                         
    
	public function dispatchDealWon(string $title, string $value, string $objectId, string $assignee): void {
		$this->activityService->publishDealWon(
			title: $title,
			value: $value,
			objectId: $objectId,
			affectedUser: $this->nullIfEmpty(value: $assignee)
		);

		if ($assignee !== '') {
			$this->notifyService->notifyDealWon(
				title: $title,
				value: $value,
				assigneeUserId: $assignee,
				objectId: $objectId,
				author: $this->getCurrentUser()
			);
		}
	}                       

	   
                                         
   
                                        
                                          
                                                 
   
                
                                                                         
    
	public function dispatchDealLost(string $title, string $objectId, string $assignee): void {
		$this->activityService->publishDealLost(
			title: $title,
			objectId: $objectId,
			affectedUser: $this->nullIfEmpty(value: $assignee)
		);

		if ($assignee !== '') {
			$this->notifyService->notifyDealLost(
				title: $title,
				assigneeUserId: $assignee,
				objectId: $objectId,
				author: $this->getCurrentUser()
			);
		}
	}                        

	   
                                                                  
   
                                            
   
                                      
    
	private function nullIfEmpty(string $value): ?string {
		if ($value !== '') {
			return $value;
		}

		return null;
	}                   

	   
                            
   
                                                       
    
	private function getCurrentUser(): string {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $user->getUID();
		}

		return '';
	}                      
}           
