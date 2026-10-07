<?php

   
                                           
  
                                                                           
                                                                            
                                                                       
                                                                          
                                                                            
                                                                            
                                                             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use RuntimeException;

   
                                                                             
  
                                                
  
                                                                                         
  
                                            
  
                                                                          
                                                               
  
                                                                            
                                                                            
                                                              
  
                                                               
   
class KassakoppelingSignatureService {
	   
                                                       
   
               
    
	public const SECRET_KEY = 'kassakoppeling.secret';

	   
                                                                    
   
               
    
	public const GENESIS_HASH = '0';

	   
                                    
   
                           
    
	private const SIGNATURE_FIELDS = [
		'operatorId',
		'registerNumber',
		'action',
		'amount',
		'taxAmount',
		'timestamp',
		'previousHash',
	];

	   
                                                                   
                                                                      
                                       
   
                           
    
	private const HASH_FIELDS = [
		'operatorId',
		'registerNumber',
		'action',
		'amount',
		'itemCount',
		'taxAmount',
		'timestamp',
		'transactionUuid',
		'description',
		'previousHash',
	];

	   
                
   
                                                                           
                                                                    
    
	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
	) {
	}                   

	   
                                                    
   
                                                                  
                                                                      
                                                                      
   
                                                          
   
                                              
   
                                                                
    
	public function generateSignature(array $entryData): string {
		$message = $this->canonicalMessage(entryData: $entryData, fields: self::SIGNATURE_FIELDS);

		return hash_hmac('sha256', $message, $this->getSecretKey());
	}                         

	   
                                                 
   
                                                                     
                                                                       
                                                                    
   
                                                          
                                                              
   
                                          
   
                                                                
    
	public function generateHash(array $entryData, string $previousHash): string {
		$entry = $entryData;
		$entry['previousHash'] = $previousHash;
		$message = $this->canonicalMessage(entryData: $entry, fields: self::HASH_FIELDS);

		return hash('sha256', $message);
	}                    

	   
                                
   
                                                                         
                                                                      
                                                                          
                                                                       
   
                                                          
                                                  
   
                                               
   
                                                                
    
	public function verifySignature(array $entryData, string $signature): bool {
		if ($signature === '') {
			return false;
		}

		$computed = $this->generateSignature(entryData: $entryData);

		return hash_equals($computed, $signature);
	}                       

	   
                                
   
                                                                           
                                                                      
                                                                        
                                                                        
                                           
   
                                                                      
   
                                                                           
   
                                                                
    
	public function verifyHashChain(array $entries): array {
		$expectedPrevious = self::GENESIS_HASH;
		$unbroken = 0;

		foreach ($entries as $index => $entry) {
			$previous = (string)($entry['previousHash'] ?? '');
			if ($previous !== $expectedPrevious) {
				return ['chainValid' => false, 'brokenAt' => ($index + 1), 'unbrokenLinks' => $unbroken];
			}

			$expectedCurrent = $this->generateHash(entryData: $entry, previousHash: $previous);
			$current = (string)($entry['currentHash'] ?? '');
			if (hash_equals($expectedCurrent, $current) === false) {
				return ['chainValid' => false, 'brokenAt' => ($index + 1), 'unbrokenLinks' => $unbroken];
			}

			$expectedPrevious = $current;
			$unbroken++;
		}             

		return ['chainValid' => true, 'brokenAt' => null, 'unbrokenLinks' => $unbroken];
	}                       

	   
                             
   
                   
   
                                                                         
                                  
                                                                          
                                                                       
                                                                      
                                                                        
   
                                                                       
                                                                    
                                                                     
   
                                  
   
                                                               
   
                                                                
    
	public function getSecretKey(): string {
		$configured = trim($this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, ''));
		if ($configured !== '') {
			return $configured;
		}

		$instanceId = trim((string)$this->config->getSystemValue('instanceid', ''));
		if ($instanceId !== '') {
			return hash('sha256', 'pipelinq:kassakoppeling:' . $instanceId);
		}

		throw new RuntimeException('Kassakoppeling secret is not configured and no instance id fallback is available.');
	}                    

	   
                                                                       
   
                                                                         
                                                                     
                               
   
                                                          
                                                                
   
                                         
    
	private function canonicalMessage(array $entryData, array $fields): string {
		$parts = [];
		foreach ($fields as $field) {
			$value = $entryData[$field] ?? '';
			if (is_array($value) === true) {
				$value = '';
			}

			if (is_object($value) === true) {
				$value = '';
			}

			if (is_bool($value) === true) {
				$boolValue = $value;
				$value = 'false';
				if ($boolValue === true) {
					$value = 'true';
				}
			}

			$parts[] = (string)$value;
		}             

		return implode('|', $parts);
	}                        
}           
