<?php

   
                                 
  
                                                                   
                                                                         
                                                                   
                                                                        
                                                                     
                                                                        
  
                    
                                          
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                                 
  
                                                                        
                                                                                 
               
  
                                                                     
   
trait MessageDispatchTrait {

	   
                                                                 
   
               
    
	private static string $dispatchLeafClass = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\MessageDispatchProvider';

	   
                                                                   
   
                                                                     
                                                                            
                                                                      
                                                   
                                                                         
                                                                            
                                                                            
                                                        
   
                                                                        
                                                                    
                                                                          
                                                                         
   
                                                                    
   
                                                                                     
                                                                                              
   
                                                                      
    
	private function dispatchViaLeaf(string $source, array $body, string $path, array $headers = []): array {
		if ($source === '') {
			throw new PermanentSmsProviderException('messaging source slug not configured');
		}

		$provider = $this->resolveDispatchProvider();

		try {
			$result = $provider->dispatch(source: $source, body: $body, path: $path, headers: $headers);
		} catch (TransientSmsProviderException|PermanentSmsProviderException $e) {
			throw $e;
		} catch (Throwable $e) {
			                                                                   
			                                                                  
			$this->logger->warning(
				'MessageDispatchTrait.dispatchViaLeaf: leaf threw unexpectedly',
				['message' => $e->getMessage()]
			);
			throw new TransientSmsProviderException($e->getMessage(), 0, $e);
		}

		if (is_array($result) === false) {
			return [];
		}

		if (($result['unavailable'] ?? false) === true) {
			$this->throwForCause(cause: (string)($result['cause'] ?? ''));
		}

		$response = $result['response'] ?? [];
		if (is_array($response) === true) {
			return $response;
		}

		return [];
	}                       

	   
                                                                        
   
                                                                
   
                                                                                       
   
                                                                      
    
	private function resolveDispatchProvider(): object {
		if (class_exists(self::$dispatchLeafClass) === false) {
			throw new PermanentSmsProviderException('OpenRegister MessageDispatchProvider not available');
		}

		try {
			$provider = $this->container->get(self::$dispatchLeafClass);
		} catch (Throwable $e) {
			throw new PermanentSmsProviderException('OpenRegister MessageDispatchProvider not available: ' . $e->getMessage());
		}

		if (is_object($provider) === false || method_exists($provider, 'dispatch') === false) {
			throw new PermanentSmsProviderException('OpenRegister MessageDispatchProvider lacks dispatch');
		}

		return $provider;
	}                               

	   
                                                                     
   
                                                                    
                                                                          
                                                                             
                                                                             
   
                                                                        
   
                 
   
                                                               
                                                               
   
                                                                      
    
	private function throwForCause(string $cause): never {
		$this->logger->warning(
			'MessageDispatchTrait: dispatch degraded',
			['cause' => $cause]
		);

		if ($cause === 'openconnector-source-missing' || $cause === 'provider-auth') {
			throw new PermanentSmsProviderException('messaging source unavailable: ' . $cause);
		}

		throw new TransientSmsProviderException('messaging source unavailable: ' . $cause);
	}                     
}           
