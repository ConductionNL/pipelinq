<?php

   
                                       
  
                                                                          
                                                                        
                                                                       
                                                                            
                                                                           
                                                                         
                                    
  
                                                                         
                                                                      
                                                                        
                                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                            
  
                                                                           
                                                                     
                                                                        
                                                       
  
                                                                                                                                                                     
   
class CampaignPerformanceService {

	   
                                                                     
   
               
    
	public const PORTALIQ_REGISTER = 'portaliq';

	   
                           
   
               
    
	public const DAILY_SCHEMA = 'portalTrafficDaily';

	   
                                                           
   
               
    
	public const PORTALIQ_PROBE_CLASS = 'OCA\\Portaliq\\AppInfo\\Application';

	   
                                   
   
            
    
	private const MAX_WINDOW_DAYS = 366;

	   
                                                                  
   
            
    
	private const DEFAULT_WINDOW_DAYS = 30;

	   
                                                
   
            
    
	private const PAGE = 200;

	   
                
   
                                                                                              
                                                     
                                                                
                                                      
                                                                        
                                                                   
                                          
   
                                                                                                                                                                      
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private BlastService $blastService,
		private AttributionService $attributionService,
		private CampaignLinkDecorator $decorator,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                               
   
                                              
                                                                                          
                                                                                 
   
                                                                                                    
   
                                                                                                                                                                      
    
	public function forBlast(string $blastId, ?string $from = null, ?string $to = null): ?array {
		$blast = $this->blastService->getBlastById(blastId: $blastId);
		if ($blast === null) {
			return null;
		}

		$window = $this->window(blast: $blast, from: $from, to: $to);
		$campaign = $this->decorator->campaignFor(blast: $blast);
		$totals = (array)($blast['totals'] ?? []);
		$deals = $this->attributionService->getBlastAttributionSummary(blastId: $blastId);

		$record = [
			'blastId' => $blastId,
			'campaign' => $campaign,
			'portal' => '',
			'connected' => false,
			'reason' => '',
			'window' => $window,
			'email' => [
				'sent' => (int)($totals['sent'] ?? 0),
				'delivered' => (int)($totals['delivered'] ?? 0),
				'opened' => (int)($totals['opened'] ?? 0),
				'clicked' => (int)($totals['clicked'] ?? 0),
			],
			'deals' => [
				'dealCount' => (int)($deals['dealCount'] ?? 0),
				'attributedValue' => (float)($deals['attributedValue'] ?? 0.0),
				'currency' => (string)($deals['currency'] ?? 'EUR'),
			],
			'site' => null,
		];

		$portal = trim($this->appConfig->getValueString(Application::APP_ID, TrafficEventEmitter::PORTAL_CONFIG_KEY, ''));
		if ($portal === '') {
			$record['reason'] = 'no_portal';
			return $record;
		}

		$record['portal'] = $portal;
		if ($this->isPortaliqInstalled() === false) {
			$record['reason'] = 'portaliq_missing';
			return $record;
		}

		$rows = $this->loadRollups(portal: $portal, from: $window['from'], to: $window['to']);
		$record['connected'] = true;
		$record['site'] = $this->sumCampaignRows(rows: $rows, campaign: $campaign, blastName: (string)($blast['name'] ?? ''));

		return $record;
	}                

	   
                                                                         
   
                
    
	protected function isPortaliqInstalled(): bool {
		return class_exists(self::PORTALIQ_PROBE_CLASS);
	}                           

	   
                                                                      
                                                                            
   
                                                     
                                             
                                         
   
                                                              
    
	private function window(array $blast, ?string $from, ?string $to): array {
		$today = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new DateTimeZone('UTC'));
		$end = ($this->parseDay(value: $to) ?? $today);

		$start = $this->parseDay(value: $from);
		if ($start === null) {
			foreach (['sentAt', 'scheduledFor', 'createdAt'] as $key) {
				$start = $this->parseDay(value: (string)($blast[$key] ?? ''));
				if ($start !== null) {
					break;
				}
			}
		}

		if ($start === null) {
			$start = $end->modify('-' . self::DEFAULT_WINDOW_DAYS . ' days');
		}

		if ($start > $end) {
			$start = $end;
		}

		$span = (int)$start->diff($end)->format('%a');
		if ($span > self::MAX_WINDOW_DAYS) {
			$start = $end->modify('-' . self::MAX_WINDOW_DAYS . ' days');
		}

		return ['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d')];
	}              

	   
                                                                
   
                                         
   
                                                             
    
	private function parseDay(?string $value): ?DateTimeImmutable {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable(($matches[1] . '-' . $matches[2] . '-' . $matches[3] . 'T00:00:00'), new DateTimeZone('UTC'));
	}                

	   
                                                                 
   
                                          
                                                
                                            
   
                                                             
    
	private function loadRollups(string $portal, string $from, string $to): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$dayAfter = (new DateTimeImmutable($to, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
		$filters = [
			'register' => self::PORTALIQ_REGISTER,
			'schema' => self::DAILY_SCHEMA,
			'portal' => $portal,
			'date' => ['gte' => $from, 'lt' => $dayAfter],
		];

		return $this->pages(objectService: $objectService, filters: $filters, pageSize: self::PAGE, maxRows: self::MAX_WINDOW_DAYS);
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
				$this->logger->warning(
					'CampaignPerformanceService: reading portalTrafficDaily failed',
					['portal' => (string)($filters['portal'] ?? ''), 'exception' => $e->getMessage()]
				);
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

	   
                                                           
   
                                                                      
                                                                     
                                                          
   
                                                              
                                                 
                                                               
   
                                                                                 
    
	private function sumCampaignRows(array $rows, string $campaign, string $blastName): array {
		$wanted = array_filter(array_unique([strtolower($campaign), strtolower(trim($blastName))]), static fn (string $v): bool => $v !== '');
		$sessions = 0;
		$pageViews = null;
		$formSubmits = null;
		$days = [];
		$sources = [];
		foreach ($rows as $row) {
			$campaigns = ($row['campaigns'] ?? []);
			if (is_array($campaigns) === false) {
				continue;
			}

			foreach ($campaigns as $entry) {
				if (is_array($entry) === false || in_array(strtolower((string)($entry['campaign'] ?? '')), $wanted, true) === false) {
					continue;
				}

				$sessions += (int)($entry['sessions'] ?? 0);
				$days[(string)($row['date'] ?? '')] = true;
				$key = ((string)($entry['source'] ?? '') . '/' . (string)($entry['medium'] ?? ''));
				$sources[$key] = ($sources[$key] ?? 0) + (int)($entry['sessions'] ?? 0);
				if (isset($entry['pageViews']) === true) {
					$pageViews = (($pageViews ?? 0) + (int)$entry['pageViews']);
				}

				if (isset($entry['formSubmits']) === true) {
					$formSubmits = (($formSubmits ?? 0) + (int)$entry['formSubmits']);
				}
			}
		}

		arsort($sources);
		$sourceRows = [];
		foreach ($sources as $key => $count) {
			[$source, $medium] = explode('/', (string)$key, 2);
			$sourceRows[] = ['source' => $source, 'medium' => $medium, 'sessions' => $count];
		}

		return [
			'sessions' => $sessions,
			'pageViews' => $pageViews,
			'formSubmits' => $formSubmits,
			'days' => count($days),
			'sources' => $sourceRows,
		];
	}                       

	   
                                                  
   
                                                                     
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'CampaignPerformanceService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
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

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               
}           
