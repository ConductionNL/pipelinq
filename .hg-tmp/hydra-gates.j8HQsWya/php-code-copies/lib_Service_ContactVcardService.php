<?php

   
                                
  
                                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                        
  
                                             
   
class ContactVcardService {
	   
                                        
   
                                                                            
                                                                            
   
                 
    
	private const ALLOWED_OBJECT_TYPES = ['client', 'contact'];

	   
                
   
                                                                  
                                                
                                                                     
                                                                         
                                              
                                                                           
                                                                                         
    
	public function __construct(
		private IContactsManager $contactsManager,
		private IAppConfig $appConfig,
		private ContactVcardWriterService $writerService,
		private ContactVcardPropertyBuilder $propBuilder,
		private LoggerInterface $logger,
		private RegisterResolverService $registerResolver,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                            
   
                                                                  
                                          
   
                                             
   
                                              
    
	public function syncToContacts(string $objectType, string $objectId): ?string {
		if ($this->contactsManager->isEnabled() === false) {
			$this->logger->debug('Pipelinq: Contacts sync skipped -- IManager not available');
			return null;
		}

		$objData = $this->fetchPipelinqObject(
			objectType: $objectType,
			objectId: $objectId
		);

		if ($objData === null) {
			return null;
		}

		$properties = $this->propBuilder->buildProperties(
			objData: $objData,
			objectType: $objectType
		);

		return $this->writerService->writeToAddressBook(
			properties: $properties,
			objData: $objData,
			objectType: $objectType
		);
	}                      

	   
                                                                             
                                                                           
                                                                               
   
                                                                         
                                                                             
                                                                              
                                                                             
                                                                           
                                                                              
                                                                        
                                               
   
                                                                           
                                                            
   
                                                                              
                                                                      
   
                                                                                
                                                                                                                                
   
                                                     
    
	public function provisionContactFromForm(array $form, string $objectType): ?array {
		if (in_array($objectType, self::ALLOWED_OBJECT_TYPES, true) === false) {
			$this->logger->warning('ContactVcardService: invalid objectType for provisioning', ['objectType' => $objectType]);
			return null;
		}

		if ($this->contactsManager->isEnabled() === false) {
			$this->logger->warning('Pipelinq: cannot provision contact -- Contacts (IManager) not available');
			return null;
		}

		                                                                          
		                                                                
		$contactsUid = $this->matchExistingContact(form: $form);

		                                                                        
		                                                                  
		if ($contactsUid === null) {
			$properties = $this->propBuilder->buildProperties(
				objData: $form,
				objectType: $objectType
			);

			$contactsUid = $this->writerService->writeVcard(properties: $properties);
		}

		if ($contactsUid === null || $contactsUid === '') {
			return null;
		}

		return [
			'contactsUid' => $contactsUid,
			'name' => (string)($form['name'] ?? ''),
			'email' => (string)($form['email'] ?? ''),
			'phone' => (string)($form['phone'] ?? ''),
		];
	}                                

	   
                                                                              
                                                                   
   
                                                                             
                                                             
   
                                                  
   
                                                                       
    
	private function matchExistingContact(array $form): ?string {
		$searchTerms = [];

		$email = (string)($form['email'] ?? '');
		if ($email !== '') {
			$searchTerms[] = ['term' => $email, 'props' => ['EMAIL', 'UID']];
		}

		if ((string)($form['type'] ?? '') === 'organization') {
			$name = (string)($form['name'] ?? '');
			if ($name !== '') {
				$searchTerms[] = ['term' => $name, 'props' => ['ORG', 'UID']];
			}
		}

		foreach ($searchTerms as $search) {
			try {
				$results = $this->contactsManager->search($search['term'], $search['props'], ['limit' => 1]);
			} catch (\Exception $e) {
				$this->logger->warning(
					'Pipelinq: contact match search failed',
					['term' => $search['term'], 'exception' => $e->getMessage()]
				);
				continue;
			}

			foreach ($results as $hit) {
				$uid = (string)($hit['UID'] ?? '');
				if ($uid !== '') {
					return $uid;
				}
			}
		}

		return null;
	}                            

	   
                                           
   
                                                                  
                                          
   
                                                                 
    
	private function fetchPipelinqObject(string $objectType, string $objectId): ?array {
		if (in_array($objectType, self::ALLOWED_OBJECT_TYPES, true) === false) {
			$this->logger->warning('ContactVcardService: invalid objectType', ['objectType' => $objectType]);
			return null;
		}

		$objectService = $this->getObjectService();
		$registerId = $this->registerResolver->resolve('contact');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');

		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning('Pipelinq: Cannot sync -- register or schema not configured');
			return null;
		}

		try {
			$object = $objectService->find(
				$objectId,
				[],
				false,
				$registerId,
				$schemaId
			);
		} catch (\Exception $e) {
			$this->logger->error('Pipelinq: Failed to fetch object for sync', ['exception' => $e->getMessage()]);
			return null;
		}

		return $this->serializeResult(result: $object);
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
