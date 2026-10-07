<?php

   
                                    
  
                                                                               
                                                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                      
  
                                                                               
                                                                         
  
                                                                                        
                                                     
   
class ForecastOverrideService {
	   
                                          
   
                           
    
	public const OVERRIDE_CATEGORIES = ['commit', 'best_case'];

	   
                                                      
   
            
    
	public const REASON_MIN_LENGTH = 5;

	   
                
   
                                                       
                                                                             
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private ForecastService $forecastService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                         
   
                                                             
   
                                                             
   
                                                                                  
    
	public function validatePayload(array $payload): ?string {
		if (trim((string)($payload['period_id'] ?? '')) === '') {
			return 'period_id is required.';
		}

		if (trim((string)($payload['override_owner_id'] ?? '')) === '') {
			return 'override_owner_id is required.';
		}

		if (in_array((string)($payload['level'] ?? ''), ['rep', 'team', 'division'], true) === false) {
			return 'A valid level (rep, team, division) is required.';
		}

		if (in_array((string)($payload['category'] ?? ''), self::OVERRIDE_CATEGORIES, true) === false) {
			return 'A valid category (commit, best_case) is required.';
		}

		$amount = $payload['override_amount'] ?? null;
		if (is_numeric($amount) === false || (float)$amount < 0) {
			return 'override_amount must be a non-negative number.';
		}

		if (mb_strlen(trim((string)($payload['reason'] ?? ''))) < self::REASON_MIN_LENGTH) {
			return 'A reason of at least 5 characters is required.';
		}

		return null;
	}                       

	   
                                   
   
                                                                             
                                                      
   
                                                                       
                                                    
   
                                                           
   
                                                                                  
    
	public function createOverride(array $payload, string $managerId): array {
		$periodId = (string)$payload['period_id'];
		$ownerId = (string)$payload['override_owner_id'];
		$level = (string)$payload['level'];
		$category = (string)$payload['category'];

		$rollUp = $this->forecastService->computeRollUp(ownerId: $ownerId, periodId: $periodId, level: $level);
		$originalKey = 'original_best_case';
		if ($category === 'commit') {
			$originalKey = 'original_commit';
		}

		$original = (float)($rollUp[$originalKey] ?? 0);

		$override = [
			'period_id' => $periodId,
			'owner_id' => $managerId,
			'override_owner_id' => $ownerId,
			'level' => $level,
			'category' => $category,
			'override_amount' => (float)$payload['override_amount'],
			'original_amount' => $original,
			'reason' => trim((string)$payload['reason']),
			'created_by' => $managerId,
			'created_at' => (new DateTimeImmutable())->format('c'),
		];

		return $this->persist(override: $override);
	}                      

	   
                            
   
                                        
   
                                                                 
                                                                                         
                                                      
    
	public function getOverride(string $id): ?array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $id === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}                   

	   
                       
   
                                        
   
                                   
                                                                                         
                                                      
    
	public function deleteOverride(string $id): bool {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $id === '') {
			return false;
		}

		try {
			return (bool)$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq: failed to delete forecast override', ['exception' => $e->getMessage(), 'id' => $id]);
			return false;
		}
	}                      

	   
                               
   
                                                            
   
                                                                
    
	private function persist(array $override): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Forecast override schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $override,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: null
		);

		return $this->toArray(object: $saved);
	}               

	   
                                               
   
                                                                        
                                                                            
                                                                             
                                                                             
                                                                           
                                                                      
   
                                                                           
                                                          
    
	private function config(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'forecastOverride_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/forecastOverride_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}              

	   
                                                                 
   
                                             
   
                                                 
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}               

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
