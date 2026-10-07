<?php

   
                                     
  
                                                                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                   
  
                                                                             
   
class ContactLinkedUidsService {
	   
                
   
                                                
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                           
   
                                          
                                                                                
    
	public function getLinkedContactsUids(): array {
		$uids = [];
		$objectService = $this->getObjectService();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');

		                                                                        
		                                                                        
		                                                                      
		                                          
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; returning no linked contact UIDs'
			);
			return [];
		}

		foreach (['client', 'contact'] as $type) {
			$typeUids = $this->getLinkedUidsForType(
				objectService: $objectService,
				registerId: $registerId,
				type: $type
			);
			$uids = array_merge($uids, $typeUids);
		}             

		return $uids;
	}                             

	   
                                                             
   
                                                    
                                              
                                                            
   
                                                
    
	private function getLinkedUidsForType(object $objectService, string $registerId, string $type): array {
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$type}_schema", '');
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		$uids = [];

		try {
			$objects = $objectService->findAll(
				['filters' => ['register' => $registerId, 'schema' => $schemaId], 'limit' => 500]
			);

			foreach ($objects as $obj) {
				$data = $this->serializeResult(result: $obj);
				if (empty($data['contactsUid']) === false) {
					$uids[] = $data['contactsUid'];
				}
			}
		} catch (\Exception $e) {
			$this->logger->debug(
				'Pipelinq: Failed to fetch linked UIDs for ' . $type,
				['exception' => $e->getMessage()]
			);
		}

		return $uids;
	}                            

	   
                                                    
   
                                                 
   
                                        
    
	private function serializeResult(mixed $result): array {
		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return $result->jsonSerialize();
		}

		if (is_array($result) === true) {
			return $result;
		}

		return [];
	}                       

	   
                                                         
   
                                      
    
	private function getObjectService(): object {
		return $this->objectService;
	}                        
}           
