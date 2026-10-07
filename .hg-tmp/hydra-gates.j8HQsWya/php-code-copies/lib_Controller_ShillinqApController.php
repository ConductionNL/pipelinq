<?php

   
                                 
  
                                                                          
                                                                            
                                                             
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqApService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                             
  
                                                                          
                                                                 
                                                     
  
                                                                             
   
class ShillinqApController extends Controller {
	   
                
   
                                         
                                                                
                                                                                              
                                                       
                                              
    
	public function __construct(
		IRequest $request,
		private ShillinqApService $apService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                               
   
                                                                 
                                                                       
                                                                        
                                                  
   
                                                      
   
                                                             
   
                                                                              
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function retry(string $id): JSONResponse {
		if ($this->apService->shouldDispatch() === false) {
			return new JSONResponse(['error' => 'Shillinq is not installed.'], 400);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'expense_schema', '');
		if ($register === '' || $schema === '' || $id === '') {
			return new JSONResponse(['error' => 'Expense schema configuration is incomplete.'], 400);
		}

		$expense = $this->fetchExpense(register: $register, schema: $schema, uuid: $id);
		if ($expense === null) {
			return new JSONResponse(['error' => 'Expense not found.'], 404);
		}

		                                                               
		if (($expense['apSyncStatus'] ?? null) !== 'failed') {
			return new JSONResponse(
				['error' => 'Only failed AP syncs can be retried.', 'apSyncStatus' => $expense['apSyncStatus'] ?? null],
				400
			);
		}

		                                                                
		$expense['apSyncStatus'] = 'pending';
		$this->persist(register: $register, schema: $schema, uuid: $id, data: $expense);

		$approvedBy = (string)($expense['approvedBy'] ?? '');
		$approvedAt = (string)($expense['approvedAt'] ?? $this->apService->now());

		$success = $this->apService->dispatchApEvent(
			expense: $expense + ['uuid' => $id],
			approvedBy: $approvedBy,
			approvedAt: $approvedAt
		);

		if ($success === true) {
			$expense['apSyncStatus'] = 'synced';
			$expense['apSyncedAt'] = $this->apService->now();
			$this->persist(register: $register, schema: $schema, uuid: $id, data: $expense);

			return new JSONResponse(
				[
					'apSyncStatus' => 'synced',
					'apSyncedAt' => $expense['apSyncedAt'],
				]
			);
		}

		$expense['apSyncStatus'] = 'failed';
		$this->persist(register: $register, schema: $schema, uuid: $id, data: $expense);

		return new JSONResponse(['apSyncStatus' => 'failed', 'error' => 'AP dispatch failed.'], 502);
	}             

	   
                                    
   
                                            
                                        
                                         
   
                                                                               
    
	private function fetchExpense(string $register, string $schema, string $uuid): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schema);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to load expense for AP retry',
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
				'Pipelinq: failed to persist expense AP retry status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}         
	}               
}           
