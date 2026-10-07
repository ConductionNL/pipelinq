<?php

   
                      
  
                                                                         
                                                                         
                                                                           
                                                                 
                                                                        
                                                 
  
                                                                
                                                                   
                                                                          
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                  
  
                                                         
   
class ZtcClient {
	public const RESOURCE_ZAAKTYPE = 'zaaktypen';
	public const RESOURCE_STATUSTYPE = 'statustypen';
	public const RESOURCE_ROLTYPE = 'roltypen';
	public const RESOURCE_RESULTAATTYPE = 'resultaattypen';
	public const RESOURCE_BESLUITTYPE = 'besluittypen';

	private const DEFAULT_TTL_S = 3600;

	   
          
                                               
                            
                           
                         
       
   
                                                                                                             
    
	private array $cache = [];

	   
                
   
                                            
                                                                        
                                                        
                                                
    
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                           
   
                                                              
                                                      
   
                                
   
                                                                     
                                                          
    
	public function resolveZaaktype(array $endpoint, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_ZAAKTYPE,
			omschrijving: $omschrijving
		);
		if ($hit === null) {
			throw new ZaaktypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}                       

	   
                                                               
   
                                                              
                                                   
                                                        
   
                                  
   
                                                                       
                                                                                  
    
	public function resolveStatustype(array $endpoint, string $caseTypeUrl, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_STATUSTYPE,
			omschrijving: $omschrijving,
			extraQuery: ['caseType' => $caseTypeUrl]
		);
		if ($hit === null) {
			throw new ZaaktypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}                         

	   
                                                            
   
                                                              
                                                   
                                                                        
   
                               
   
                                                                    
                                                                                  
    
	public function resolveRoltype(array $endpoint, string $caseTypeUrl, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_ROLTYPE,
			omschrijving: $omschrijving,
			extraQuery: ['caseType' => $caseTypeUrl]
		);
		if ($hit === null) {
			throw new ZaaktypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}                      

	   
                                              
   
                                                              
                                                         
   
                                   
   
                                                                           
                                                          
    
	public function resolveBesluittype(array $endpoint, string $omschrijving): string {
		$hit = $this->resolveByOmschrijving(
			endpoint: $endpoint,
			resourceType: self::RESOURCE_BESLUITTYPE,
			omschrijving: $omschrijving
		);
		if ($hit === null) {
			throw new BesluittypeNotInCatalogusException($omschrijving);
		}

		return $hit['url'];
	}                          

	   
                                                                            
   
                                                                         
                                                                         
                                                                       
   
                                                              
                                                                
   
                                                                     
                                                          
    
	public function resolveOmschrijvingFromUrl(array $endpoint, string $statustypeUrl): ?string {
		$endpointId = (string)($endpoint['id'] ?? '');
		$bucket = $this->cache[$endpointId][self::RESOURCE_STATUSTYPE] ?? [];
		foreach ($bucket as $entry) {
			if ($entry['url'] === $statustypeUrl) {
				$omsch = $entry['data']['omschrijving'] ?? null;
				if (is_string($omsch) === true) {
					return $omsch;
				}

				return null;
			}
		}

		                              
		$client = $this->registers->findClientForEndpoint($endpoint);
		$ztcUrl = (string)($endpoint['componenten']['ztc'] ?? '');
		if ($client === null || $ztcUrl === '' || str_starts_with($statustypeUrl, $ztcUrl) === false) {
			return null;
		}

		try {
			$response = $this->api->callComponent(
				componentUrl: $ztcUrl,
				method: 'GET',
				path: substr($statustypeUrl, strlen($ztcUrl)),
				client: $client
			);
		} catch (Throwable $e) {
			$this->logger->warning('ZGW ZTC: resolveOmschrijvingFromUrl failed', ['err' => $e->getMessage()]);
			return null;
		}

		$omsch = $response['body']['omschrijving'] ?? null;
		if (is_string($omsch) === true) {
			return $omsch;
		}

		return null;
	}                                  

	   
                                                                               
   
                                                              
                                                                   
   
                
                                                          
    
	public function invalidateCache(array $endpoint, string $resourceType = '*'): void {
		$endpointId = (string)($endpoint['id'] ?? '');
		if ($endpointId === '') {
			return;
		}

		if ($resourceType === '*' || $resourceType === '') {
			unset($this->cache[$endpointId]);
			return;
		}

		unset($this->cache[$endpointId][$resourceType]);
	}                       

	   
                                          
   
                                          
                                                  
                                             
                                    
                                                                       
   
                
                                                          
    
	public function primeCache(string $endpointId, string $resourceType, string $omschrijving, string $url, array $data = []): void {
		$cacheData = $data;
		if ($data === []) {
			$cacheData = ['omschrijving' => $omschrijving];
		}

		$this->cache[$endpointId][$resourceType][$omschrijving] = [
			'url' => $url,
			'data' => $cacheData,
			'storedAt' => time(),
		];
	}                  

	   
                                                      
   
                                                           
                                                  
                                                        
                                                                           
   
                                                           
    
	private function resolveByOmschrijving(
		array $endpoint,
		string $resourceType,
		string $omschrijving,
		array $extraQuery = [],
	): ?array {
		$endpointId = (string)($endpoint['id'] ?? '');
		$cacheKey = $omschrijving . '|' . json_encode($extraQuery, JSON_UNESCAPED_SLASHES);
		$bucket = $this->cache[$endpointId][$resourceType][$cacheKey] ?? null;
		if ($bucket !== null && (time() - $bucket['storedAt']) < $this->ttl()) {
			return ['url' => $bucket['url'], 'data' => $bucket['data']];
		}

		$resolved = $this->fetchByOmschrijving(
			endpoint: $endpoint,
			endpointId: $endpointId,
			resourceType: $resourceType,
			omschrijving: $omschrijving,
			extraQuery: $extraQuery
		);
		if ($resolved === null) {
			return null;
		}

		$this->cache[$endpointId][$resourceType][$cacheKey] = [
			'url' => $resolved['url'],
			'data' => $resolved['data'],
			'storedAt' => time(),
		];

		return $resolved;
	}                             

	   
                                                                          
   
                                                           
                                                                
                                                  
                                                        
                                                                           
   
                                                           
    
	private function fetchByOmschrijving(
		array $endpoint,
		string $endpointId,
		string $resourceType,
		string $omschrijving,
		array $extraQuery,
	): ?array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		$ztcUrl = (string)($endpoint['componenten']['ztc'] ?? '');
		if ($client === null || $ztcUrl === '') {
			return null;
		}

		$query = array_merge(['omschrijving' => $omschrijving, 'status' => 'definitief'], $extraQuery);

		try {
			$response = $this->api->callComponent(
				componentUrl: $ztcUrl,
				method: 'GET',
				path: '/' . $resourceType,
				client: $client,
				query: $query
			);
		} catch (ZgwResourceNotFoundException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'ZGW ZTC: lookup failed',
				['endpoint' => $endpointId, 'resource' => $resourceType, 'om' => $omschrijving, 'err' => $e->getMessage()]
			);
			return null;
		}

		$results = $response['body']['results'] ?? $response['body'];
		if (is_array($results) === false || $results === []) {
			return null;
		}

		                                       
		$entry = $results[0] ?? null;
		if (is_array($entry) === false) {
			return null;
		}

		$url = (string)($entry['url'] ?? '');
		if ($url === '') {
			return null;
		}

		return ['url' => $url, 'data' => $entry];
	}                           

	   
                                  
   
               
    
	private function ttl(): int {
		$value = $this->appConfig->getValueInt(Application::APP_ID, 'zgw.ztc_cache_ttl', self::DEFAULT_TTL_S);
		if ($value > 0) {
			return $value;
		}

		return self::DEFAULT_TTL_S;
	}           
}           
