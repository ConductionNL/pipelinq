<?php

   
                             
  
                                                                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                
  
                                                                                           
   
class NoteEventService {
	private const TYPE_MAP = [
		'pipelinq_client' => 'client',
		'pipelinq_contact' => 'contact',
		'pipelinq_lead' => 'lead',
		'pipelinq_request' => 'request',
	];

	   
                
   
                                                                             
                                                                 
                                                                 
                                                      
                                                                                  
                                              
    
	public function __construct(
		private NotificationService $notificationService,
		private ActivityService $activityService,
		private SettingsService $settingsService,
		private IUserSession $userSession,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                    
   
                                              
                                          
   
                
   
                                             
    
	public function triggerNoteEvents(string $objectType, string $objectId): void {
		try {
			$entityType = self::TYPE_MAP[$objectType] ?? null;
			if ($entityType === null) {
				return;
			}

			                                                                 
			                                                                
			                                                      

			$entityData = $this->fetchEntityData(
				entityType: $entityType,
				objectId: $objectId
			);

			if ($entityData === null) {
				return;
			}

			$this->publishNoteActivity(
				entityType: $entityType,
				entityData: $entityData,
				objectId: $objectId
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to trigger note events',
				[
					'objectType' => $objectType,
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
				]
			);
		}         
	}                         

	   
                                                               
   
                                              
                                          
   
                                                                               
    
	private function fetchEntityData(string $entityType, string $objectId): ?array {
		$settings = $this->settingsService->getSettings();
		$register = $settings['register'] ?? '';
		$schemaKey = $entityType . '_schema';
		$schema = $settings[$schemaKey] ?? '';

		if ($register === '' || $schema === '') {
			return null;
		}

		                                                                       
		                                                                    
		                                                                      
		                                                                     
		                                                                       
		                                                                       
		                 
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(
				id: $objectId,
				register: $register,
				schema: $schema
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Could not read note entity from OpenRegister',
				[
					'entityType' => $entityType,
					'objectId' => $objectId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}         

		return $this->toArray(row: $object);
	}                       

	   
                                                      
   
                                                          
   
                                                                          
    
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'jsonSerialize') === true) {
				$serialized = $row->jsonSerialize();
				if (is_array($serialized) === true) {
					return $serialized;
				}
			}

			if (method_exists($row, 'getObject') === true) {
				$inner = $row->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return null;
	}               

	   
                                                          
   
                                              
                                                               
                                          
   
                
    
	private function publishNoteActivity(string $entityType, array $entityData, string $objectId): void {
		$entityTitle = $entityData['title'] ?? $entityType . ' ' . $objectId;
		$assignee = $entityData['assignee'] ?? '';

		$currentUser = $this->userSession->getUser();
		$author = '';
		if ($currentUser !== null) {
			$author = $currentUser->getUID();
		}

		$assigneeOrNull = null;
		if ($assignee !== '') {
			$assigneeOrNull = $assignee;
		}

		$this->activityService->publishNoteAdded(
			entityType: $entityType,
			entityTitle: $entityTitle,
			objectId: $objectId,
			affectedUser: $assigneeOrNull
		);

		if ($assignee !== '') {
			$this->notificationService->notifyNoteAdded(
				entityType: $entityType,
				entityTitle: $entityTitle,
				assigneeUserId: $assignee,
				objectId: $objectId,
				author: $author
			);
		}
	}                           
}           
