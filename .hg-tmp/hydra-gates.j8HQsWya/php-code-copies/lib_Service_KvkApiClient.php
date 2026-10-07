<?php

   
                         
  
                                                              
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                 
  
                                                                                  
   
class KvkApiClient {
	   
                                               
   
                                                                           
                                                                           
                                                                       
                                                                           
   
               
    
	private const DEFAULT_API_BASE = 'https://api.kvk.nl/api/v1';

	   
                
   
                                                                 
                                                
                                              
                                                           
                                                                                     
    
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private KvkResultMapper $resultMapper,
		private IURLGenerator $urlGenerator,
	) {
	}                   

	   
                                                                                  
   
                                                       
    
	private function getApiBase(): string {
		return rtrim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				'kvk.api_base_url',
				self::DEFAULT_API_BASE
			),
			'/'
		);
	}                  

	   
                                                                 
   
                                          
                                               
   
                                     
   
                                                                                   
    
	public function search(string $apiKey, array $criteria): array {
		if ($apiKey === '') {
			return [];
		}

		$results = [];
		$sbiCodes = $criteria['sbiCodes'] ?? [];

		foreach ($sbiCodes as $sbiCode) {
			$this->searchBySbiCode(apiKey: $apiKey, sbiCode: $sbiCode, results: $results);
		}

		return array_values(array: $results);
	}              

	   
                                                   
   
                                          
                                                  
                                                                       
   
                
    
	private function searchBySbiCode(string $apiKey, string $sbiCode, array &$results): void {
		try {
			$body = $this->fetchResults(apiKey: $apiKey, sbiCode: $sbiCode);

			if (isset($body['resultaten']) === false) {
				return;
			}

			foreach ($body['resultaten'] as $item) {
				$mapped = $this->resultMapper->mapResult(item: $item, sbiCode: $sbiCode);
				if ($mapped !== null) {
					$results[$mapped['kvkNumber']] = $mapped;
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'KVK API search failed for SBI code {sbi}',
				context: ['sbi' => $sbiCode, 'error' => $e->getMessage()]
			);
		}         
	}                       

	   
                                                        
   
                                          
                                                     
   
                                            
    
	private function fetchResults(string $apiKey, string $sbiCode): array {
		                                                                       
		                                                                     
		                                                                      
		                                                                      
		                                                                 
		$viaOr = $this->fetchViaOpenRegister(sbiCode: $sbiCode);
		if ($viaOr !== null) {
			return ['resultaten' => $viaOr];
		}

		$queryParams = [
			'apikey' => $apiKey,
			'type' => 'hoofdvestiging',
			'pagina' => '1',
			'count' => '50',
			'sbiHoofdActiviteit' => $sbiCode,
		];

		$url = $this->getApiBase() . '/zoeken?' . http_build_query(data: $queryParams);

		$client = $this->clientService->newClient();
		$response = $client->get(
			uri: $url,
			options: [
				'headers' => ['Accept' => 'application/json'],
				'timeout' => 15,
			]
		);

		$decoded = json_decode(json: $response->getBody(), associative: true);

		if (is_array(value: $decoded) === true) {
			return $decoded;
		}

		return [];
	}                    

	   
                                                                              
   
                                                                          
                                                                            
                                                                          
                                                                             
                                                                           
                                                                   
                                                            
                                                                          
                                                                         
                                            
   
                                                     
   
                                                                                   
   
                                                                                                       
    
	private function fetchViaOpenRegister(string $sbiCode): ?array {
		$params = http_build_query(
			[
				'sbiHoofdActiviteit' => $sbiCode,
				'type' => 'hoofdvestiging',
				'limit' => 50,
				'page' => 1,
			]
		);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/kvk/search?' . $params);

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				$url,
				[
					'timeout' => 15,
					'connect_timeout' => 3,
					'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $e) {
			                                                                     
			                                                                       
			$this->logger->debug('KVK OR search unavailable, falling back to direct path', ['exception' => $e]);
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (is_array($decoded) === false || isset($decoded['results']) === false || is_array($decoded['results']) === false) {
			return null;
		}

		$rows = [];
		foreach ($decoded['results'] as $row) {
			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}                            
}           
