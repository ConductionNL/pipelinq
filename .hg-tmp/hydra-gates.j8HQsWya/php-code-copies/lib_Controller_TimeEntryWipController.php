<?php

   
                                   
  
                                                                            
                                                                      
                                                                    
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqWipService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                     
  
                                                                          
                                                                               
                                                                           
                                  
  
                                                                                                                                  
   
class TimeEntryWipController extends Controller {
	   
                
   
                                         
                                                                   
                                                                                              
                                                       
                                              
    
	public function __construct(
		IRequest $request,
		private ShillinqWipService $wipService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                 
   
                                                                             
                                                                            
                                             
   
                                                           
   
                                                              
   
                                                                                                                                   
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function retry(string $uuid): JSONResponse {
		if ($this->wipService->shouldDispatch() === false) {
			return new JSONResponse(['error' => 'Shillinq is not installed.'], 400);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return new JSONResponse(['error' => 'Time entry configuration is incomplete.'], 400);
		}

		$entry = $this->fetchEntry(register: $register, schema: $schema, uuid: $uuid);
		if ($entry === null) {
			return new JSONResponse(['error' => 'Time entry not found.'], 404);
		}

		                                                                             
		                                                                         
		                                 
		if (($entry['wipSyncStatus'] ?? null) === 'synced') {
			return new JSONResponse(
				[
					'status' => 'synced',
					'wipSyncStatus' => 'synced',
					'wipSyncedAt' => (string)($entry['wipSyncedAt'] ?? ''),
				]
			);
		}

		                                                                  
		$entry['wipSyncStatus'] = 'pending';
		$this->persist(register: $register, schema: $schema, uuid: $uuid, data: $entry);

		$approvedBy = (string)($entry['approvedBy'] ?? '');
		$approvedAt = (string)($entry['approvedAt'] ?? '');

		$success = $this->wipService->dispatchWipEvent(
			timeEntry: $entry,
			approvedBy: $approvedBy,
			approvedAt: $approvedAt
		);

		if ($success === true) {
			$entry['wipSyncStatus'] = 'synced';
			$entry['wipSyncedAt'] = $this->wipService->now();
			$this->persist(register: $register, schema: $schema, uuid: $uuid, data: $entry);

			return new JSONResponse(
				[
					'status' => 'synced',
					'wipSyncStatus' => 'synced',
					'wipSyncedAt' => $entry['wipSyncedAt'],
				]
			);
		}

		$entry['wipSyncStatus'] = 'failed';
		$this->persist(register: $register, schema: $schema, uuid: $uuid, data: $entry);

		return new JSONResponse(
			[
				'status' => 'failed',
				'wipSyncStatus' => 'failed',
				'message' => 'WIP dispatch failed.',
			],
			502
		);
	}             

	   
                                      
   
                                            
                                        
                                            
   
                                                                                  
    
	private function fetchEntry(string $register, string $schema, string $uuid): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schema);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to load time entry for WIP retry',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}         
	}                  

	   
                                                             
   
                                            
                                        
                                            
                                                                  
   
                
    
	private function persist(string $register, string $schema, string $uuid, array $data): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to persist time entry WIP retry status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}         
	}               
}           
