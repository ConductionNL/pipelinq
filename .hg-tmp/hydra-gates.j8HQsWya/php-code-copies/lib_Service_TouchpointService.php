<?php

   
                              
  
                                                                     
                                                                    
                                                                        
                                    
  
                                                                     
                                                               
                                                                
                                                                   
                                                                       
                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

   
                                                                  
  
                                                                                                                                                               
   
class TouchpointService {

	   
                                                   
   
               
    
	public const SCHEMA_SLUG = 'touchpoint';

	   
                                                           
   
               
    
	public const SCHEMA_CONFIG_KEY = 'touchpoint_schema';

	   
                                                 
   
                           
    
	public const KINDS = ['click', 'visit', 'submit', 'reply'];

	   
                
   
                                                                
   
                                                                                                                                                                
    
	public function __construct(
		private ListObjectStore $store,
	) {
	}                   

	   
                                                                 
   
                            
   
                                                                                                                                                                
    
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}                  

	   
                                                   
   
                                                                     
                                                                     
                                                                     
                                                                       
                                    
   
                                           
   
                                                             
   
                                                                                                                                                   
    
	public function forCampaign(string $campaignId): array {
		if (trim($campaignId) === '') {
			return [];
		}

		$rows = $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['campaignId' => trim($campaignId)],
		);

		$indexed = [];
		foreach ($rows as $index => $row) {
			$indexed[] = ['index' => $index, 'row' => $row];
		}

		usort(
			$indexed,
			static function (array $left, array $right): int {
				$compared = strcmp(
					(string)($left['row']['occurredAt'] ?? ''),
					(string)($right['row']['occurredAt'] ?? '')
				);
				if ($compared !== 0) {
					return $compared;
				}

				return ($left['index'] <=> $right['index']);
			}
		);

		return array_map(static fn (array $entry): array => $entry['row'], $indexed);
	}                   

	   
                                                    
   
                                                                           
   
                                                                
   
                                                                                                                                                                
    
	public function existsForNonce(string $nonce): bool {
		if (trim($nonce) === '') {
			return false;
		}

		return ($this->store->findAll(schemaSlug: $this->schemaSlug(), filters: ['nonce' => trim($nonce)]) !== []);
	}                      

	   
                          
   
                                                                          
                                                                                    
   
                                                                                   
   
                                                                                                                                                                
    
	public function append(array $touchpoint): ?array {
		$kind = (string)($touchpoint['kind'] ?? '');
		if (in_array($kind, self::KINDS, true) === false) {
			return null;
		}

		$row = $touchpoint;
		if (trim((string)($row['occurredAt'] ?? '')) === '') {
			$row['occurredAt'] = gmdate('Y-m-d\TH:i:sP');
		}

		$row['createdAt'] = gmdate('Y-m-d\TH:i:sP');

		return $this->store->save(schemaSlug: $this->schemaSlug(), payload: $row);
	}              

	   
                                         
   
                                                                
   
                                              
   
                                                                                                                                                                
    
	public function idOf(?array $touchpoint): string {
		return $this->store->idOf(payload: $touchpoint);
	}            

	   
                                    
   
                                                                       
                                                                   
                                                                    
                                                                  
   
                                                 
   
                               
   
                                                                                                                     
    
	public function channelForMedium(string $medium): string {
		$value = strtolower(trim($medium));
		if ($value === '') {
			return 'direct';
		}

		$map = ['cpc' => 'paid', 'display' => 'paid', 'ppc' => 'paid'];

		return ($map[$value] ?? $value);
	}                        
}           
