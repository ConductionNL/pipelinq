<?php

   
                             
  
                                                                         
                                                                        
                                                                     
                                                                    
                                                            
  
                                                                       
                                                                           
                                                                         
                                                                         
                                                                         
                                                                    
  
                                                                   
                                                                      
                                                                           
                                                                
                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

   
                                                                    
  
                                                                                                                               
   
class ListTokenService {
	   
                                   
   
               
    
	public const PURPOSE_CONFIRM = 'confirm';

	   
                                   
   
               
    
	public const PURPOSE_UNSUBSCRIBE = 'unsub';

	   
                                        
   
               
    
	public const PURPOSE_PREFERENCES = 'prefs';

	   
                                                            
   
               
    
	private const SECRET_CONFIG_KEY = 'lists.token_secret';

	   
                                                              
   
               
    
	private const CONFIRM_TTL_CONFIG_KEY = 'lists.confirm_token_ttl_days';

	   
                                                                            
   
               
    
	private const LINK_TTL_CONFIG_KEY = 'lists.link_token_ttl_days';

	   
                                                             
   
            
    
	private const DEFAULT_CONFIRM_TTL_DAYS = 7;

	   
                                                                       
   
            
    
	private const DEFAULT_LINK_TTL_DAYS = 730;

	   
                
   
                                                     
                                                           
                                                                      
                                                             
   
                                                                                                                                
    
	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private ISecureRandom $secureRandom,
		private IURLGenerator $urlGenerator,
	) {
	}                   

	   
                                                
   
                                                     
   
                                                                                                                                
    
	public function mintNonce(): string {
		return $this->secureRandom->generate(43, ISecureRandom::CHAR_ALPHANUMERIC);
	}                 

	   
                                                   
   
                                                                       
                                 
   
                                                                 
   
                                                                    
                               
   
                                                                                                                                
    
	public function digest(string $nonce): string {
		if ($nonce === '') {
			return '';
		}

		return hash('sha256', $nonce);
	}              

	   
                                                            
   
                                                                     
                                                                   
   
                                              
   
                                                                     
                                    
   
                                                                                                                                
    
	public function hashAddress(string $address): string {
		if ($address === '') {
			return '';
		}

		return hash_hmac('sha256', $address, $this->signingKey());
	}                   

	   
                                                  
   
                                                            
                                                                       
   
                                                            
   
                                                                                                                                
    
	public function signConfirmToken(string $subscriptionId, string $nonce): string {
		return $this->sign(
			purpose: self::PURPOSE_CONFIRM,
			claims: ['s' => $subscriptionId, 'n' => $nonce],
			ttlSeconds: $this->ttlSeconds(key: self::CONFIRM_TTL_CONFIG_KEY, default: self::DEFAULT_CONFIRM_TTL_DAYS),
		);
	}                        

	   
                                                  
   
                                                            
                                                                      
                                                                     
   
                                                            
   
                                                                                                           
    
	public function signUnsubscribeToken(string $subscriptionId, string $contactId): string {
		return $this->sign(
			purpose: self::PURPOSE_UNSUBSCRIBE,
			claims: ['s' => $subscriptionId, 'c' => $contactId],
			ttlSeconds: $this->ttlSeconds(key: self::LINK_TTL_CONFIG_KEY, default: self::DEFAULT_LINK_TTL_DAYS),
		);
	}                            

	   
                                                  
   
                                                  
   
                                                            
   
                                                                                                                      
    
	public function signPreferencesToken(string $contactId): string {
		return $this->sign(
			purpose: self::PURPOSE_PREFERENCES,
			claims: ['c' => $contactId],
			ttlSeconds: $this->ttlSeconds(key: self::LINK_TTL_CONFIG_KEY, default: self::DEFAULT_LINK_TTL_DAYS),
		);
	}                            

	   
                                                                     
   
                                                                       
                                              
   
                                             
                                                            
   
                                                                       
                                                            
   
                                                                                                                
    
	public function verify(string $token, string $purpose): ?array {
		$parts = explode('.', $token);
		if (count($parts) !== 2) {
			return null;
		}

		[$encoded, $signature] = $parts;
		if ($encoded === '' || $signature === '') {
			return null;
		}

		$expected = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		if (hash_equals($expected, $signature) === false) {
			return null;
		}

		$decoded = json_decode($this->base64UrlDecode(data: $encoded), true);
		if (is_array($decoded) === false || isset($decoded['exp']) === false) {
			return null;
		}

		if ((string)($decoded['p'] ?? '') !== $purpose) {
			return null;
		}

		if ((int)$decoded['exp'] < $this->time->getTime()) {
			return null;
		}

		return $decoded;
	}              

	   
                                                          
   
                                                       
   
                                
   
                                                                                                                  
    
	public function confirmUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('pipelinq.listPublic.confirm', ['token' => $token]);
	}                  

	   
                                                         
   
                                                      
   
                                
   
                                                                                                           
    
	public function unsubscribeUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('pipelinq.listPublic.unsubscribePage', ['token' => $token]);
	}                      

	   
                                                      
   
                                                      
   
                                
   
                                                                                                                      
    
	public function preferencesUrl(string $token): string {
		return $this->urlGenerator->linkToRouteAbsolute('pipelinq.listPublic.preferences', ['token' => $token]);
	}                      

	   
                                 
   
                                                            
                                                                
                                               
   
                                    
    
	private function sign(string $purpose, array $claims, int $ttlSeconds): string {
		$issuedAt = $this->time->getTime();
		$full = array_merge(
			['p' => $purpose],
			$claims,
			['iat' => $issuedAt, 'exp' => ($issuedAt + $ttlSeconds)],
		);

		$encoded = $this->base64UrlEncode(data: (string)json_encode($full));
		$signature = $this->base64UrlEncode(data: hash_hmac('sha256', $encoded, $this->signingKey(), true));
		return ($encoded . '.' . $signature);
	}            

	   
                                                               
   
                                   
    
	private function signingKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, '');
		if ($key === '') {
			$key = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->appConfig->setValueString(Application::APP_ID, self::SECRET_CONFIG_KEY, $key, false, true);
		}

		return $key;
	}                  

	   
                                                                        
   
                                                                   
                                                  
   
                                    
    
	private function ttlSeconds(string $key, int $default): int {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, (string)$default);
		if (is_numeric($raw) === false) {
			return ($default * 86400);
		}

		$days = (int)$raw;
		if ($days <= 0) {
			return ($default * 86400);
		}

		return ($days * 86400);
	}                  

	   
                                                      
   
                                     
   
                                      
    
	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}                       

	   
                     
   
                                           
   
                                                      
    
	private function base64UrlDecode(string $data): string {
		$decoded = base64_decode(strtr($data, '-_', '+/'), true);
		if ($decoded === false) {
			return '';
		}

		return $decoded;
	}                       
}           
