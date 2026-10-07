<?php

   
                                   
  
                                                                         
                                                                              
                                                                              
                                                                      
                                                                               
                                                                            
                                       
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

   
                                                                   
  
                                                                                        
                                                                           
                                                                           
                              
   
class DocumentSigningService {
	   
                                                           
   
               
    
	private const SIGNING_KEY = 'portal_document_signing_key';

	   
                                 
   
            
    
	public const DEFAULT_TTL_MINUTES = 5;

	   
                
   
                                                
                                                                        
                                               
    
	public function __construct(
		private IAppConfig $appConfig,
		private ISecureRandom $secureRandom,
		private ITimeFactory $time,
	) {
	}                   

	   
                                                                          
   
                                          
                                                             
                                                                                
                                              
   
                                                                                   
                                                                                         
                                                                            
                                                                            
                               
    
	public function generateUrl(
		string $objectId,
		string $objectType,
		string $accountId,
		int $ttlMinutes = self::DEFAULT_TTL_MINUTES,
	): array {
		$issuedAt = $this->time->getTime();
		$expiresAt = ($issuedAt + (max(1, $ttlMinutes) * 60));

		$payload = [
			'objectId' => $objectId,
			'objectType' => $objectType,
			'accountId' => $accountId,
			'issuedAt' => $issuedAt,
			'expiresAt' => $expiresAt,
		];

		$encoded = $this->base64UrlEncode(data: json_encode($payload));
		$signature = $this->base64UrlEncode(data: $this->sign(encoded: $encoded));
		$token = $encoded . '.' . $signature;

		return [
			'token' => $token,
			'path' => '/portal/api/documents/' . $token . '/download',
			'expiresAt' => $expiresAt,
		];
	}                   

	   
                                                            
   
                                                                         
                                                                              
                                                                          
   
                                             
   
                                                                             
                                                                                         
                                                                            
                                                                            
                               
    
	public function validateToken(string $token): array|string|null {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}

		[$encoded, $signature] = $parts;
		$expected = $this->base64UrlEncode(data: $this->sign(encoded: $encoded));
		if (hash_equals($expected, $signature) === false) {
			return null;
		}

		$decoded = json_decode((string)$this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['expiresAt']) === false) {
			return null;
		}

		if ((int)$decoded['expiresAt'] < $this->time->getTime()) {
			return 'expired';
		}

		return $decoded;
	}                     

	   
                                                            
   
                                                         
   
                                      
    
	private function sign(string $encoded): string {
		return hash_hmac('sha256', $encoded, $this->signingKey(), true);
	}            

	   
                                                                  
   
                                   
    
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SIGNING_KEY, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SIGNING_KEY, $key, false, true);
		}

		return $key;
	}                  

	   
                                               
   
                                     
   
                                      
    
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}                       

	   
                     
   
                                           
   
                                                    
    
	private function base64UrlDecode(string $data): string|false {
		return base64_decode(strtr($data, '-_', '+/'), true);
	}                       
}           
