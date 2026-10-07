<?php

   
                                    
  
                                                           
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                     
  
                                                                                                   
   
class OpenCorporatesApiClient {
	   
                                                          
   
                                               
                                                                            
                                                                          
                                                    
   
               
    
	private const DEFAULT_API_BASE = 'https://api.opencorporates.com/v0.4';

	   
                
   
                                                                 
                                                
                                              
                                                                      
                                                                                     
    
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private OpenCorporatesResultMapper $resultMapper,
		private IURLGenerator $urlGenerator,
	) {
	}                   

	   
                                                         
   
                                                
   
                                                       
    
	private function getApiBase(): string {
		return rtrim(
			$this->appConfig->getValueString(
				Application::APP_ID,
				'opencorporates.api_base_url',
				self::DEFAULT_API_BASE
			),
			'/'
		);
	}                  

	   
                                              
   
                                               
   
                                     
   
                                                                                          
    
	public function search(array $criteria): array {
		$keywords = $criteria['keywords'] ?? [];

		if (count($keywords) === 0) {
			return [];
		}

		$results = [];
		foreach ($keywords as $keyword) {
			$this->searchByKeyword(keyword: $keyword, results: $results);
		}

		return array_values(array: $results);
	}              

	   
                                                  
   
                                                 
                                                                       
   
                
    
	private function searchByKeyword(string $keyword, array &$results): void {
		try {
			$body = $this->fetchCompanies(keyword: $keyword);
			$companies = $body['results']['companies'] ?? [];

			foreach ($companies as $entry) {
				$company = $entry['company'] ?? [];
				$mapped = $this->resultMapper->mapResult(company: $company);
				if ($mapped !== null) {
					$results[$mapped['kvkNumber']] = $mapped;
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'OpenCorporates search failed for keyword {kw}',
				context: ['kw' => $keyword, 'error' => $e->getMessage()]
			);
		}         
	}                       

	   
                                                
   
                                              
   
                                            
    
	private function fetchCompanies(string $keyword): array {
		                                                                      
		                                                                     
		                                                                    
		                                                                     
		                                                                        
		$viaOr = $this->fetchViaOpenRegister(keyword: $keyword);
		if ($viaOr !== null) {
			return ['results' => ['companies' => $viaOr]];
		}

		$queryParams = [
			'q' => $keyword,
			'jurisdiction_code' => 'nl',
			'per_page' => '30',
			'order' => 'score',
		];

		$url = $this->getApiBase() . '/companies/search?' . http_build_query(data: $queryParams);

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

	   
                                                                                  
   
                                                                         
                                                                            
                                                                    
                                                                            
                                                 
                                                                             
                                                                       
                                                                            
                                                                       
                                   
   
                                              
   
                                                                                           
   
                                                                                                       
    
	private function fetchViaOpenRegister(string $keyword): ?array {
		$params = http_build_query(
			[
				'q' => $keyword,
				'jurisdiction' => 'nl',
				'limit' => 30,
				'page' => 1,
			]
		);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/opencorporates/search?' . $params);

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
			                                                                     
			                                                                       
			$this->logger->debug('OpenCorporates OR search unavailable, falling back to direct path', ['exception' => $e]);
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
				$rows[] = ['company' => $row];
			}
		}

		return $rows;
	}                            
}           
