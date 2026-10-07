<?php

   
                            
  
                                                                                    
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                                         
                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Activity\IManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                   
  
                                                                            
                                                                          
                                                                  
                                                               
                                                                         
                                                                        
                                                                     
                                                                            
  
                                                                              
   
class ActivityService {
	   
                
   
                                                          
                                                      
                                              
    
	public function __construct(
		private IManager $activityManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                  
   
                                              
                                          
                                          
                                                           
   
                
   
                                                                                                          
    
	public function publishCreated(
		string $entityType,
		string $title,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$type = 'lead_created';
		if ($entityType === 'request') {
			$type = 'request_created';
		}

		$this->publish(
			subject: $type,
			type: 'pipelinq_assignment',
			parameters: [
				'title' => $title,
				'entityType' => $entityType,
			],
			objectType: $entityType,
			objectId: $objectId,
			affectedUser: $affectedUser
		);
	}                      

	   
                                                      
   
                                              
                                          
                                                       
                                          
   
                
   
                                                                                                          
    
	public function publishAssigned(
		string $entityType,
		string $title,
		string $newAssignee,
		string $objectId,
	): void {
		$type = 'lead_assigned';
		if ($entityType === 'request') {
			$type = 'request_assigned';
		}

		$this->publish(
			subject: $type,
			type: 'pipelinq_assignment',
			parameters: [
				'title' => $title,
				'entityType' => $entityType,
				'assignee' => $newAssignee,
			],
			objectType: $entityType,
			objectId: $objectId,
			affectedUser: $newAssignee
		);
	}                       

	   
                                            
   
                                          
                                               
                                          
                                                           
   
                
   
                                                                                                          
    
	public function publishStageChanged(
		string $title,
		string $newStage,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$this->publish(
			subject: 'lead_stage_changed',
			type: 'pipelinq_stage_status',
			parameters: [
				'title' => $title,
				'stage' => $newStage,
			],
			objectType: 'lead',
			objectId: $objectId,
			affectedUser: $affectedUser
		);
	}                           

	   
                                                
   
                                          
                                                 
                                          
                                                           
   
                
   
                                                                                                          
    
	public function publishStatusChanged(
		string $title,
		string $newStatus,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$this->publish(
			subject: 'request_status_changed',
			type: 'pipelinq_stage_status',
			parameters: [
				'title' => $title,
				'status' => $newStatus,
			],
			objectType: 'request',
			objectId: $objectId,
			affectedUser: $affectedUser
		);
	}                            

	   
                               
   
                                              
                                                
                                          
                                                           
   
                
   
                                                                                                          
    
	public function publishNoteAdded(
		string $entityType,
		string $entityTitle,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$this->publish(
			subject: 'note_added',
			type: 'pipelinq_notes',
			parameters: [
				'title' => $entityTitle,
				'entityType' => $entityType,
			],
			objectType: $entityType,
			objectId: $objectId,
			affectedUser: $affectedUser
		);
	}                        

	   
                             
   
                                        
                                        
                                          
                                                           
   
                
   
                                                       
    
	public function publishDealWon(
		string $title,
		string $value,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$this->publish(
			subject: 'lead_won',
			type: 'pipelinq_deals',
			parameters: [
				'title' => $title,
				'value' => $value,
			],
			objectType: 'lead',
			objectId: $objectId,
			affectedUser: $affectedUser
		);
	}                      

	   
                              
   
                                        
                                          
                                                           
   
                
   
                                                                                
    
	public function publishDealLost(
		string $title,
		string $objectId,
		?string $affectedUser = null,
	): void {
		$this->publish(
			subject: 'lead_lost',
			type: 'pipelinq_deals',
			parameters: [
				'title' => $title,
			],
			objectType: 'lead',
			objectId: $objectId,
			affectedUser: $affectedUser
		);
	}                       

	   
                              
   
                                                
                                          
                                                     
                                              
                                          
                                                           
   
                
    
	private function publish(
		string $subject,
		string $type,
		array $parameters,
		string $objectType,
		string $objectId,
		?string $affectedUser = null,
	): void {
		try {
			$currentUser = $this->userSession->getUser();
			$author = '';
			if ($currentUser !== null) {
				$author = $currentUser->getUID();
			}

			$event = $this->activityManager->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType($type)
				->setAuthor($author)
				->setTimestamp(time())
				->setSubject(subject: $subject, parameters: $parameters)
				->setObject(objectType: $objectType, objectId: (int)$objectId, objectName: $parameters['title'] ?? '');

			if ($affectedUser !== null && $affectedUser !== '') {
				$event->setAffectedUser($affectedUser);
			} elseif ($author !== '') {
				$event->setAffectedUser($author);
			}

			$this->activityManager->publish($event);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to publish Pipelinq activity',
				[
					'subject' => $subject,
					'type' => $type,
					'exception' => $e->getMessage(),
				]
			);
		}         
	}               
}           
