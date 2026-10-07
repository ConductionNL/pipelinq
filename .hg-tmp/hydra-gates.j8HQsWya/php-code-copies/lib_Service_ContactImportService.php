<?php

   
                                 
  
                                                                                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                  
  
                                                                             
   
class ContactImportService {
	   
                
   
                                                
                                                            
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private ContactDataBuilder $dataBuilder,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                    
   
                                                       
                                       
   
                                                 
   
                                                                    
   
                                              
    
	public function importAsClient(array $ncContact, string $uid): array {
		$data = $this->dataBuilder->buildClientImportData(
			ncContact: $ncContact,
			uid: $uid
		);
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($schemaId === '') {
			throw new RuntimeException('Pipelinq: app-config "client_schema" is not configured.');
		}

		return $this->saveAndSerialize(data: $data, schemaId: $schemaId);
	}                      

	   
                                                            
   
                                                       
                                       
                                                    
   
                                                  
   
                                                                     
   
                                              
    
	public function importAsContact(array $ncContact, string $uid, ?string $clientId): array {
		$data = $this->dataBuilder->buildContactImportData(
			ncContact: $ncContact,
			uid: $uid,
			clientId: $clientId
		);
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($schemaId === '') {
			throw new RuntimeException('Pipelinq: app-config "contact_schema" is not configured.');
		}

		return $this->saveAndSerialize(data: $data, schemaId: $schemaId);
	}                       

	   
                                                      
   
                                                                         
                                                                             
                                                                            
                                                                      
                                                                         
                                                                      
                                             
   
                                               
                                          
   
                                        
   
                                                                           
    
	private function saveAndSerialize(array $data, string $schemaId): array {
		$objectService = $this->getObjectService();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Pipelinq: register or schema is not configured for the contact import.');
		}

		                                      
		  
		                                                                  
		                                                                      
		                                                                        
		                                                                    
		                                                 
		  
		                                                                
		                                                                      
		                                                                 
		$existingUuid = $this->findExistingByContactsUid(
			objectService: $objectService,
			registerId: $registerId,
			schemaId: $schemaId,
			contactsUid: (string)($data['contactsUid'] ?? '')
		);

		$created = $objectService->saveObject(
			$data,
			[],
			$registerId,
			$schemaId,
			$existingUuid
		);

		return $this->serializeResult(result: $created);
	}                        

	   
                                                                         
   
                                                                       
                                                                          
                                                                             
                                                                       
                                           
   
                                                                 
                                              
                                          
                                                           
   
                                                             
    
	private function findExistingByContactsUid(
		object $objectService,
		string $registerId,
		string $schemaId,
		string $contactsUid,
	): ?string {
		if ($contactsUid === '') {
			return null;
		}

		try {
			                                                                    
			                                                                  
			                                      
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
						'contactsUid' => $contactsUid,
					],
					'limit' => 1,
				]
			);
		} catch (Throwable $e) {
			                                                                    
			                                                                     
			                                                               
			                                         
			unset($e);
			return null;
		}

		foreach ((array)$rows as $row) {
			$array = $row;
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$array = $row->jsonSerialize();
			}

			if (is_array($array) === false) {
				continue;
			}

			$uuid = (string)($array['@self']['id'] ?? $array['id'] ?? '');
			if ($uuid !== '') {
				return $uuid;
			}
		}

		return null;
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
