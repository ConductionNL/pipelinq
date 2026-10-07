<?php

   
                                      
  
                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                            
  
                                             
   
class ContactVcardWriterService {
	   
                
   
                                                                  
                                                
                                              
                                                                           
                                                                                         
    
	public function __construct(
		private IContactsManager $contactsManager,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private RegisterResolverService $registerResolver,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                             
   
                                                  
                                                   
                                                                  
   
                                             
   
                                              
    
	public function writeToAddressBook(array $properties, array $objData, string $objectType): ?string {
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		if (empty($addressBooks) === true) {
			$this->logger->debug('Pipelinq: No addressbooks available for sync');
			return null;
		}

		$addressBook = reset($addressBooks);

		$existingUid = $objData['contactsUid'] ?? null;
		if ($existingUid !== null && $existingUid !== '') {
			$properties['UID'] = $existingUid;
		}

		try {
			$result = $addressBook->createOrUpdate($properties);
		} catch (\Exception $e) {
			$this->logger->error(
				'Pipelinq: Failed to sync contact to addressbook',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		$contactsUid = $this->extractContactsUid(
			result: $result,
			existingUid: $existingUid
		);

		if ($contactsUid !== null && ($existingUid === null || $existingUid === '')) {
			$this->storeContactsUidOnObject(
				objData: $objData,
				contactsUid: $contactsUid,
				objectType: $objectType
			);
		}

		return $contactsUid;
	}                          

	   
                                                                        
                                                                              
                                                                     
                                                                          
                                        
   
                                                                     
                                                                                      
   
                                                                                
                                                     
   
                                                     
    
	public function writeVcard(array $properties, ?string $existingUid = null): ?string {
		$addressBooks = $this->contactsManager->getUserAddressBooks();
		if (empty($addressBooks) === true) {
			$this->logger->debug('Pipelinq: No addressbooks available for contact provisioning');
			return null;
		}

		$addressBook = reset($addressBooks);

		if ($existingUid !== null && $existingUid !== '') {
			$properties['UID'] = $existingUid;
		}

		try {
			$result = $addressBook->createOrUpdate($properties);
		} catch (\Exception $e) {
			$this->logger->error(
				'Pipelinq: Failed to provision contact in addressbook',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->extractContactsUid(
			result: $result,
			existingUid: $existingUid
		);
	}                  

	   
                                                                      
   
                                                        
                                                        
   
                                                       
    
	private function extractContactsUid(mixed $result, ?string $existingUid): ?string {
		if (is_array($result) === true && isset($result['UID']) === true) {
			return $result['UID'];
		}

		if (is_string($result) === true) {
			return $result;
		}

		if ($existingUid !== null && $existingUid !== '') {
			return $existingUid;
		}

		return null;
	}                          

	   
                                                      
   
                                          
                                                         
                                                                  
   
                
    
	private function storeContactsUidOnObject(array $objData, string $contactsUid, string $objectType): void {
		try {
			$objectService = $this->getObjectService();
			$registerId = $this->registerResolver->resolve('contact');
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, "{$objectType}_schema", '');

			                                                                    
			                                                           
			                                                                    
			                                                                   
			                                                                   
			                                                                 
			                                                                   
			                                                        
			if ($registerId === '' || $schemaId === '') {
				$this->logger->warning(
					'Pipelinq: refusing to store contactsUid — register or schema is not configured',
					['objectType' => $objectType]
				);
				return;
			}

			$updateData = $objData;
			$updateData['contactsUid'] = $contactsUid;
			$objectService->saveObject(
				$updateData,
				[],
				$registerId,
				$schemaId,
				null
			);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Pipelinq: Failed to store contactsUid back on object',
				['exception' => $e->getMessage()]
			);
		}         
	}                                

	   
                                                         
   
                                      
    
	private function getObjectService(): object {
		return $this->objectService;
	}                        
}           
