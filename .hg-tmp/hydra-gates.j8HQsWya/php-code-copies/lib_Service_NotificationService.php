<?php

   
                                
  
                                                       
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                      
                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

   
                                              
  
                                                                         
                                                                            
                                                        
                                                                           
                                                                           
                                                        
                                                                              
                                                                  
                                                                             
                                                                               
                                                                            
                                                                          
                                                                                
                     
  
                                                                              
  
                                                                                        
                                                                               
                                                                                           
                                                                                      
                                                                                                  
                                                                                           
                                                                                                 
                                                                                               
             
  
                                                                        
   
class NotificationService {
	   
                                                   
   
                              
    
	private const SUBJECT_SETTING_MAP = [
		'lead_assigned' => 'notify_assignments',
		'request_assigned' => 'notify_assignments',
		'task_assigned' => 'notify_assignments',
		'task_reassigned' => 'notify_assignments',
		'lead_stage_changed' => 'notify_stage_status',
		'request_status_changed' => 'notify_stage_status',
		'task_completed' => 'notify_stage_status',
		'task_expired' => 'notify_stage_status',
		'note_added' => 'notify_notes',
		'lead_won' => 'notify_deals',
		'lead_lost' => 'notify_deals',
	];

	   
                
   
                                                                  
                                              
                                              
    
	public function __construct(
		private IManager $notificationManager,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                     
   
                                              
                                          
                                                       
                                          
                                             
   
                
   
                                                       
    
	public function notifyAssignment(
		string $entityType,
		string $title,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		if ($author === $assigneeUserId) {
			return;
		}

		$subject = 'lead_assigned';
		if ($entityType === 'request') {
			$subject = 'request_assigned';
		} elseif ($entityType === 'task') {
			$subject = 'task_assigned';
		}

		$this->send(
			subject: $subject,
			parameters: [
				'title' => $title,
				'entityType' => $entityType,
				'author' => $author,
			],
			userId: $assigneeUserId,
			objectType: $entityType,
			objectId: $objectId
		);
	}                        

	   
                                          
   
                                          
                                                         
                                                                     
                                               
                                                          
   
                
   
                                                       
    
	public function notifyTaskCompleted(
		string $title,
		string $resultText,
		string $userId,
		string $objectId,
		string $author,
	): void {
		if ($author === $userId) {
			return;
		}

		$this->send(
			subject: 'task_completed',
			parameters: [
				'title' => $title,
				'resultText' => $resultText,
				'author' => $author,
			],
			userId: $userId,
			objectType: 'task',
			objectId: $objectId
		);
	}                           

	   
                                            
   
                                          
                                                           
                                               
                                                  
                                              
   
                
   
                                                       
    
	public function notifyTaskReassigned(
		string $title,
		string $assigneeUserId,
		string $objectId,
		string $author,
		string $deadline = '',
	): void {
		if ($author === $assigneeUserId) {
			return;
		}

		$this->send(
			subject: 'task_reassigned',
			parameters: [
				'title' => $title,
				'author' => $author,
				'deadline' => $deadline,
			],
			userId: $assigneeUserId,
			objectType: 'task',
			objectId: $objectId
		);
	}                            

	   
                                                             
   
                                          
                                             
                                               
                                              
   
                
   
                                                       
    
	public function notifyTaskExpired(
		string $title,
		string $userId,
		string $objectId,
		string $deadline = '',
	): void {
		$this->send(
			subject: 'task_expired',
			parameters: [
				'title' => $title,
				'deadline' => $deadline,
			],
			userId: $userId,
			objectType: 'task',
			objectId: $objectId
		);
	}                         

	   
                                            
   
                                          
                                               
                                                       
                                          
                                             
   
                
   
                                                       
    
	public function notifyStageChange(
		string $title,
		string $newStage,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		if ($author === $assigneeUserId) {
			return;
		}

		$this->send(
			subject: 'lead_stage_changed',
			parameters: [
				'title' => $title,
				'stage' => $newStage,
				'author' => $author,
			],
			userId: $assigneeUserId,
			objectType: 'lead',
			objectId: $objectId
		);
	}                         

	   
                                                
   
                                          
                                                 
                                                       
                                          
                                             
   
                
   
                                                       
    
	public function notifyStatusChange(
		string $title,
		string $newStatus,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		if ($author === $assigneeUserId) {
			return;
		}

		$this->send(
			subject: 'request_status_changed',
			parameters: [
				'title' => $title,
				'status' => $newStatus,
				'author' => $author,
			],
			userId: $assigneeUserId,
			objectType: 'request',
			objectId: $objectId
		);
	}                          

	   
                                                
   
                                              
                                                
                                                       
                                          
                                             
   
                
   
                                                       
    
	public function notifyNoteAdded(
		string $entityType,
		string $entityTitle,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		if ($author === $assigneeUserId) {
			return;
		}

		$this->send(
			subject: 'note_added',
			parameters: [
				'title' => $entityTitle,
				'entityType' => $entityType,
				'author' => $author,
			],
			userId: $assigneeUserId,
			objectType: $entityType,
			objectId: $objectId
		);
	}                       

	   
                                         
   
                                        
                                        
                                                       
                                          
                                             
   
                
   
                                                       
    
	public function notifyDealWon(
		string $title,
		string $value,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$this->send(
			subject: 'lead_won',
			parameters: [
				'title' => $title,
				'value' => $value,
				'author' => $author,
			],
			userId: $assigneeUserId,
			objectType: 'lead',
			objectId: $objectId
		);
	}                     

	   
                                          
   
                                        
                                                       
                                          
                                             
   
                
   
                                                                                
    
	public function notifyDealLost(
		string $title,
		string $assigneeUserId,
		string $objectId,
		string $author,
	): void {
		$this->send(
			subject: 'lead_lost',
			parameters: [
				'title' => $title,
				'author' => $author,
			],
			userId: $assigneeUserId,
			objectType: 'lead',
			objectId: $objectId
		);
	}                      

	   
                                          
   
                                                                     
                                                             
   
                                             
                                                    
                                                         
                                                         
                                                     
   
                
   
                                                                                
    
	public function sendNotification(
		string $userId,
		string $subject,
		array $parameters,
		string $objectType = '',
		string $objectId = '',
	): void {
		$this->send(
			subject: $subject,
			parameters: $parameters,
			userId: $userId,
			objectType: $objectType,
			objectId: $objectId
		);
	}                        

	   
                                  
   
                                                    
                                                         
                                             
                                              
                                          
   
                
    
	private function send(
		string $subject,
		array $parameters,
		string $userId,
		string $objectType,
		string $objectId,
	): void {
		                                                 
		$settingKey = self::SUBJECT_SETTING_MAP[$subject] ?? null;
		if ($settingKey !== null) {
			$enabled = $this->config->getUserValue(
				userId: $userId,
				appName: Application::APP_ID,
				key: $settingKey,
				default: 'true'
			);
			if ($enabled !== 'true') {
				return;
			}
		}

		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new DateTime())
				->setObject(type: $objectType, id: $objectId)
				->setSubject(subject: $subject, parameters: $parameters);

			$this->notificationManager->notify($notification);
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to send Pipelinq notification',
				[
					'subject' => $subject,
					'userId' => $userId,
					'exception' => $e->getMessage(),
				]
			);
		}
	}            
}           
