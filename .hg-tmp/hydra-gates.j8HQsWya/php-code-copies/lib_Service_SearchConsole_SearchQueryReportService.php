<?php

   
                                     
  
                                                                           
                                                                           
                                                                        
                                                         
  
                    
                                               
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\SearchConsole;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                 
  
                                                                                                                                                       
   
class SearchQueryReportService {

	   
                                              
   
            
    
	public const DEFAULT_WINDOW_DAYS = 28;

	   
                                         
   
            
    
	private const PAGE = 500;

	   
                                       
   
            
    
	private const MAX_ROWS = 20000;

	   
                
   
                                                                                  
                                                     
                                                                  
                                          
   
                                                                                                                                                        
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                            
   
                                                                                
                                                                                 
                                              
                                                                        
   
                                                                                                      
   
                                                                                                                                                        
    
	public function topQueries(?string $from = null, ?string $to = null, int $limit = 50, ?string $property = null): array {
		[$from, $to] = $this->window(from: $from, to: $to);
		$rows = $this->loadRows(from: $from, to: $to, property: $property);
		$aggregated = $this->aggregate(rows: $rows);

		return [
			'from' => $from,
			'to' => $to,
			'rows' => array_slice($aggregated, 0, max(1, min(500, $limit))),
			'totalQueries' => count($aggregated),
		];
	}                  

	   
                             
   
                                                                     
                                                                          
                                                                       
                                                           
                                                                        
                                                                 
                                      
   
                                                                                
                                                                                 
                                                                        
   
                                                                                   
   
                                                                                                                                                                                    
    
	public function rows(?string $from = null, ?string $to = null, ?string $property = null): array {
		[$from, $to] = $this->window(from: $from, to: $to);

		return ['from' => $from, 'to' => $to, 'rows' => $this->loadRows(from: $from, to: $to, property: $property)];
	}            

	   
                                                                       
                         
   
                                                                          
   
                                                                                                               
   
                                                                                                                                                        
    
	public function aggregate(array $rows): array {
		$byQuery = [];
		foreach ($rows as $row) {
			$query = trim((string)($row['query'] ?? ''));
			if ($query === '') {
				continue;
			}

			$impressions = max(0, (int)($row['impressions'] ?? 0));
			$entry = ($byQuery[$query] ?? ['query' => $query, 'clicks' => 0, 'impressions' => 0, 'weightedPosition' => 0.0, 'pages' => []]);
			$entry['clicks'] += max(0, (int)($row['clicks'] ?? 0));
			$entry['impressions'] += $impressions;
			$entry['weightedPosition'] += ((float)($row['position'] ?? 0.0) * $impressions);
			$page = (string)($row['page'] ?? '');
			if ($page !== '') {
				$entry['pages'][$page] = true;
			}

			$byQuery[$query] = $entry;
		}

		$out = [];
		foreach ($byQuery as $entry) {
			$impressions = (int)$entry['impressions'];
			$ctr = 0.0;
			$position = 0.0;
			if ($impressions > 0) {
				$ctr = round(($entry['clicks'] / $impressions), 4);
				$position = round(($entry['weightedPosition'] / $impressions), 1);
			}

			$out[] = [
				'query' => $entry['query'],
				'clicks' => (int)$entry['clicks'],
				'impressions' => $impressions,
				'ctr' => $ctr,
				'position' => $position,
				'pages' => count($entry['pages']),
			];
		}

		usort(
			$out,
			static function (array $a, array $b): int {
				return [$b['clicks'], $b['impressions'], $a['query']] <=> [$a['clicks'], $a['impressions'], $b['query']];
			}
		);

		return $out;
	}                 

	   
                                                                        
   
                                             
                                         
   
                                                 
    
	private function window(?string $from, ?string $to): array {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$end = ($this->parseDay(value: $to) ?? $today);
		$start = ($this->parseDay(value: $from) ?? $end->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days'));
		if ($start > $end) {
			$start = $end;
		}

		return [$start->format('Y-m-d'), $end->format('Y-m-d')];
	}              

	   
                                         
   
                                         
   
                                                        
    
	private function parseDay(?string $value): ?DateTimeImmutable {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable((trim($value) . 'T00:00:00'), new DateTimeZone('UTC'));
	}                

	   
                                                         
   
                                     
                                            
                                                          
   
                                                        
    
	private function loadRows(string $from, string $to, ?string $property): array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('SearchQueryReportService: OpenRegister unavailable', ['exception' => $e->getMessage()]);
			return [];
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'pipelinq');
		$schemaKey = (SearchConsoleImportService::SCHEMA . '_schema');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, SearchConsoleImportService::SCHEMA);
		$dayAfter = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
		$filters = ['register' => $register, 'schema' => $schema, 'date' => ['gte' => $from, 'lt' => $dayAfter]];
		if ($property !== null && trim($property) !== '') {
			$filters['property'] = trim($property);
		}

		return $this->pages(objectService: $objectService, filters: $filters, pageSize: self::PAGE, maxRows: self::MAX_ROWS);
	}                

	   
                                                                  
   
                                                                 
                                                                                   
                                       
                                                            
   
                                                        
    
	private function pages(object $objectService, array $filters, int $pageSize, int $maxRows): array {
		$rows = [];
		$offset = 0;
		while ($offset < $maxRows) {
			try {
				$page = $objectService->findAll(
					config: ['filters' => $filters, 'limit' => $pageSize, 'offset' => $offset],
					_rbac: false,
					_multitenancy: false
				);
			} catch (Throwable $e) {
				$this->logger->warning('SearchQueryReportService: findAll failed', ['exception' => $e->getMessage()]);
				break;
			}

			if (is_iterable($page) === false) {
				break;
			}

			$count = 0;
			foreach ($page as $row) {
				$rows[] = $this->toArray(value: $row);
				$count++;
			}

			if ($count < $pageSize) {
				break;
			}

			$offset += $pageSize;
		}

		return $rows;
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
