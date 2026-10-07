<?php

   
                               
  
                                                                          
                                                              
                                                                        
                                                                          
                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                      
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                           
  
                                                                             
                                                                 
                                        
  
                                                                                     
                                                                      
  
                                                      
   
class KccWerkplekService {
	   
                                                                   
   
                           
    
	private const OPEN_REQUEST_STATUSES = ['new', 'in_progress'];

	   
                                                                
   
                           
    
	private const OPEN_TASK_STATUSES = ['open', 'in_progress'];

	   
                
   
                                                                           
                                                                             
                                                              
                                                                
                                          
                                                                         
                                                                             
                                                                               
                                                                        
   
                                                       
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private TicketService $ticketService,
	) {
	}                   

	   
                                                  
   
                                                                                              
   
                                                                      
   
                                                       
    
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		                                                                     
		                                                                        
		                                                                       
		                                                                    
		                                                                       
		                                                                      
		if (class_exists('\OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException('The OpenRegister app is not installed');
		}

		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (\Throwable $e) {
			throw new RuntimeException(
				'OpenRegister ObjectService is not available',
				0,
				$e
			);
		}
	}                        

	   
                                                                         
   
                                                                 
   
                                                                      
   
                                                       
    
	private function getSchema(string $schemaKey): string {
		return $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
	}                 

	   
                                                        
   
                                                                       
                                                                      
                                                                           
                                                                            
                                                                           
                                                                      
   
                                                                              
   
                                                       
    
	private function getRegister(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}                   

	   
                                                                        
   
                                                                       
   
                                                         
   
                                                       
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}               

	   
                                                                          
                                                                        
   
                                                                 
   
                                                                 
   
                                                       
    
	private function findAllSafe(string $schemaKey): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[KccWerkplekService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema]]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[KccWerkplekService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}                   

	   
                                                                              
                                                                            
                                                      
   
                                                                              
                                                                             
                                                                             
   
                                                                 
                                                                  
   
                                                                 
   
                                                                            
    
	private function findFilteredSafe(string $schemaKey, array $filters): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[KccWerkplekService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: ['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[KccWerkplekService] filtered findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}                        

	   
                                                                         
   
                                                                             
                                                                      
                                                                            
                                                                          
                                                                              
                                         
   
                                                                         
                                                                  
   
                                                                 
   
                                                                            
    
	private function findTicketsSafe(string $ticketType, array $filters): array {
		if ($this->ticketService->isConfigured() === false) {
			$this->logger->info(
				message: '[KccWerkplekService] register or ticket schema not configured',
				context: ['ticketType' => $ticketType]
			);
			return [];
		}

		$out = [];
		foreach ($this->ticketService->findByType($ticketType, $filters) as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}                       

	   
                                                               
   
                                                                      
                                                                           
   
                                                          
   
                                                                                                         
                                                                               
   
                                                                                                                              
   
                                                       
    
	public function getWorkspaceState(string $userId): array {
		                                                                         
		                                                               
		                                                                      
		                                                                        
		                                                                     
		                                                                       
		                                     
		$assignedRequests = $this->findTicketsSafe(
			ticketType: TicketService::TYPE_REQUEST,
			filters: ['assignee' => $userId, 'status' => self::OPEN_REQUEST_STATUSES]
		);

		$openTasks = $this->findFilteredSafe(
			schemaKey: 'task_schema',
			filters: ['assigneeUserId' => $userId, 'status' => self::OPEN_TASK_STATUSES]
		);

		$allAgents = $this->findAllSafe(schemaKey: 'agentProfile_schema');

		                                                                         
		$agentProfile = [
			'userId' => $userId,
			'isAvailable' => false,
			'maxConcurrent' => 0,
			'skills' => [],
		];
		foreach ($allAgents as $candidate) {
			if ((string)($candidate['userId'] ?? '') === $userId) {
				$agentProfile = [
					'id' => (string)($candidate['id'] ?? ($candidate['@self']['id'] ?? '')),
					'userId' => $userId,
					'isAvailable' => (bool)($candidate['isAvailable'] ?? false),
					'maxConcurrent' => (int)($candidate['maxConcurrent'] ?? 0),
					'skills' => (array)($candidate['skills'] ?? []),
				];
				break;
			}
		}

		return [
			'agentProfile' => $agentProfile,
			'assignedRequests' => $assignedRequests,
			'openTasks' => $openTasks,
		];
	}                         

	   
                                                             
   
                                                                      
                                                                    
                                                                    
   
                                                          
                                                     
   
                                                                               
   
                                                                      
   
                                                                                                                               
   
                                                       
    
	public function setAvailability(string $userId, bool $available): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: 'agentProfile_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('agentProfile schema is not configured');
		}

		$objectService = $this->getObjectService();

		                                                 
		$existingId = '';
		$existingData = [];
		try {
			$results = $objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema]]
			);
			foreach ($results as $result) {
				$arr = $this->toArray(object: $result);
				if ((string)($arr['userId'] ?? '') === $userId) {
					$existingData = $arr;
					$existingId = (string)($arr['@self']['id'] ?? $arr['id'] ?? '');
					break;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[KccWerkplekService] setAvailability lookup failed',
				context: ['error' => $e->getMessage()]
			);
		}

		                                                                                   
		$payload = array_filter(
			$existingData,
			static fn (string $k): bool => $k !== '@self' && $k !== 'id',
			ARRAY_FILTER_USE_KEY
		);
		$payload['userId'] = $userId;
		$payload['isAvailable'] = $available;
		if (isset($payload['maxConcurrent']) === false) {
			$payload['maxConcurrent'] = 1;
		}

		try {
			$saveId = null;
			if ($existingId !== '') {
				$saveId = $existingId;
			}

			$objectService->saveObject(
				$payload,
				[],
				$register,
				$schema,
				$saveId
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[KccWerkplekService] saveObject failed',
				context: ['error' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to update agent availability', 0, $e);
		}         

		return [
			'userId' => $userId,
			'isAvailable' => $available,
		];
	}                       
}           
