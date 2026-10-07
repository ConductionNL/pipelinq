<?php

   
                                 
  
                                                                          
                                                                         
                                              
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                           
  
                                                                                                                                                              
   
class ContactAddressLookup {

	   
                                                          
   
                          
    
	private const PHONE_KEYS = ['phoneNumber', 'mobile', 'telephone', 'phone'];

	   
                     
   
                          
    
	private const EMAIL_KEYS = ['email', 'emailAddress'];

	   
                                                
   
                             
    
	private array $cache = [];

	   
                
   
                                                                               
                                                                             
                                                            
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                              
   
                                                                           
                                                       
   
                                                                             
                                                          
   
                                                                   
   
                                                                                                                                                               
    
	public function addressFor(string $contactId, string $channel): string {
		$contactId = trim($contactId);
		if ($contactId === '') {
			return '';
		}

		if (str_contains($contactId, '@') === true) {
			if ($channel === 'email') {
				return strtolower($contactId);
			}

			return '';
		}

		$cacheKey = $contactId.'|'.$channel;
		if (isset($this->cache[$cacheKey]) === true) {
			return $this->cache[$cacheKey];
		}

		$contact = $this->loadContact(contactId: $contactId);
		$address = '';
		if ($contact !== null) {
			$address = self::fromContact(contact: $contact, channel: $channel);
		}

		$this->cache[$cacheKey] = $address;
		return $address;
	}                  

	   
                                                
   
                                                        
                                                                     
   
                                         
   
                                                                                                                                                               
    
	public static function fromContact(array $contact, string $channel): string {
		$keys = self::PHONE_KEYS;
		if ($channel === 'email') {
			$keys = self::EMAIL_KEYS;
		}

		foreach ($keys as $key) {
			$value = ($contact[$key] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}                   

	   
                                         
   
                                      
   
                                             
    
	private function loadContact(string $contactId): ?array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			$register = 'pipelinq';
		}

		foreach (['contact_schema' => 'contact', 'client_schema' => 'client'] as $key => $default) {
			$schema = $this->appConfig->getValueString(Application::APP_ID, $key, '');
			if ($schema === '') {
				$schema = $default;
			}

			try {
				$entity = $objectService->find(id: $contactId, register: $register, schema: $schema);
			} catch (Throwable $e) {
				$this->logger->debug('ContactAddressLookup: not found in '.$schema, ['contactId' => $contactId]);
				$entity = null;
			}

			$row = $this->toArray(value: $entity);
			if ($row !== []) {
				return $row;
			}
		}

		return null;
	}                   

	   
                                                 
   
                                   
   
                                        
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}               
}           
