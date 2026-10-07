<?php

   
                             
  
                                                                          
                   
  
                    
                                      
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Search\KeywordTargetService;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryDailyStore;

   
                                                                 
  
                                                                                                      
   
class DemoSearchSeeder {
	   
                
   
                                                             
                                                         
    
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly DemoSeedValues $values,
	) {
	}                   

	   
                                                          
   
                                                                      
                                                                       
                                                                        
                                                                       
                                                                           
                                                                         
   
                                                                         
   
                                                                
   
                                                                                                       
    
	public function seed(array $search): array {
		$counts = ['rows' => 0, 'targets' => 0, 'skipped' => 0];
		$property = (string)($search['property'] ?? '');
		$source = (string)($search['source'] ?? '');
		if ($property === '' || $source === '') {
			return $counts;
		}

		[$rowSchema, $targetSchema] = $this->schemas();
		$now = gmdate('Y-m-d\TH:i:s\Z');

		$rowsSeeded = ($this->demoRows(schemaSlug: $rowSchema, property: $property, source: $source) !== []);
		if ($rowsSeeded === true) {
			$counts['skipped']++;
		}

		if ($rowsSeeded === false) {
			foreach (($search['rows'] ?? []) as $row) {
				$payload = $this->rowPayload(row: $row, property: $property, source: $source, importedAt: $now);
				if ($this->store->save(schemaSlug: $rowSchema, payload: $payload) !== null) {
					$counts['rows']++;
				}
			}
		}

		$targets = $this->seedTargets(
			definitions: ($search['targets'] ?? []),
			schemaSlug: $targetSchema,
			property: $property,
			createdAt: $now,
		);
		$counts['targets'] = $targets['created'];
		$counts['skipped'] += $targets['skipped'];

		return $counts;
	}            

	   
                                                            
   
                                                                       
                                                                          
                                                                             
   
                                                                         
   
                                                  
   
                                                                                                       
    
	public function remove(array $search): array {
		$counts = ['rows' => 0, 'targets' => 0];
		$property = (string)($search['property'] ?? '');
		$source = (string)($search['source'] ?? '');
		if ($property === '' || $source === '') {
			return $counts;
		}

		[$rowSchema, $targetSchema] = $this->schemas();

		foreach ($this->demoRows(schemaSlug: $rowSchema, property: $property, source: $source) as $row) {
			if ($this->store->delete(schemaSlug: $rowSchema, id: $this->store->idOf(payload: $row)) === true) {
				$counts['rows']++;
			}
		}

		foreach ($this->store->findAll(schemaSlug: $targetSchema) as $target) {
			if ((string)($target['property'] ?? '') !== $property
				|| str_starts_with((string)($target['notes'] ?? ''), DemoSeedValues::DEMO_PREFIX) === false
			) {
				continue;
			}

			if ($this->store->delete(schemaSlug: $targetSchema, id: $this->store->idOf(payload: $target)) === true) {
				$counts['targets']++;
			}
		}

		return $counts;
	}              

	   
                                                                      
   
                                                       
                                              
                                          
                                                       
   
                                                 
    
	private function rowPayload(array $row, string $property, string $source, string $importedAt): array {
		$row = $this->values->resolvePlaceholders(data: $row);
		$clicks = (int)($row['clicks'] ?? 0);
		$impressions = (int)($row['impressions'] ?? 0);
		$ctr = 0.0;
		if ($impressions > 0) {
			$ctr = round(($clicks / $impressions), 4);
		}

		return [
			'property' => $property,
			'date' => (string)($row['date'] ?? ''),
			'query' => (string)($row['query'] ?? ''),
			'page' => (string)($row['page'] ?? ''),
			'clicks' => $clicks,
			'impressions' => $impressions,
			'ctr' => $ctr,
			'position' => (float)($row['position'] ?? 0),
			'source' => $source,
			'importedAt' => $importedAt,
		];
	}                  

	   
                                                                          
   
                                                                                          
                                                        
                                              
                                                          
   
                                                     
    
	private function seedTargets(array $definitions, string $schemaSlug, string $property, string $createdAt): array {
		$result = ['created' => 0, 'skipped' => 0];

		$notes = [];
		foreach ($this->store->findAll(schemaSlug: $schemaSlug) as $target) {
			$notes[(string)($target['notes'] ?? '')] = true;
		}

		foreach ($definitions as $definition) {
			$data = ($definition['data'] ?? []);
			if (isset($notes[(string)($data['notes'] ?? '')]) === true) {
				$result['skipped']++;
				continue;
			}

			$data['property'] = $property;
			$data['createdAt'] = $createdAt;
			if ($this->store->save(schemaSlug: $schemaSlug, payload: $data) !== null) {
				$result['created']++;
			}
		}

		return $result;
	}                   

	   
                                                                                           
   
                                                      
    
	private function schemas(): array {
		return [
			$this->store->schemaSlug(SearchQueryDailyStore::SCHEMA . '_schema', SearchQueryDailyStore::SCHEMA),
			$this->store->schemaSlug(KeywordTargetService::SCHEMA_CONFIG_KEY, KeywordTargetService::SCHEMA_SLUG),
		];
	}               

	   
                                                                                
   
                                                                             
                                                                        
                                                                
   
                                             
                                              
                                          
   
                                                      
    
	private function demoRows(string $schemaSlug, string $property, string $source): array {
		return $this->store->findAll(schemaSlug: $schemaSlug, filters: ['property' => $property, 'source' => $source]);
	}                
}           
