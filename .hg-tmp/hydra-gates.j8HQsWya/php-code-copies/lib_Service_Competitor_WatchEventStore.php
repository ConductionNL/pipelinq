<?php

   
                            
  
                                                      
  
                                                                             
                                                                          
                                                                      
                                                                             
                                                                               
  
                    
                                            
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;

   
                                     
  
                                                                                                                                                              
   
class WatchEventStore {

	   
                                                   
   
               
    
	public const SCHEMA_SLUG = 'watchEvent';

	   
                                                     
   
               
    
	public const SCHEMA_CONFIG_KEY = 'watchEvent_schema';

	   
                
   
                                                                
                                                                        
   
                                                                                                                                                               
    
	public function __construct(
		private ListObjectStore $store,
		private ITimeFactory $time,
	) {
	}                   

	   
                           
   
                  
   
                                                                                                                                                               
    
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}                  

	   
                                     
   
                                                            
   
                                                        
   
                                                                                                                                                                                 
    
	public function all(array $filters = []): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug(), filters: $filters);
	}           

	   
                                                
   
                                                             
                                                                            
                                                                              
   
                                                                  
   
                                                                                                                                                               
    
	public function upsert(array $watch, array $item, array $extra = []): array {
		$watchId = $this->store->idOf(payload: $watch);
		$url = trim((string)($item['url'] ?? ''));
		if ($watchId === '' || $url === '') {
			return ['created' => false, 'event' => null];
		}

		$existing = $this->existing(watchId: $watchId, url: $url);

		                                                                    
		                                                                   
		                                                                  
		                                  
		$base = ($existing ?? []);
		unset($base['@self'], $base['id'], $base['uuid']);
		$record = [
			'competitorId' => trim((string)($watch['competitorId'] ?? '')),
			'watchId' => $watchId,
			'kind' => trim((string)($watch['kind'] ?? '')),
			'title' => mb_substr(trim((string)($item['title'] ?? $url)), 0, 500, 'UTF-8'),
			'url' => $url,
			'diffSummary' => mb_substr(trim((string)($item['summary'] ?? '')), 0, 2000, 'UTF-8'),
			'sourceStamp' => mb_substr(trim((string)($item['stamp'] ?? '')), 0, 128, 'UTF-8'),
			'seenAt' => $this->seenAt(existing: $existing),
		];

		$id = null;
		if ($existing !== null) {
			$id = $this->store->idOf(payload: $existing);
		}

		$saved = $this->store->save(
			schemaSlug: $this->schemaSlug(),
			payload: array_merge($base, $record, $extra),
			id: $id
		);

		return ['created' => ($existing === null), 'event' => $saved];
	}              

	   
                                                     
   
                                     
                               
   
                                     
    
	private function existing(string $watchId, string $url): ?array {
		$found = $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['watchId' => $watchId, 'url' => $url]
		);
		foreach ($found as $event) {
			return $event;
		}

		return null;
	}                

	   
                                                                          
              
   
                                                                
   
                                       
    
	private function seenAt(?array $existing): string {
		$stored = trim((string)($existing['seenAt'] ?? ''));
		if ($stored !== '') {
			return $stored;
		}

		return gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime());
	}              
}           
