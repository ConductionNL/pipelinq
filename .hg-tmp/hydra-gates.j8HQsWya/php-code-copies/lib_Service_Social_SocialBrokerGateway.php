<?php

   
                                
  
                                                                        
                                             
  
                                                                         
                                                                              
                                                                              
                                                                         
                                                                           
                                                                        
                                                                           
                                                                     
  
                                                                              
                                                                      
                                            
  
                                                              
                                                                
                                                                            
                                                                             
                                                                           
                                                                           
                          
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                          
  
                                                                                                                                          
  
                                                                  
                                                                    
                                                                        
                                                                           
                                                                          
   
class SocialBrokerGateway {
	   
                                                                         
                                                    
   
               
    
	public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	   
                                                                       
                                                                    
   
               
    
	public const CATALOGUE_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\ProviderCatalogue';

	   
                                                                         
                                                     
   
               
    
	public const RELINK_EXCEPTION = 'OCA\\OpenRegister\\Service\\Credential\\CredentialRelinkRequiredException';

	   
                                       
   
               
    
	public const ACCESS_EXCEPTION = 'OCA\\OpenRegister\\Service\\Credential\\CredentialAccessDeniedException';

	   
                                                                            
                                                      
   
               
    
	public const APP_ID = 'pipelinq';

	   
                                        
   
               
    
	public const READY = 'ready';

	   
                                                                        
                                                                         
                                                                        
                                                                           
   
               
    
	public const PREVIEW = 'preview';

	   
                                                                   
                                           
   
               
    
	public const NOT_CONFIGURED = 'not_configured';

	   
                                                                          
                                                                         
                                                                         
              
   
                           
    
	private const BROKER_OWNED_HEADERS = ['authorization', 'x-api-key', 'apikey', 'dpop'];

	   
                
   
                                                                                   
                                                                      
   
                
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                           
   
                                                            
   
                                                                                                                                           
    
	public function isAvailable(): bool {
		return class_exists(self::BROKER_CLASS);
	}                   

	   
                                              
   
                                                                          
                                                                        
                                                                     
                                                                            
                                                                          
                        
   
                                                                                        
   
                                                                              
   
                                                                                                                                                  
    
	public function readiness(string $brokerProvider): array {
		if ($brokerProvider === '') {
			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'This network has no developer application yet, so an account cannot be connected.',
			];
		}

		if (class_exists(self::CATALOGUE_CLASS) === false) {
			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'OpenRegister is not available, so no network can be connected.',
			];
		}

		$entry = null;
		try {
			$catalogue = $this->container->get(self::CATALOGUE_CLASS);
			$entry = $catalogue->get($brokerProvider);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'SocialBrokerGateway.readiness: the provider catalogue could not be read',
				['provider' => $brokerProvider, 'exception' => $failure->getMessage()]
			);

			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'The credential provider catalogue could not be read.',
			];
		}

		if (is_array($entry) === false) {
			return [
				'state' => self::NOT_CONFIGURED,
				'reason' => 'No developer application is filed for this network yet, so an account cannot be connected.',
			];
		}

		if (($entry['preview'] ?? false) === true) {
			return [
				'state' => self::PREVIEW,
				'reason' => 'This network is still a preview in the credential broker, so the network itself may refuse a post.',
			];
		}

		return ['state' => self::READY, 'reason' => ''];
	}                 

	   
                                                  
   
                                                                                  
                                                                      
                                                                                          
                                                                                        
                                                           
                                                                                                       
   
                                                                                      
   
                                                                                                                                        
   
                                                                             
                                                                          
                                                                           
                          
    
	public function request(
		string $credentialRef,
		string $method,
		string $path,
		array $headers = [],
		?string $body = null,
		?string $actingUserId = null,
	): SocialGatewayResult {
		if ($this->isAvailable() === false) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::UNAVAILABLE,
				reason: 'The OpenRegister credential broker is not installed, so nothing can be published.',
			);
		}

		if (trim($credentialRef) === '') {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::NOT_CONFIGURED,
				reason: 'This account is not connected yet, so there is no credential to publish with.',
			);
		}

		try {
			$broker = $this->container->get(self::BROKER_CLASS);
			$response = $broker->request(
				$credentialRef,
				self::APP_ID,
				strtoupper($method),
				$path,
				$this->withoutBrokerOwnedHeaders(headers: $headers),
				$body,
				$actingUserId
			);
		} catch (Throwable $failure) {
			return $this->classify(failure: $failure, method: $method, path: $path);
		}

		return $this->interpret(response: $response, method: $method, path: $path);
	}               

	   
                                                                     
   
                                                                  
                                                                            
                                                                          
                                                                          
   
                                                    
                                                                     
                                                                 
   
                                                  
    
	private function classify(Throwable $failure, string $method, string $path): SocialGatewayResult {
		                                                                      
		                                                                     
		$this->logger->warning(
			'SocialBrokerGateway: the brokered call failed',
			['method' => strtoupper($method), 'path' => $path, 'type' => $failure::class]
		);

		if (is_a($failure, self::RELINK_EXCEPTION) === true) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::RELINK_NEEDED,
				reason: 'The connection to this account has ended. Reconnect it and the post can go out again.',
			);
		}

		if (is_a($failure, self::ACCESS_EXCEPTION) === true) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::NOT_PERMITTED,
				reason: 'The credential broker refused this call. Check who owns the account and which app it was granted to.',
			);
		}

		return SocialGatewayResult::failed(
			code: SocialGatewayResult::UNAVAILABLE,
			reason: 'The network could not be reached. This can be tried again.',
		);
	}                

	   
                                           
   
                                                                        
                                                                  
                                                              
   
                                            
    
	private function interpret(mixed $response, string $method, string $path): SocialGatewayResult {
		if (is_array($response) === false) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::UNAVAILABLE,
				reason: 'The credential broker answered in a shape this version does not understand.',
			);
		}

		$status = (int)($response['status'] ?? 0);
		$raw = (string)($response['body'] ?? '');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		if ($status >= 200 && $status < 300) {
			return SocialGatewayResult::succeeded(status: $status, body: $decoded);
		}

		$this->logger->warning(
			'SocialBrokerGateway: the network refused the call',
			['method' => strtoupper($method), 'path' => $path, 'status' => $status]
		);

		if ($status === 401 || $status === 403) {
			                                                                    
			                                                                     
			                            
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::RELINK_NEEDED,
				reason: 'The network refused the connection for this account. Reconnect it to restore the permissions.',
				status: $status,
			);
		}

		if ($status >= 400 && $status < 500) {
			return SocialGatewayResult::failed(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: $this->networkMessage(decoded: $decoded, status: $status),
				status: $status,
			);
		}

		return SocialGatewayResult::failed(
			code: SocialGatewayResult::UNAVAILABLE,
			reason: 'The network answered with an error of its own. This can be tried again.',
			status: $status,
		);
	}                 

	   
                                                       
   
                                                                            
                                                                          
                                                                 
   
                                                                   
                                           
   
                                     
    
	private function networkMessage(array $decoded, int $status): string {
		$candidates = [
			($decoded['error'] ?? null),
			($decoded['message'] ?? null),
			($decoded['detail'] ?? null),
			($decoded['error_description'] ?? null),
		];

		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return 'The network refused the post: ' . mb_substr(trim($candidate), 0, 300);
			}
		}

		return 'The network refused the post with status ' . $status . '.';
	}                      

	   
                                     
   
                                                                
   
                                                                             
    
	private function withoutBrokerOwnedHeaders(array $headers): array {
		$kept = [];
		foreach ($headers as $name => $value) {
			if (in_array(strtolower((string)$name), self::BROKER_OWNED_HEADERS, true) === true) {
				continue;
			}

			$kept[(string)$name] = (string)$value;
		}

		return $kept;
	}                                 
}           
