<?php

   
                                   
  
                                                                           
                                 
  
                                                                              
                                                                               
                                                                            
                                                                             
                                      
  
                                                                            
                                                                              
                                                                             
                                                             
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                            
  
                                                                                                                                                                                       
  
                                                                    
                                                                      
                                                                        
                                                                          
                                                                     
                                                      
                                                                    
                                                                          
                                                                      
                                                                          
                                                               
                                                                
   
class CompetitorWatchService {

	   
                                                                         
            
   
               
    
	public const SOURCE_KEY = 'competitor.egress_source';

	   
                                      
   
               
    
	public const WATCH_SCHEMA = 'competitorWatch';

	   
                                 
   
               
    
	public const COMPETITOR_SCHEMA = 'competitor';

	   
                                        
   
                           
    
	public const CADENCE_SECONDS = [
		'hourly' => 3600,
		'daily' => 86400,
		'weekly' => 604800,
	];

	   
                                     
   
            
    
	public const DEFAULT_LIMIT = 25;

	   
                
   
                                                                
                                                          
                                                           
                                                           
                                                                           
                                                                    
                                                         
                                                               
                                                                   
                                          
   
                                                                                                                                                                                        
    
	public function __construct(
		private ListObjectStore $store,
		private FeedWatchReader $feeds,
		private SitemapWatchReader $sitemaps,
		private PageWatchReader $pages,
		private FediverseWatchReader $fediverse,
		private SearchWatchReader $searches,
		private RelevanceScorer $scorer,
		private WatchEventStore $events,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                
   
                                                            
   
                                                         
   
                                                                                                                                                                                 
    
	public function watches(array $filters = []): array {
		return $this->store->findAll(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitorWatch_schema', default: self::WATCH_SCHEMA),
			filters: $filters
		);
	}               

	   
                     
   
                                                             
   
                                                                                                                                                                                 
    
	public function competitors(): array {
		return $this->store->findAll(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitor_schema', default: self::COMPETITOR_SCHEMA)
		);
	}                   

	   
                    
   
                                   
   
                                                         
   
                                                                                                                                                                                 
    
	public function watch(string $id): ?array {
		return $this->store->find(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitorWatch_schema', default: self::WATCH_SCHEMA),
			id: trim($id)
		);
	}             

	   
                                                   
   
                                                                       
                                                                          
                                                                  
   
                                                 
                                                          
   
                                                        
   
                                                                                                                                                                                        
    
	public function isDue(array $watch, int $now): bool {
		if (($watch['active'] ?? true) === false) {
			return false;
		}

		$lastRun = trim((string)($watch['lastRunAt'] ?? ''));
		if ($lastRun === '') {
			return true;
		}

		$stamp = strtotime($lastRun);
		if ($stamp === false) {
			return true;
		}

		$cadence = (self::CADENCE_SECONDS[(string)($watch['schedule'] ?? 'daily')] ?? self::CADENCE_SECONDS['daily']);

		return (($now - $stamp) >= $cadence);
	}             

	   
                                       
   
                                       
   
                                                             
   
                                                                                                                                                                                        
    
	public function dueNow(int $limit = self::DEFAULT_LIMIT): array {
		$now = $this->time->getTime();
		$out = [];
		foreach ($this->watches() as $watch) {
			if ($this->isDue(watch: $watch, now: $now) === false) {
				continue;
			}

			$out[] = $watch;
			if (count($out) >= $limit) {
				break;
			}
		}

		return $out;
	}              

	   
                                               
   
                                               
                                                                  
   
                                                                             
   
                                                                                                                                                                                        
    
	public function runDue(int $limit = self::DEFAULT_LIMIT, ?string $actingUserId = null): array {
		$summary = ['watches' => 0, 'events' => 0, 'failures' => []];
		foreach ($this->dueNow(limit: $limit) as $watch) {
			$id = $this->store->idOf(payload: $watch);
			try {
				$result = $this->runOne(watch: $watch, actingUserId: $actingUserId);
			} catch (Throwable $e) {
				$this->logger->warning(
					'CompetitorWatchService: a watch threw',
					['watchId' => $id, 'exception' => $e->getMessage()]
				);
				$summary['failures'][$id] = $e->getMessage();
				continue;
			}

			$summary['watches']++;
			$summary['events'] += $result['events'];
			if ($result['outcome'] !== WatchOutcome::OK) {
				$summary['failures'][$id] = $result['reason'];
			}
		}

		return $summary;
	}              

	   
                                         
   
                                                 
                                                                  
   
                                                               
   
                                                                                                                                                               
    
	public function runOne(array $watch, ?string $actingUserId = null): array {
		$outcome = $this->readOne(watch: $watch, actingUserId: $actingUserId);
		$created = 0;
		foreach ($outcome->items as $item) {
			$stored = $this->events->upsert(
				watch: $watch,
				item: $item,
				extra: $this->scorer->fieldsFor(item: $item, actingUserId: $actingUserId)
			);
			if ($stored['created'] === true) {
				$created++;
			}
		}

		$this->recordRun(watch: $watch, outcome: $outcome);

		return ['outcome' => $outcome->outcome, 'reason' => $outcome->reason, 'events' => $created];
	}              

	   
                                     
   
                                                 
                                                                  
   
                        
    
	private function readOne(array $watch, ?string $actingUserId): WatchOutcome {
		$target = trim((string)($watch['target'] ?? ''));
		$sourceId = trim((string)($watch['connectorSourceId'] ?? ''));

		return match ((string)($watch['kind'] ?? '')) {
			'rss' => $this->feeds->read(url: $target, sourceId: $sourceId),
			'sitemap' => $this->sitemaps->read(
				url: $target,
				previous: $this->seenLocations(watch: $watch),
				sourceId: $sourceId
			),
			'page' => $this->pages->read(
				url: $target,
				selector: trim((string)($watch['selector'] ?? '')),
				fingerprint: trim((string)($watch['fingerprint'] ?? '')),
				lineFingerprints: array_map('strval', (array)($watch['lineFingerprints'] ?? [])),
				sourceId: $sourceId
			),
			'fediverse' => $this->fediverse->read(handle: $target, sourceId: $sourceId),
			'search' => $this->searches->read(query: $target, actingUserId: $actingUserId),
			default => WatchOutcome::failed(
				outcome: EgressResult::NOT_CONFIGURED,
				reason: 'This watch has no kind that can be read.'
			),
		};
	}               

	   
                                                                           
                                                                         
                                                                    
   
                                                 
   
                                                    
    
	private function seenLocations(array $watch): array {
		$watchId = $this->store->idOf(payload: $watch);
		if ($watchId === '') {
			return [];
		}

		$out = [];
		foreach ($this->events->all(filters: ['watchId' => $watchId]) as $event) {
			$url = trim((string)($event['url'] ?? ''));
			if ($url !== '') {
				$out[$url] = trim((string)($event['sourceStamp'] ?? ''));
			}
		}

		return $out;
	}                     

	   
                                                                          
                                
   
                                                 
                                               
   
                
    
	private function recordRun(array $watch, WatchOutcome $outcome): void {
		$id = $this->store->idOf(payload: $watch);
		if ($id === '') {
			return;
		}

		                                                                    
		                                                                      
		                                                                   
		                                                           
		$record = $watch;
		unset($record['@self'], $record['id'], $record['uuid']);
		$record['lastRunAt'] = gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime());
		$record['lastOutcome'] = $outcome->outcome;
		$record['lastReason'] = mb_substr($outcome->reason, 0, 500, 'UTF-8');
		foreach (['fingerprint', 'lineFingerprints'] as $key) {
			if (array_key_exists($key, $outcome->state) === true) {
				$record[$key] = $outcome->state[$key];
			}
		}

		$this->store->save(
			schemaSlug: $this->store->schemaSlug(configKey: 'competitorWatch_schema', default: self::WATCH_SCHEMA),
			payload: $record,
			id: $id
		);
	}                 
}           
