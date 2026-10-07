<?php

   
                              
  
                                                                     
                                                                    
                                                                    
                                                                   
                                                               
                                                                    
                                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                         
                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                                                   
  
                                                                                                         
   
class EncryptionService {
	   
                                    
   
            
    
	private const KEY_BYTES = 32;

	   
                                   
   
            
    
	private const IV_BYTES = 12;

	   
                                           
   
            
    
	private const TAG_BYTES = 16;

	   
                      
   
               
    
	private const CIPHER = 'aes-256-gcm';

	   
                                                                      
                                                                  
                                                                     
                                                                 
   
                    
    
	private ?object $vaultProvider = null;

	   
                
   
                                                    
                                          
    
	public function __construct(
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                      
                                                   
   
                                                      
   
                
                                                                                      
                                                 
    
	public function setVaultProvider(object $provider): void {
		$this->vaultProvider = $provider;
	}                        

	   
                                                                  
   
                                                                
                                       
   
                                                                 
                                                                         
   
                                                          
   
                                                 
   
                                                                                                          
    
	public function encrypt(string $plaintext, string $tenantId): string {
		$key = $this->getEncryptionKey(tenantId: $tenantId);
		$initVector = random_bytes(self::IV_BYTES);
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$initVector,
			$tag,
			'',
			self::TAG_BYTES
		);

		if ($ciphertext === false) {
			throw new RuntimeException('AES-256-GCM encryption failed.');
		}

		return base64_encode($initVector . $ciphertext . $tag);
	}               

	   
                                                              
   
                                                                     
                                              
   
                                                
   
                                                                             
   
                                                                                                          
    
	public function decrypt(string $ciphertext, string $tenantId): string {
		$raw = base64_decode($ciphertext, true);
		if ($raw === false || strlen($raw) < (self::IV_BYTES + self::TAG_BYTES + 1)) {
			throw new RuntimeException('Malformed ciphertext payload.');
		}

		$initVector = substr($raw, 0, self::IV_BYTES);
		$tag = substr($raw, -self::TAG_BYTES);
		$cipher = substr($raw, self::IV_BYTES, -self::TAG_BYTES);
		$keys = $this->getDecryptionKeys(tenantId: $tenantId);

		foreach ($keys as $key) {
			$plain = openssl_decrypt(
				$cipher,
				self::CIPHER,
				$key,
				OPENSSL_RAW_DATA,
				$initVector,
				$tag
			);
			if ($plain !== false) {
				return $plain;
			}
		}

		$this->logger->error('Berichtenbox EncryptionService decrypt failed (all keys exhausted).');
		throw new RuntimeException('AES-256-GCM decryption failed.');
	}               

	   
                                                                   
                                                                     
                                         
   
                                           
                                              
   
                                                  
                                                                                      
                                                 
    
	public function hashBsn(string $plaintext, string $tenantId): string {
		$hmacKey = $this->getHmacKey(tenantId: $tenantId);
		return hash_hmac('sha256', $plaintext, $hmacKey);
	}               

	   
                                                                      
   
                                                         
                                                          
                                              
   
                                                         
                                                                                      
                                                 
    
	public function bsnEquals(string $candidate, string $hash, string $tenantId): bool {
		return hash_equals($hash, $this->hashBsn(plaintext: $candidate, tenantId: $tenantId));
	}                 

	   
                                                           
   
                                              
   
                                
   
                                                           
    
	private function getEncryptionKey(string $tenantId): string {
		if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getEncryptionKey') === true) {
			try {
				$key = (string)$this->vaultProvider->getEncryptionKey($tenantId);
				if (strlen($key) === self::KEY_BYTES) {
					return $key;
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Berichtenbox vault encryption-key lookup failed; falling back to derived key.',
					['exception' => $e->getMessage()]
				);
			}
		}

		return $this->deriveKey(tenantId: $tenantId, purpose: 'enc.active');
	}                        

	   
                                                                     
                                                            
   
                                              
   
                                                                
    
	private function getDecryptionKeys(string $tenantId): array {
		$keys = [];
		if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getDecryptionKeys') === true) {
			try {
				$vaultKeys = $this->vaultProvider->getDecryptionKeys($tenantId);
				if (is_array($vaultKeys) === true) {
					foreach ($vaultKeys as $vaultKey) {
						if (is_string($vaultKey) === true && strlen($vaultKey) === self::KEY_BYTES) {
							$keys[] = $vaultKey;
						}
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Berichtenbox vault decryption-keys lookup failed; falling back to derived key.',
					['exception' => $e->getMessage()]
				);
			}
		}

		$keys[] = $this->deriveKey(tenantId: $tenantId, purpose: 'enc.active');
		                                                                               
		$keys[] = $this->deriveKey(tenantId: $tenantId, purpose: 'enc.previous');

		return $keys;
	}                         

	   
                                           
   
                                              
   
                                                                 
    
	private function getHmacKey(string $tenantId): string {
		if ($this->vaultProvider !== null && method_exists($this->vaultProvider, 'getHmacKey') === true) {
			try {
				$key = (string)$this->vaultProvider->getHmacKey($tenantId);
				if ($key !== '') {
					return $key;
				}
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Berichtenbox vault HMAC-key lookup failed; falling back to derived key.',
					['exception' => $e->getMessage()]
				);
			}
		}

		return $this->deriveKey(tenantId: $tenantId, purpose: 'hmac.bsn');
	}                  

	   
                                                                          
                                                                       
                                                                          
   
                                                                    
                                                                       
   
                                
    
	private function deriveKey(string $tenantId, string $purpose): string {
		$systemSecret = (string)$this->config->getSystemValue('secret', '');
		if ($systemSecret === '') {
			                                                                            
			$systemSecret = (string)$this->config->getSystemValue('instanceid', 'pipelinq-dev');
		}

		return hash_hkdf(
			'sha256',
			$systemSecret,
			self::KEY_BYTES,
			'berichtenbox.' . $purpose . ':' . $tenantId
		);
	}                 
}           
