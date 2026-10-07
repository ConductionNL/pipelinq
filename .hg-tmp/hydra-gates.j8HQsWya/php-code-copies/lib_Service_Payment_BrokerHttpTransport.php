<?php

   
                                
  
                                                                                    
                                 
  
                                                                                     
                                                                                         
                                                                                  
                                                                                      
                                                                                        
              
  
                                                                                     
                                                                                      
                                                                                        
                                                                      
  
                                           
  
                                                                                         
                                                                                         
                                                                                 
                                                                                       
                                                                                         
                             
  
                                                                                     
                                                                                    
                                                                                     
                          
  
                    
                                         
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Payment;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                                 
  
                                                                                     
   
class BrokerHttpTransport implements HttpTransport {
	   
                                                                                   
                                  
   
               
    
	public const BROKER_CLASS = 'OCA\\OpenRegister\\Service\\Credential\\CredentialBrokerService';

	   
                                                                                   
                                                       
   
               
    
	public const APP_ID = 'pipelinq';

	   
                                                                                 
                                                                            
   
                           
    
	private const BROKER_OWNED_HEADERS = [
		'authorization',
		'x-api-key',
		'apikey',
	];

	   
                
   
                                                                          
                                                                         
                                          
                                              
                                                                             
                                                                        
                                                                       
                                                                            
                                                                            
                                                                 
                                                                                   
                                                                             
                                                                      
    
	public function __construct(
		private string $credentialId,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private ?string $actingUserId = null,
	) {
	}                   

	   
                                                          
   
                                                            
   
                                                                                      
    
	public static function isAvailable(): bool {
		return class_exists(self::BROKER_CLASS) === true;
	}                   

	   
                                           
   
                                          
                                                                   
                                                                
                                          
                                                                                          
                                                       
   
                                                                       
   
                                                                                      
    
	public function request(string $method, string $url, array $headers = [], ?string $body = null): array {
		$empty = [
			'status' => 0,
			'body' => [],
			'raw' => '',
		];

		if (self::isAvailable() === false) {
			                                                                              
			                                                                      
			$this->logger->error(
				'Pipelinq POS payment: the OpenRegister credential broker is not available; refusing to call the PSP.'
			);
			return $empty;
		}

		if ($this->credentialId === '') {
			$this->logger->error('Pipelinq POS payment: no broker credential configured for this provider.');
			return $empty;
		}

		try {
			$broker = $this->container->get(self::BROKER_CLASS);
			$response = $broker->request(
				$this->credentialId,
				self::APP_ID,
				$method,
				$this->toPath(url: $url),
				$this->stripBrokerOwnedHeaders(headers: $headers),
				$body,
				$this->actingUserId
			);
		} catch (Throwable $e) {
			                                                                             
			                                                                           
			                                                
			$this->logger->warning(
				'Pipelinq POS payment: broker call failed',
				[
					'method' => $method,
					'path' => $this->toPath(url: $url),
				]
			);
			return $empty;
		}         

		$status = (int)($response['status'] ?? 0);
		$raw = (string)($response['body'] ?? '');

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return [
			'status' => $status,
			'body' => $decoded,
			'raw' => $raw,
		];
	}               

	   
                                                             
   
                                                                                    
                                                                                    
   
                                    
   
                                                                
    
	private function toPath(string $url): string {
		$path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
		if ($path === '') {
			$path = '/';
		}

		$query = parse_url($url, PHP_URL_QUERY);
		if (is_string($query) === true && $query !== '') {
			$path .= '?' . $query;
		}

		return $path;
	}              

	   
                                     
   
                                                                
   
                                                                           
    
	private function stripBrokerOwnedHeaders(array $headers): array {
		$out = [];
		foreach ($headers as $name => $value) {
			if (in_array(strtolower((string)$name), self::BROKER_OWNED_HEADERS, true) === true) {
				continue;
			}

			$out[$name] = $value;
		}

		return $out;
	}                               
}           
