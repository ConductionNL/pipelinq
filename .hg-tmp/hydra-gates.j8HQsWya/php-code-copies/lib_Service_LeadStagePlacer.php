<?php

   
                            
  
                                                                              
                                                                               
                                                                            
                                                                             
                                                                      
  
                                                                            
                                                                              
                                                                               
                                                                             
                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                            
  
                                    
                                                                    
  
                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;

   
                                                    
  
                                               
   
final class LeadStagePlacer {

	   
                                                  
   
               
    
	private const LEAD_SLUG = 'lead';

	   
                                                 
   
                                                                             
                                                                         
                                                                        
   
                                                                        
   
                                                                              
   
                                                                                        
    
	public function defaultPipeline(array $pipelines): ?array {
		$fallback = null;
		foreach ($pipelines as $pipeline) {
			if (trim((string)($pipeline['id'] ?? '')) === '' || $this->appliesToLeads(pipeline: $pipeline) === false) {
				continue;
			}

			if (($pipeline['isDefault'] ?? false) === true) {
				return $pipeline;
			}

			$fallback ??= $pipeline;
		}

		return $fallback;
	}                       

	   
                                                                     
   
                                                       
   
                                                                                                        
   
                                                                                        
    
	public function firstOpenStage(array $pipeline): ?array {
		$stages = array_values(array_filter(
			(array)($pipeline['stages'] ?? []),
			static fn ($stage): bool => is_array($stage) === true
				&& trim((string)($stage['name'] ?? '')) !== ''
				&& ($stage['isClosed'] ?? false) !== true
		));
		if ($stages === []) {
			return null;
		}

		usort(
			$stages,
			static fn (array $left, array $right): int => ((int)($left['order'] ?? 0) <=> (int)($right['order'] ?? 0))
		);

		return [
			'name' => (string)$stages[0]['name'],
			'order' => (int)($stages[0]['order'] ?? 1),
		];
	}                      

	   
                                                       
   
                                                                      
                                                                        
             
   
                                                                     
                                                                        
                                                                                           
   
                                                                                                               
   
                                                                                        
    
	public function forNewLead(array $lead, array $pipelines, ?string $now = null): array {
		$patch = $this->stagePatch(lead: $lead, pipelines: $pipelines);
		if ($patch === null) {
			return [];
		}

		if (isset($patch['stage']) === true || $this->isBlank(value: $lead['stageEnteredAt'] ?? null) === true) {
			$patch['stageEnteredAt'] = $this->moment(iso: $now);
		}

		return $patch;
	}                  

	   
                                                          
   
                                                                          
                                                                           
                                               
   
                                                                     
                                                                        
                                                                                                                         
   
                                                                                                               
   
                                                                                        
    
	public function forStoredLead(array $lead, array $pipelines, ?string $enteredAt = null): array {
		$patch = $this->stagePatch(lead: $lead, pipelines: $pipelines);
		if ($patch === null) {
			return [];
		}

		if (isset($patch['stage']) === true) {
			$patch['stageEnteredAt'] = $this->moment(iso: $enteredAt);
		}

		return $patch;
	}                     

	   
                                                          
   
                                                                           
                                                                  
                                                                      
                                                                         
                     
   
                                                                     
                                                                        
   
                                                                                
    
	private function stagePatch(array $lead, array $pipelines): ?array {
		$pipeline = $this->pipelineFor(lead: $lead, pipelines: $pipelines);
		if ($pipeline === null) {
			return null;
		}

		$patch = [];
		if (trim((string)($lead['pipeline'] ?? '')) === '') {
			$patch['pipeline'] = (string)$pipeline['id'];
		}

		$stageName = trim((string)($lead['stage'] ?? ''));
		if ($stageName === '') {
			$first = $this->firstOpenStage(pipeline: $pipeline);
			if ($first === null) {
				return $patch;
			}

			$patch['stage'] = $first['name'];
			$patch['stageOrder'] = $first['order'];
			return $patch;
		}

		$order = $this->stageOrder(pipeline: $pipeline, stageName: $stageName);
		if ($order !== null && $this->isBlank(value: $lead['stageOrder'] ?? null) === true) {
			$patch['stageOrder'] = $order;
		}

		return $patch;
	}                  

	   
                                              
   
                                                        
   
                              
    
	private function moment(?string $iso): string {
		if ($iso !== null && trim($iso) !== '') {
			return $iso;
		}

		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}              

	   
                                                                             
   
                                                                     
                                                                        
   
                                                            
    
	private function pipelineFor(array $lead, array $pipelines): ?array {
		$pipelineId = trim((string)($lead['pipeline'] ?? ''));
		if ($pipelineId === '') {
			return $this->defaultPipeline(pipelines: $pipelines);
		}

		foreach ($pipelines as $pipeline) {
			if ((string)($pipeline['id'] ?? '') === $pipelineId) {
				return $pipeline;
			}
		}

		return null;
	}                   

	   
                                             
   
                                                        
                                                          
   
                                                                  
    
	private function stageOrder(array $pipeline, string $stageName): ?int {
		if ($stageName === '') {
			return null;
		}

		foreach ((array)($pipeline['stages'] ?? []) as $stage) {
			if (is_array($stage) === true && (string)($stage['name'] ?? '') === $stageName && isset($stage['order']) === true) {
				return (int)$stage['order'];
			}
		}

		return null;
	}                  

	   
                                      
   
                                                                            
                                                 
   
                                                       
   
                                               
    
	private function appliesToLeads(array $pipeline): bool {
		$slugs = [];
		foreach ((array)($pipeline['propertyMappings'] ?? []) as $mapping) {
			if (is_array($mapping) === true && (string)($mapping['schemaSlug'] ?? '') !== '') {
				$slugs[] = (string)$mapping['schemaSlug'];
			}
		}

		if ($slugs === []) {
			$entityType = (string)($pipeline['entityType'] ?? '');
			if ($entityType === '' || $entityType === 'both') {
				return true;
			}

			$slugs = [$entityType];
		}

		return in_array(self::LEAD_SLUG, $slugs, true);
	}                      

	   
                                                    
   
                                  
   
                                                 
    
	private function isBlank(mixed $value): bool {
		return $value === null || (is_string($value) === true && trim($value) === '');
	}               
}           
