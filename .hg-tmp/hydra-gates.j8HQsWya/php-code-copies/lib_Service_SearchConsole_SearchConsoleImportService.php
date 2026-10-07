<?php

   
                                       
  
                                                                      
                                                                 
                                                                          
                                                                      
                                                                        
                                                                     
  
                                                                         
                                                                      
                                             
  
                    
                                               
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

   
                                                     
  
                                                                                                                                                                            
   
class SearchConsoleImportService {

	   
                                                                      
                       
   
               
    
	public const PROPERTIES_KEY = 'search.gsc.properties';

	   
                                                                    
   
               
    
	public const KEY_KEY = 'search.gsc.service_account_key';

	   
                                                                  
   
               
    
	public const LAST_IMPORT_KEY = 'search.gsc.last_import_at';

	   
                                       
   
               
    
	public const SCHEMA = SearchQueryDailyStore::SCHEMA;

	   
                                   
   
               
    
	public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	   
                                                                    
   
               
    
	public const API_URL = 'https://www.googleapis.com/webmasters/v3/sites/{site}/searchAnalytics/query';

	   
                                                                     
   
            
    
	private const ROW_LIMIT = 5000;

	   
                                                                    
   
            
    
	private const MAX_ROWS = 25000;

	   
                
   
                                                                       
                                                     
                                                          
                                                        
                                                                        
                                          
   
                                                                                                                                                                             
    
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private SearchQueryDailyStore $store,
		private GoogleServiceAccountAuth $auth,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                        
   
                                                                                                   
   
                                                                                                                                                                             
    
	public function properties(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::PROPERTIES_KEY, '');
		$parts = preg_split('/[\r\n,]+/', $raw);
		if ($parts === false) {
			return [];
		}

		$out = [];
		foreach ($parts as $part) {
			$trimmed = trim($part);
			if ($trimmed !== '') {
				$out[$trimmed] = true;
			}
		}

		return array_keys($out);
	}                  

	   
                                                    
   
                
   
                                                                                                                                                                             
    
	public function hasKey(): bool {
		return $this->key() !== null;
	}              

	   
                                                                 
   
                                                  
   
                                                                                                                                                                             
    
	public function serviceAccountEmail(): string {
		$key = $this->key();
		if ($key === null) {
			return '';
		}

		return $key['client_email'];
	}                           

	   
                                                      
   
                  
   
                                                                                                                                                                             
    
	public function lastImportAt(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::LAST_IMPORT_KEY, '');
	}                    

	   
                                                               
   
                                                                       
   
                                                                                                              
   
                                                                                                                                                                             
    
	public function importRecent(int $days = 3): array {
		$result = ['properties' => 0, 'rows' => 0, 'errors' => []];
		$key = $this->key();
		$properties = $this->properties();
		if ($key === null || $properties === []) {
			$this->logger->debug('SearchConsoleImportService: no key or no properties configured, skipping');
			return $result;
		}

		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$from = $today->modify('-' . max(0, $days) . ' days')->format('Y-m-d');
		$to = $today->format('Y-m-d');

		try {
			$token = $this->auth->accessToken(key: $key, scope: self::SCOPE);
		} catch (Throwable $e) {
			$result['errors']['*'] = $e->getMessage();
			return $result;
		}

		foreach ($properties as $property) {
			try {
				$result['rows'] += $this->importProperty(property: $property, from: $from, to: $to, token: $token);
				$result['properties']++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'SearchConsoleImportService: property import failed',
					['property' => $property, 'exception' => $e->getMessage()]
				);
				$result['errors'][$property] = $e->getMessage();
			}
		}

		if ($result['properties'] > 0) {
			$this->appConfig->setValueString(Application::APP_ID, self::LAST_IMPORT_KEY, $today->format('Y-m-d\TH:i:s\Z'));
		}

		return $result;
	}                    

	   
                                      
   
                                                                  
                                                  
                                                         
                                                                
   
                              
   
                                           
   
                                                                                                                                                                             
    
	public function importProperty(string $property, string $from, string $to, string $token): int {
		$rows = $this->fetchRows(token: $token, property: $property, from: $from, to: $to);
		$importedAt = gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime());
		$saved = 0;
		foreach ($rows as $row) {
			if ($this->store->upsert(property: $property, row: $row, importedAt: $importedAt) === true) {
				$saved++;
			}
		}

		$this->logger->info(
			'SearchConsoleImportService: property imported',
			['property' => $property, 'from' => $from, 'to' => $to, 'rows' => $saved]
		);

		return $saved;
	}                      

	   
                                                                             
   
                                      
                                         
                                     
                                 
   
                                                                                                                    
   
                                                                 
    
	private function fetchRows(string $token, string $property, string $from, string $to): array {
		$url = str_replace('{site}', rawurlencode($property), self::API_URL);
		$client = $this->clientService->newClient();
		$rows = [];
		$startRow = 0;
		while ($startRow < self::MAX_ROWS) {
			$response = $client->post(
				$url,
				[
					'headers' => ['Authorization' => ('Bearer ' . $token), 'Accept' => 'application/json'],
					'json' => [
						'startDate' => $from,
						'endDate' => $to,
						'dimensions' => ['date', 'query', 'page'],
						'rowLimit' => self::ROW_LIMIT,
						'startRow' => $startRow,
						'dataState' => 'all',
					],
					'timeout' => 60,
				]
			);

			$decoded = json_decode((string)$response->getBody(), true);
			if (is_array($decoded) === false) {
				throw new RuntimeException('Search Console: the API answered with something that is not JSON');
			}

			if (isset($decoded['error']) === true) {
				throw new RuntimeException('Search Console: ' . $this->errorMessage(error: $decoded['error']));
			}

			$page = ($decoded['rows'] ?? []);
			if (is_array($page) === false) {
				$page = [];
			}

			foreach ($page as $raw) {
				$row = $this->normaliseRow(raw: $raw);
				if ($row !== null) {
					$rows[] = $row;
				}
			}

			if (count($page) < self::ROW_LIMIT) {
				break;
			}

			$startRow += self::ROW_LIMIT;
		}

		return $rows;
	}                 

	   
                                                                        
   
                                                             
   
                  
    
	private function errorMessage(mixed $error): string {
		if (is_array($error) === true) {
			return (string)($error['message'] ?? 'error');
		}

		return (string)$error;
	}                    

	   
                                                                      
   
                                  
   
                                                                                    
    
	private function normaliseRow(mixed $raw): ?array {
		if (is_array($raw) === false || is_array($raw['keys'] ?? null) === false || count($raw['keys']) < 3) {
			return null;
		}

		[$date, $query, $page] = $raw['keys'];
		$date = (string)$date;
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
			return null;
		}

		return [
			'date' => $date,
			'query' => (string)$query,
			'page' => (string)$page,
			'clicks' => (int)($raw['clicks'] ?? 0),
			'impressions' => (int)($raw['impressions'] ?? 0),
			'ctr' => round((float)($raw['ctr'] ?? 0.0), 4),
			'position' => round((float)($raw['position'] ?? 0.0), 2),
		];
	}                    

	   
                                    
   
                                      
    
	private function key(): ?array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY_KEY, '');
		if ($raw === '') {
			return null;
		}

		return $this->auth->parseKey(json: $raw);
	}           

}           
