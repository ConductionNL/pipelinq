<?php

   
                            
  
                                                                       
                                                                     
                                                                
                                         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                      
                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                 
  
                                                                                                      
   
class MailboxResolver {
	   
                                                      
   
            
    
	public const DEFAULT_TTL_SECONDS = 86400;

	   
                
   
                                                    
                                                                                   
                                                               
                                          
                                                                                 
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly EncryptionService $encryption,
		private readonly LogiusConnector $logiusConnector,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                               
   
                                         
                                                  
   
                                                                                                                                 
   
                                                    
                                                              
    
	public function resolve(string $bsn, string $tenantId): array {
		$bsnHash = $this->encryption->hashBsn($bsn, $tenantId);
		$cached = $this->lookupCache(bsnHash: $bsnHash);
		if ($cached !== null) {
			$cached['source'] = 'cache';
			return $cached;
		}

		$available = false;
		try {
			$available = $this->logiusConnector->checkMailboxExists($bsn);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Berichtenbox mailbox check failed; treating as unavailable.',
				['exception' => $e->getMessage()]
			);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$expiresAt = $now->modify('+' . self::DEFAULT_TTL_SECONDS . ' seconds');

		$row = [
			'bsn' => $this->encryption->encrypt($bsn, $tenantId),
			'bsnHash' => $bsnHash,
			'mailboxAvailable' => $available,
			'resolvedAt' => $now->format(DATE_ATOM),
			'expiresAt' => $expiresAt->format(DATE_ATOM),
			'optedOut' => false,
		];

		$this->writeCache(row: $row);

		return [
			'mailboxAvailable' => $available,
			'optedOut' => false,
			'resolvedAt' => $row['resolvedAt'],
			'expiresAt' => $row['expiresAt'],
			'bsnHash' => $bsnHash,
			'source' => 'logius',
		];
	}               

	  
                           
   
                                                                            
                                                                       
                                                           
                                                                          
                                                                    
    

	   
                                           
   
                                               
   
                                                                  
    
	private function lookupCache(string $bsnHash): ?array {
		[$register, $schema] = $this->config();
		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'bsnHash' => $bsnHash,
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MailboxResolver cache lookup failed.',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$expiresAt = $data['expiresAt'] ?? null;
			if (is_string($expiresAt) === false) {
				continue;
			}

			try {
				$expires = new DateTimeImmutable($expiresAt);
			} catch (\Throwable) {
				continue;
			}

			if ($expires > $now) {
				return [
					'mailboxAvailable' => (bool)($data['mailboxAvailable'] ?? false),
					'optedOut' => (bool)($data['optedOut'] ?? false),
					'resolvedAt' => (string)($data['resolvedAt'] ?? $now->format(DATE_ATOM)),
					'expiresAt' => $expiresAt,
					'bsnHash' => $bsnHash,
					'source' => 'cache',
				];
			}
		}             

		return null;
	}                   

	   
                                    
   
                                                    
   
                
    
	private function writeCache(array $row): void {
		[$register, $schema] = $this->config();
		try {
			$this->getObjectService()->saveObject(
				object: $row,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MailboxResolver cache write failed.',
				['exception' => $e->getMessage()]
			);
		}
	}                  

	   
                         
   
                                                             
    
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                  
   
                                       
    
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'mailboxResolution_schema',
			''
		);

		if ($register === '' || $schema === '') {
			throw new RuntimeException('MailboxResolution register or schema not configured.');
		}

		return [$register, $schema];
	}              

	   
                                                         
   
                              
   
                      
    
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'jsonSerialize') === true) {
				$serialised = $row->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($row, 'getObject') === true) {
				$inner = $row->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}
		}

		return null;
	}               
}           
