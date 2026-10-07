<?php

   
                                     
  
                                                                        
                                                                       
                                                                       
                                                                          
                                                                         
                                                                    
  
                    
                                               
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

   
                                                                            
  
                                                                                                                                                                            
   
class GoogleServiceAccountAuth {

	   
                                                       
   
               
    
	public const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

	   
                                                                      
   
            
    
	public const ASSERTION_TTL = 3600;

	   
                
   
                                                                       
                                                           
                                          
   
                                                                                                                                                                             
    
	public function __construct(
		private IClientService $clientService,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                  
   
                                              
   
                                                                                                         
   
                                                                                                                                                                             
    
	public function parseKey(string $json): ?array {
		$decoded = json_decode(trim($json), true);
		if (is_array($decoded) === false) {
			return null;
		}

		$email = (string)($decoded['client_email'] ?? '');
		$privateKey = (string)($decoded['private_key'] ?? '');
		$type = (string)($decoded['type'] ?? 'service_account');
		if ($email === '' || $privateKey === '' || $type !== 'service_account') {
			return null;
		}

		$tokenUri = (string)($decoded['token_uri'] ?? '');
		if ($tokenUri === '' || preg_match('#^https://#', $tokenUri) !== 1) {
			$tokenUri = self::DEFAULT_TOKEN_URI;
		}

		return ['client_email' => $email, 'private_key' => $privateKey, 'token_uri' => $tokenUri];
	}                

	   
                                                      
   
                                                                      
                                                                       
                                                                  
   
                                   
   
                                                                                 
   
                                                                                                                                                                             
    
	public function buildAssertion(array $key, string $scope, ?int $now = null): string {
		$issuedAt = ($now ?? $this->time->getTime());
		$header = self::base64Url(data: (string)json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$claims = self::base64Url(
			data: (string)json_encode(
				[
					'iss' => $key['client_email'],
					'scope' => $scope,
					'aud' => $key['token_uri'],
					'iat' => $issuedAt,
					'exp' => ($issuedAt + self::ASSERTION_TTL),
				]
			)
		);

		$signingInput = ($header . '.' . $claims);
		$privateKey = openssl_pkey_get_private($key['private_key']);
		if ($privateKey === false) {
			throw new RuntimeException('Search Console: the service account private key does not load');
		}

		$signature = '';
		$signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
		if ($signed === false || $signature === '') {
			throw new RuntimeException('Search Console: signing the assertion failed');
		}

		return ($signingInput . '.' . self::base64Url(data: $signature));
	}                      

	   
                                              
   
                                                   
                                         
   
                                    
   
                                                                            
   
                                                                                                                                                                             
    
	public function accessToken(array $key, string $scope): string {
		$assertion = $this->buildAssertion(key: $key, scope: $scope);
		try {
			$response = $this->clientService->newClient()->post(
				$key['token_uri'],
				[
					'body' => [
						'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						'assertion' => $assertion,
					],
					'timeout' => 20,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Search Console: token exchange failed', ['exception' => $e->getMessage()]);
			throw new RuntimeException('Search Console: token exchange failed', 0, $e);
		}

		$body = $response->getBody();
		$decoded = json_decode((string)$body, true);
		$token = '';
		if (is_array($decoded) === true) {
			$token = (string)($decoded['access_token'] ?? '');
		}

		if ($token === '') {
			throw new RuntimeException('Search Console: token exchange answered without an access token');
		}

		return $token;
	}                   

	   
                                                      
   
                                     
   
                                      
   
                                                                                                                                                                             
    
	public static function base64Url(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}                 
}           
