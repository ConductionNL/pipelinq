<?php

   
                     
  
                                                                     
                                                                            
                                                                         
                                                                           
                                                                    
                                                    
  
                                                                          
                                                                        
                                                                       
                                                                         
                                                                          
                                                                         
                                                        
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                      
  
                                                         
   
class AcClient {
	   
                                                   
    
	private const DEFAULT_REFRESH_S = 900;

	   
                           
   
                            
                             
                          
                                                   
          
       
   
                                                                                        
    
	private array $cache = [];

	   
                
   
                                                 
                                                             
                                                               
                                                
    
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                      
   
                                                            
                                                                      
                                                             
   
                                                              
                                                          
   
                
   
                                                                                  
    
	public function refreshScopes(array $endpoint, array $client): void {
		$endpointId = (string)($endpoint['id'] ?? ($endpoint['@self']['slug'] ?? ''));
		$acUrl = (string)($endpoint['componenten']['ac'] ?? '');
		if ($endpointId === '' || $acUrl === '') {
			return;
		}

		try {
			$response = $this->api->callComponent(
				componentUrl: $acUrl,
				method: 'GET',
				path: '/autorisaties',
				client: $client
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ZGW AC: refreshScopes failed',
				['endpoint' => $endpointId, 'err' => $e->getMessage()]
			);
			return;
		}

		$results = $response['body']['results'] ?? $response['body'];
		if (is_array($results) === false) {
			return;
		}

		$scopes = $this->buildScopeMap(
			results: $results,
			clientIdentifier: (string)($client['clientIdentifier'] ?? '')
		);

		$this->cache[$endpointId] = ['refreshedAt' => time(), 'scopes' => $scopes];
	}                     

	   
                                                                            
   
                                                                          
                                                                           
   
                                             
    
	private function buildScopeMap(array $results, string $clientIdentifier): array {
		$scopes = [];

		foreach ($results as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			if ($this->entryMatchesClient(entry: $entry, clientIdentifier: $clientIdentifier) === false) {
				continue;
			}

			$autorisaties = $entry['autorisaties'] ?? [$entry];
			if (is_array($autorisaties) === false) {
				continue;
			}

			$this->collectScopes(autorisaties: $autorisaties, scopes: $scopes);
		}             

		                
		foreach ($scopes as $resource => $list) {
			$scopes[$resource] = array_values(array_unique($list));
		}

		return $scopes;
	}                     

	   
                                                                             
   
                                                                  
                                                               
   
                                                         
    
	private function entryMatchesClient(array $entry, string $clientIdentifier): bool {
		$clientIds = $entry['clientIds'] ?? [];
		if (is_array($clientIds) === false || $clientIdentifier === '') {
			return true;
		}

		if (in_array($clientIdentifier, $clientIds, true) === true) {
			return true;
		}

		$entryClient = (string)($entry['component'] ?? $entry['clientIds'][0] ?? '');
		return $entryClient === $clientIdentifier;
	}                          

	   
                                                                       
   
                                                                       
                                                                                           
   
                
    
	private function collectScopes(array $autorisaties, array &$scopes): void {
		foreach ($autorisaties as $auth) {
			if (is_array($auth) === false) {
				continue;
			}

			$resource = (string)($auth['caseType'] ?? $auth['besluittype'] ?? $auth['informatieobjecttype'] ?? '*');
			$list = $auth['scopes'] ?? [];
			if (is_array($list) === false) {
				continue;
			}

			foreach ($list as $scope) {
				if (is_string($scope) === false || $scope === '') {
					continue;
				}

				$scopes[$resource] = $scopes[$resource] ?? [];
				$scopes[$resource][] = $scope;
			}
		}             
	}                     

	   
                                                                           
   
                                                                       
                                                                    
                                               
   
                                                              
                                                                                    
                                                            
   
                                                
                                                          
    
	public function hasScope(array $endpoint, string $resourceUrl, string $scope): bool {
		$endpointId = (string)($endpoint['id'] ?? ($endpoint['@self']['slug'] ?? ''));
		if ($endpointId === '' || $scope === '') {
			return false;
		}

		                               
		$bucket = $this->cache[$endpointId] ?? null;
		if ($bucket === null || (time() - $bucket['refreshedAt']) > $this->refreshInterval()) {
			$client = $this->registers->findClientForEndpoint($endpoint);
			if ($client !== null) {
				$this->refreshScopes(endpoint: $endpoint, client: $client);
			}

			$bucket = $this->cache[$endpointId] ?? null;
		}

		if ($bucket === null) {
			return false;
		}

		                                                                
		if ((time() - $bucket['refreshedAt']) > (2 * $this->refreshInterval())) {
			return false;
		}

		$list = $bucket['scopes'][$resourceUrl] ?? $bucket['scopes']['*'] ?? [];
		return in_array($scope, $list, true);
	}                

	   
                                                         
   
                                                              
                                                   
   
                              
                                                          
    
	public function getScopesFor(array $endpoint, string $resourceUrl): array {
		$endpointId = (string)($endpoint['id'] ?? ($endpoint['@self']['slug'] ?? ''));
		if ($endpointId === '') {
			return [];
		}

		$bucket = $this->cache[$endpointId] ?? null;
		if ($bucket === null) {
			return [];
		}

		return array_values(
			array_unique(
				array_merge(
					$bucket['scopes'][$resourceUrl] ?? [],
					$bucket['scopes']['*'] ?? []
				)
			)
		);
	}                    

	   
                                                    
   
                                                              
                                                   
                                        
   
                
   
                                                                     
                                                                                  
    
	public function require(array $endpoint, string $resourceUrl, string $scope): void {
		if ($this->hasScope(endpoint: $endpoint, resourceUrl: $resourceUrl, scope: $scope) === true) {
			return;
		}

		throw new InsufficientScopeException(
			scope: $scope,
			caseTypeUrl: $resourceUrl,
			additionalInfo: 'Vraag de gemeente-beheerder om de juiste autorisatie te verlenen op het AC.'
		);
	}               

	   
                                                    
   
                                          
                                                               
                                                                
                                                             
   
                
                                                          
    
	public function primeCache(string $endpointId, array $scopes, ?int $refreshedAt = null): void {
		$this->cache[$endpointId] = ['refreshedAt' => $refreshedAt ?? time(), 'scopes' => $scopes];
	}                  

	   
                                         
   
               
    
	private function refreshInterval(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, 'zgw.ac_refresh_interval', self::DEFAULT_REFRESH_S);
		if ($value > 0) {
			return $value;
		}

		return self::DEFAULT_REFRESH_S;
	}                       
}           
