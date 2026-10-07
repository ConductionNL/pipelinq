<?php

   
                                     
  
                                                                          
                                                                         
                                                                           
                                                                          
                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                            
  
                                                                           
                                                                  
                                                                              
                     
  
                                          
                                       
  
                                                                                                      
   
trait WritesAsMessagingAccount {
	   
                                                              
   
                                                                                                 
   
                                                                                        
    
	private function asMessagingAccount(callable $writes): array {
		try {
			$account = $this->container->get(MessagingServiceAccount::class);
		} catch (Throwable $e) {
			$account = null;
		}

		if (($account instanceof MessagingServiceAccount) === false) {
			$this->logger->error('Pipelinq messaging: no messaging service account resolves; the webhook writes nothing');
			return ['status' => 'serviceUnavailable'];
		}

		try {
			return $account->runAs(operation: $writes);
		} catch (ServiceAccountUnavailableException $e) {
			return ['status' => 'serviceUnavailable'];
		}
	}                          

	   
                                                                                        
   
                                                     
   
                                                                       
    
	private function findContactByPhone(string $phone): string {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return '';
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						                                                      
						'phone' => $phone,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->resolveSchemaSlug(key: 'contact_schema', default: self::DEFAULT_CONTACT_SCHEMA_SLUG),
					],
				],
				_rbac: self::LOOKUP_SCOPE['_rbac'],
				_multitenancy: self::LOOKUP_SCOPE['_multitenancy'],
			);
		} catch (Throwable $e) {
			return '';
		}

		if (is_array($rows) === false || $rows === []) {
			return '';
		}

		return $this->extractId(payload: $this->toArray(value: $rows[0]));
	}                          
}           
