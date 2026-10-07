<?php

   
                           
  
                                                                         
                                                                               
                                  
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                            
  
                                                                            
                                                                             
                                                                              
                                                          
  
                                                              
   
class PosRoleService {
	   
                
   
                                                
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                             
   
                                                              
   
                                                                 
    
	public function listRoles(): array {
		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

		$results = $this->getObjectService()->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
				],
				'limit' => 1000,
			]
		);

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}                 

	   
                              
   
                                    
   
                                                 
   
                                                          
   
                                                                 
    
	public function getRole(string $id): array {
		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

		try {
			$object = $this->getObjectService()->find(id: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$object = null;
		}

		if ($object === null) {
			throw new OCSNotFoundException('POS-rol niet gevonden.');
		}

		return $this->toArray(object: $object);
	}               

	   
                               
   
                                                                            
                                                                            
              
   
                                                    
                                                
   
                                                
   
                                                                                           
   
                                                                 
    
	public function saveRole(array $data, string $id = ''): array {
		$name = trim((string)($data['name'] ?? ''));
		if ($name === '') {
			throw new OCSBadRequestException('Rolnaam is verplicht.');
		}

		$max = (int)($data['maxDiscountPercent'] ?? 0);
		if ($max < 0 || $max > 100) {
			throw new OCSBadRequestException('Maximale korting moet tussen 0 en 100 liggen.');
		}

		$payload = [
			'name' => $name,
			'description' => (string)($data['description'] ?? ''),
			'canVoid' => (bool)($data['canVoid'] ?? false),
			'maxDiscountPercent' => $max,
			'canRefund' => (bool)($data['canRefund'] ?? false),
			'canNoSale' => (bool)($data['canNoSale'] ?? false),
		];

		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

		$uuid = $id;
		if ($uuid === '') {
			$uuid = $this->uuid();
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}                

	   
                     
   
                                                                        
                                                                           
                                                                      
                                                            
   
                                    
   
                
   
                                                          
                                                                                
   
                                                                 
    
	public function deleteRole(string $id): void {
		                                                              
		$this->getRole(id: $id);

		$assignedCount = $this->countActiveStaffForRole(roleId: $id);
		if ($assignedCount > 0) {
			throw new OCSForbiddenException(
				'Deze rol is nog toegewezen aan ' . $assignedCount . ' actieve medewerker(s). '
				. 'Wijs ze eerst een andere rol toe.'
			);
		}

		[$register, $schema] = $this->config(schemaKey: 'posRole_schema');

		try {
			$this->getObjectService()->deleteObject(uuid: $id, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: failed to delete posRole', ['exception' => $e->getMessage()]);
			throw new OCSNotFoundException('POS-rol kon niet worden verwijderd.');
		}
	}                  

	   
                                                                
   
                                           
   
                                                            
   
                                                                 
    
	public function countActiveStaffForRole(string $roleId): int {
		try {
			[$register, $staffSchema] = $this->config(schemaKey: 'posStaff_schema');
		} catch (OCSNotFoundException $e) {
			return 0;
		}

		try {
			                                                               
			                                                                 
			                                                              
			                                                                 
			                                                                     
			                                                                    
			                                                            
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $staffSchema,
						'posRole' => $roleId,
					],
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		$count = 0;
		foreach ($results as $result) {
			$staff = $this->toArray(object: $result);
			if ((bool)($staff['isActive'] ?? true) === true) {
				$count++;
			}
		}

		return $count;
	}                               

	   
                                                                     
   
                                                       
   
                                                                   
   
                                                                             
   
                                                                 
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                       
   
                                      
   
                                                                 
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
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

		return (array)$object;
	}               

	   
                       
   
                            
    
	private function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}            
}           
