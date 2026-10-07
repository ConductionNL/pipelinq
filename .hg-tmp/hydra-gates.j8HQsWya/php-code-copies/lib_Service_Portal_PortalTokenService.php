<?php

   
                               
  
                                                                            
                                                                              
                                                                              
                                                                                
                                                                       
                                                                        
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

   
                                                       
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalTokenService {
	   
                                            
   
            
    
	private const TOKEN_BYTES = 32;

	   
                
   
                                                  
                                               
    
	public function __construct(
		private ISecureRandom $secureRandom,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                                           
                                                                  
   
                                                           
   
                                                                                     
                                                                                         
                                                                            
                                                                            
                               
    
	public function issue(int $ttlMinutes): array {
		$plain = $this->randomToken();
		$expiry = $this->time->getDateTime();
		$expiry->modify('+' . max(1, $ttlMinutes) . ' minutes');

		return [
			'plain' => $plain,
			'hash' => $this->hash(plain: $plain),
			'expiresAt' => $expiry->format(DATE_ATOM),
		];
	}             

	   
                                                                        
   
                                                                           
                                                
   
                                                            
                                                           
                                                                      
   
                                                            
                                                                                         
                                                                            
                                                                            
                               
    
	public function verify(?string $plain, ?string $storedHash, ?string $expiresAt): bool {
		if ($plain === null || $plain === '' || $storedHash === null || $storedHash === '') {
			return false;
		}

		if (hash_equals($storedHash, $this->hash(plain: $plain)) === false) {
			return false;
		}

		return $this->isUnexpired(expiresAt: $expiresAt);
	}              

	   
                                                                
   
                                                                
   
                                     
                                                                                         
                                                                            
                                                                            
                               
    
	public function isUnexpired(?string $expiresAt): bool {
		if ($expiresAt === null || $expiresAt === '') {
			return false;
		}

		$now = $this->time->getDateTime()->getTimestamp();
		$exp = strtotime($expiresAt);
		if ($exp === false) {
			return false;
		}

		return $exp > $now;
	}                   

	   
                                                   
   
                                             
   
                                          
                                                                                         
                                                                            
                                                                            
                               
    
	public function hash(string $plain): string {
		return hash('sha256', $plain);
	}            

	   
                                             
   
                                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function randomToken(): string {
		$bytes = $this->secureRandom->generate(
			self::TOKEN_BYTES,
			ISecureRandom::CHAR_ALPHANUMERIC
		);

		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}                   
}           
