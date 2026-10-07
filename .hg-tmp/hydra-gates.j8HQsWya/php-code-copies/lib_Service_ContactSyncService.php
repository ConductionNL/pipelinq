<?php

   
                               
  
                                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                             
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                        
  
                                                                             
   
class ContactSyncService {
	   
                
   
                                                                  
                                                                                 
                                                                           
                                                                               
                                                       
                                                                                         
    
	public function __construct(
		private IContactsManager $contactsManager,
		private ContactImportService $contactImportService,
		private ContactVcardService $contactVcardService,
		private ContactLinkedUidsService $linkedUidsService,
		private IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                           
                                                                        
                                                                         
                                 
   
                                                                             
                                                                        
                                                                 
                                                                           
   
                                                                      
                                                                              
   
                                                       
   
                                                                                       
   
                                                     
   
                                                                                                                                         
                                                                                                                                         
    
	public function createWithContact(string $objectType, array $form): array {
		if (in_array($objectType, ['client', 'contact'], true) === false) {
			throw new RuntimeException('Invalid objectType -- must be client or contact');
		}

		if (trim((string)($form['name'] ?? '')) === '') {
			throw new RuntimeException('Name is required');
		}

		$provision = $this->contactVcardService->provisionContactFromForm(
			form: $form,
			objectType: $objectType
		);

		if ($provision === null) {
			throw new RuntimeException('Could not provision the Nextcloud contact -- is the Contacts app enabled?');
		}

		                                                                       
		                                                                  
		$payload = $form;
		unset($payload['id'], $payload['@self']);
		$payload['contactsUid'] = $provision['contactsUid'];
		$payload['name'] = $provision['name'];
		if ($provision['email'] !== '') {
			$payload['email'] = $provision['email'];
		}

		if ($provision['phone'] !== '') {
			$payload['phone'] = $provision['phone'];
		}

		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');

		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Pipelinq register or schema is not configured');
		}

		$created = $this->getObjectService()->saveObject(
			$payload,
			[],
			$registerId,
			$schemaId,
			null
		);

		if (is_object($created) === true && method_exists($created, 'jsonSerialize') === true) {
			return $created->jsonSerialize();
		}

		if (is_array($created) === true) {
			return $created;
		}

		return [];
	}                         

	   
                                                         
   
                                      
    
	private function getObjectService(): object {
		return $this->objectService;
	}                        

	   
                                                                
                                                                                               
   
                                          
   
                                        
   
                                              
    
	public function searchContacts(string $query): array {
		if ($this->contactsManager->isEnabled() === false) {
			return [];
		}

		$results = $this->contactsManager->search($query, ['FN', 'EMAIL', 'TEL', 'ORG'], ['limit' => 50]);

		$linkedUids = $this->linkedUidsService->getLinkedContactsUids();

		return $this->buildContactResults(
			results: $results,
			linkedUids: $linkedUids
		);
	}                      

	   
                                                                    
   
                                       
                                                      
                                                            
                                                                        
   
                                          
   
                                                                                                            
   
                                              
    
	public function importContact(string $uid, string $addressBookKey, string $type = 'client', ?string $clientId = null): array {
		if ($this->contactsManager->isEnabled() === false) {
			throw new RuntimeException('Nextcloud Contacts is not available');
		}

		$ncContact = $this->findContactByUid(uid: $uid);

		if ($ncContact === null) {
			throw new RuntimeException('Contact not found in Nextcloud addressbook');
		}

		if ($type === 'client') {
			return $this->contactImportService->importAsClient(
				ncContact: $ncContact,
				uid: $uid
			);
		}

		return $this->contactImportService->importAsContact(
			ncContact: $ncContact,
			uid: $uid,
			clientId: $clientId
		);
	}                     

	   
                                                            
                                         
   
                                                                  
                                          
   
                                             
   
                                              
    
	public function syncToContacts(string $objectType, string $objectId): ?string {
		return $this->contactVcardService->syncToContacts(
			objectType: $objectType,
			objectId: $objectId
		);
	}                      

	   
                                                         
   
                                                         
                                                     
   
                                                
    
	private function buildContactResults(array $results, array $linkedUids): array {
		$contacts = [];
		foreach ($results as $result) {
			$uid = $result['UID'] ?? null;
			if ($uid === null) {
				continue;
			}

			$contacts[] = $this->formatContactResult(
				result: $result,
				uid: $uid,
				linkedUids: $linkedUids
			);
		}

		return $contacts;
	}                           

	   
                                         
   
                                                
                                       
                                                 
   
                                              
    
	private function formatContactResult(array $result, string $uid, array $linkedUids): array {
		return [
			'uid' => $uid,
			'name' => $this->extractFirstValue(value: ($result['FN'] ?? '')),
			'email' => $this->extractFirstValue(value: ($result['EMAIL'] ?? '')),
			'phone' => $this->extractFirstValue(value: ($result['TEL'] ?? '')),
			'org' => $this->extractFirstValue(value: ($result['ORG'] ?? '')),
			'addressBookKey' => $result['addressbook-key'] ?? '',
			'alreadyLinked' => in_array($uid, $linkedUids, true),
		];
	}                           

	   
                                        
   
                                               
   
                                                         
    
	private function findContactByUid(string $uid): ?array {
		                                                                      
		                                                               
		                                                                      
		                                      
		$results = $this->contactsManager->search($uid, ['UID'], ['limit' => 1, 'types' => true]);

		foreach ($results as $r) {
			if (($r['UID'] ?? '') === $uid) {
				return $r;
			}
		}

		return null;
	}                        

	   
                                                                             
   
                                                  
   
                                              
    
	private function extractFirstValue(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value[0] ?? '');
		}

		return (string)$value;
	}                         
}           
