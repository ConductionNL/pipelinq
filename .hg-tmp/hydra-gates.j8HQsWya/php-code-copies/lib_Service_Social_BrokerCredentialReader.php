<?php

   
                                   
  
                                                                             
                                                                               
                                                                              
                                                                    
  
                                                                           
                                                                           
                                                                               
                                                                      
  
                                                                               
                                                                         
                                                                          
                                                                              
                                                 
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                
  
                                                                                                                                          
   
class BrokerCredentialReader {
	   
                                                                                 
   
               
    
	public const REGISTER = 'credential-broker';

	   
                                                                             
   
               
    
	public const SCHEMA = 'brokeredcredential';

	   
                                                                            
                                                                            
                                      
   
                           
    
	public const READABLE = [
		'id',
		'uuid',
		'name',
		'provider',
		'kind',
		'status',
		'owner',
		'scope',
		'scopes',
		'expiresAt',
		'lastError',
		'lastRefreshedAt',
		'accountHandle',
		'accountDisplayName',
		'accountId',
		'instanceBaseUrl',
		'clientId',
		'allowedApps',
	];

	   
                
   
                                                                                          
                                          
   
                
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                           
                                                     
   
                                                     
   
                                                                     
   
                                                                                                                                           
    
	public function read(string $credentialRef): ?array {
		if (trim($credentialRef) === '') {
			return null;
		}

		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$entity = $service->find(
				id: $credentialRef,
				register: self::REGISTER,
				schema: self::SCHEMA,
			);
		} catch (Throwable $failure) {
			$this->logger->info(
				'BrokerCredentialReader.read: the credential could not be read',
				['exception' => $failure->getMessage()]
			);

			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->readable(entity: $entity);
	}            

	   
                                                                        
                                        
   
                                                     
   
                                                  
   
                                                                                                                                           
    
	public function status(string $credentialRef): string {
		$row = $this->read(credentialRef: $credentialRef);
		if ($row === null) {
			return '';
		}

		return (string)($row['status'] ?? '');
	}              

	   
                                                                          
                                          
   
                                                                       
                                                                             
                                                                     
                                                                             
                                                                            
                                     
   
                                                              
                                                   
   
                                                                     
   
                                                                                                                                           
    
	public function findLatest(string $provider, string $ownerUid): ?array {
		if (trim($provider) === '' || trim($ownerUid) === '') {
			return null;
		}

		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$rows = $service->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA,
						'provider' => $provider,
					],
				],
			);
		} catch (Throwable $failure) {
			$this->logger->info(
				'BrokerCredentialReader.findLatest: the credential list could not be read',
				['provider' => $provider, 'exception' => $failure->getMessage()]
			);

			return null;
		}

		$best = null;
		foreach (($rows ?? []) as $row) {
			$readable = $this->readable(entity: $row);
			if ($this->belongsTo(row: $readable, provider: $provider, ownerUid: $ownerUid) === false) {
				continue;
			}

			if ($best === null || $this->connectedAt(row: $readable) >= $this->connectedAt(row: $best)) {
				$best = $readable;
			}
		}

		return $best;
	}                  

	   
                                                                        
   
                                                                           
                                                                        
                                                          
   
                                                             
                                                            
                                                         
   
                                      
    
	private function belongsTo(array $row, string $provider, string $ownerUid): bool {
		if ($row === [] || (string)($row['provider'] ?? '') !== $provider) {
			return false;
		}

		return ((string)($row['owner'] ?? '') === $ownerUid);
	}                 

	   
                                                                           
                                                                       
   
                                                             
   
                                                                     
    
	private function connectedAt(array $row): string {
		foreach (['lastRefreshedAt', 'expiresAt'] as $field) {
			$value = trim((string)($row[$field] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}                   

	   
                                                                   
   
                                                                        
   
                                                     
    
	private function readable(mixed $entity): array {
		$payload = $entity;
		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$payload = $entity->jsonSerialize();
		}

		if (is_array($payload) === false) {
			return [];
		}

		$out = [];
		foreach (self::READABLE as $key) {
			if (array_key_exists($key, $payload) === true) {
				$out[$key] = $payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			$out['id'] = (string)($payload['@self']['id'] ?? ($out['id'] ?? ''));
		}

		return $out;
	}                
}           
