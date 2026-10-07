<?php

   
                            
  
                                                                              
                                                                             
                                                                              
                                                                            
                                                                         
                                                                           
                                               
  
                                                                       
                                                                               
                                                                      
                                                                           
                    
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Egress;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Support\FleetAppId;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                       
  
                                                                                                                                                                         
  
                                                                      
                                                                    
                                                                         
                                                                   
                                                                         
                         
   
class ConnectorEgress {

	   
                                                                  
   
                                                                           
                                                                              
                                                                       
                                                                              
                                                    
   
               
    
	public const SOURCE_REGISTER_CANONICAL = ConnectorSourceRegister::CANONICAL_REGISTER;

	   
                                             
   
               
    
	public const SOURCE_SCHEMA = 'source';

	   
                                                                
   
               
    
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	   
                
   
                                                                                   
                                                                             
                                                                                                     
                                          
   
                                                                                                                                                                          
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ConnectorSourceRegister $connectorRegister,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                       
   
                                                                      
   
                
   
                                                                                                                                                                          
    
	public function isConfigured(string $configKey): bool {
		return ($this->sourceId(configKey: $configKey) !== '');
	}                    

	   
                                                               
   
                                                                      
                                                                 
                                                                                               
                                                                               
   
                                                               
   
                                                                                                                                                                          
    
	public function read(string $configKey, string $endpoint, array $config = [], string $method = 'GET'): EgressResult {
		$sourceId = $this->sourceId(configKey: $configKey);
		if ($sourceId === '') {
			return EgressResult::failed(
				failure: EgressResult::NOT_CONFIGURED,
				reason: 'No OpenConnector source is configured under ' . $configKey . '.'
			);
		}

		$source = $this->resolveSource(sourceId: $sourceId);
		if ($source === null) {
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'The OpenConnector source ' . $sourceId . ' could not be resolved.'
			);
		}

		return $this->readThrough(source: $source, sourceId: $sourceId, endpoint: $endpoint, config: $config, method: $method);
	}            

	   
                                                                         
                          
   
                                                                       
                                                                            
                                                                           
                                                                          
                                                                          
                                                                         
                                                                        
                                      
   
                                                                               
                                                
                                                                 
                                                                                 
   
                                                               
   
                                                                                                                                                                          
    
	public function readUrl(string $configKey, string $url, array $config = [], string $sourceId = ''): EgressResult {
		$resolvedId = trim($sourceId);
		if ($resolvedId === '') {
			$resolvedId = $this->sourceId(configKey: $configKey);
		}

		if ($resolvedId === '') {
			return EgressResult::failed(
				failure: EgressResult::NOT_CONFIGURED,
				reason: 'No OpenConnector source is configured under ' . $configKey . '.'
			);
		}

		$source = $this->resolveSource(sourceId: $resolvedId);
		if ($source === null) {
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'The OpenConnector source ' . $resolvedId . ' could not be resolved.'
			);
		}

		$location = rtrim((string)($this->toArray(value: $source)['location'] ?? ''), '/');
		$endpoint = trim($url);
		if ($location !== '') {
			if (str_starts_with($endpoint, ($location . '/')) === false && $endpoint !== $location) {
				return EgressResult::failed(
					failure: EgressResult::REFUSED,
					reason: 'The source only reaches ' . $location . ', so ' . $url . ' was not requested.'
				);
			}

			$endpoint = substr($endpoint, strlen($location));
		}

		return $this->readThrough(source: $source, sourceId: $resolvedId, endpoint: $endpoint, config: $config, method: 'GET');
	}               

	   
                                                 
   
                                                                                 
                                                       
                                                                          
                                                                 
                                          
   
                        
    
	private function readThrough(object|array $source, string $sourceId, string $endpoint, array $config, string $method): EgressResult {
		$callService = $this->resolveCallService();
		if ($callService === null) {
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'OpenConnector is not available on this instance.'
			);
		}

		try {
			$callLog = $callService->call(
				source: $source,
				endpoint: $endpoint,
				method: $method,
				config: $config,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorEgress.read: the call failed',
				['sourceId' => $sourceId, 'endpoint' => $endpoint, 'exception' => $e->getMessage()]
			);
			return EgressResult::failed(
				failure: EgressResult::UNAVAILABLE,
				reason: 'The call through OpenConnector failed.'
			);
		}

		return $this->fromCallLog(callLog: $callLog, endpoint: $endpoint);
	}                   

	   
                                                
   
                                                       
                                                           
   
                        
    
	private function fromCallLog(mixed $callLog, string $endpoint): EgressResult {
		$data = $this->toArray(value: $callLog);
		$status = (int)($data['statusCode'] ?? 0);
		$response = ($data['response'] ?? null);
		if (is_array($response) === true) {
			$status = (int)($response['statusCode'] ?? $status);
		}

		if ($status < 200 || $status >= 300) {
			return EgressResult::failed(
				failure: EgressResult::REFUSED,
				reason: 'Reading ' . $endpoint . ' answered with status ' . $status . '.',
				status: $status
			);
		}

		$body = '';
		if (is_array($response) === true && is_string($response['body'] ?? null) === true) {
			$body = (string)$response['body'];
		}

		return EgressResult::success(body: $body, status: $status);
	}                   

	   
                                      
   
                                                
   
                                              
    
	private function sourceId(string $configKey): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, $configKey, ''));
	}                

	   
                                              
   
                                                    
   
                                                                 
    
	private function resolveSource(string $sourceId): object|array|null {
		                                                                          
		                                                                          
		                                                                        
		                                                                          
		                                                                       
		$registerSlug = $this->connectorRegister->slugOrNull(
			operation: 'ConnectorEgress.resolveSource',
			sourceId: $sourceId
		);
		if ($registerSlug === null) {
			return null;
		}

		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
			$source = $objectService->find(
				id: $sourceId,
				register: $registerSlug,
				schema: self::SOURCE_SCHEMA,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConnectorEgress.resolveSource: lookup failed',
				['sourceId' => $sourceId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($source) === false && is_array($source) === false) {
			return null;
		}

		return $source;
	}                     

	   
                                         
   
                                                                          
    
	private function resolveCallService(): ?object {
		                                                                
		                                                                   
		                                                                    
		                                                                     
		                                                                      
		                                                                      
		                                                              
		$callService = FleetAppId::getService(
			container: $this->container,
			canonical: 'integriq',
			relative: 'Service\CallService'
		);
		if ($callService === null) {
			$this->logger->info('ConnectorEgress.resolveCallService: unavailable');
			return null;
		}

		if (method_exists($callService, 'call') === false) {
			return null;
		}

		return $callService;
	}                          

	   
                                                  
   
                                            
   
                                
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}               
}           
