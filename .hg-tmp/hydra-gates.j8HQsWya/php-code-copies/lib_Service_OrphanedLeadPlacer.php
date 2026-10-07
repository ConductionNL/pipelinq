<?php

   
                               
  
                                                                         
                                                                           
                                                                         
                                                             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;

   
                                            
  
                                                                                                                                                     
   
final class OrphanedLeadPlacer {
	   
                
   
                                                                                       
    
	public function __construct(
		private readonly LeadStagePlacer $placer = new LeadStagePlacer(),
	) {
	}                   

	   
                                                                     
   
                                                                         
                                                                          
                                                                          
                                                                          
                                                                         
                                                                          
                                                      
   
                                                                       
                                                                        
                                                                                           
   
                                                                                            
   
                                                                                                                                                      
    
	public function forOrphanedLead(array $lead, array $pipelines, ?string $now = null): array {
		$pipelineId = trim((string)($lead['pipeline'] ?? ''));
		if ($pipelineId === '') {
			return [];
		}

		foreach ($pipelines as $pipeline) {
			if ((string)($pipeline['id'] ?? '') === $pipelineId) {
				return [];
			}
		}

		$target = $this->placer->defaultPipeline(pipelines: $pipelines);
		if ($target === null) {
			return [];
		}

		$stage = $this->closedStageFor(pipeline: $target, status: (string)($lead['status'] ?? ''));
		$stage ??= $this->placer->firstOpenStage(pipeline: $target);

		$patch = ['pipeline' => (string)$target['id']];
		if ($stage !== null) {
			$patch['stage'] = $stage['name'];
			$patch['stageOrder'] = $stage['order'];
			$patch['stageEnteredAt'] = $this->moment(iso: $now);
		}

		return $patch;
	}                       

	   
                                                                   
   
                                                       
                                                          
   
                                                                                                      
    
	private function closedStageFor(array $pipeline, string $status): ?array {
		if ($status !== 'won' && $status !== 'lost') {
			return null;
		}

		$wantWon = ($status === 'won');
		$stages = array_values(array_filter(
			(array)($pipeline['stages'] ?? []),
			static fn ($stage): bool => is_array($stage) === true
				&& trim((string)($stage['name'] ?? '')) !== ''
				&& ($stage['isClosed'] ?? false) === true
				&& (($stage['isWon'] ?? false) === true) === $wantWon
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

	   
                                              
   
                                                        
   
                              
    
	private function moment(?string $iso): string {
		if ($iso !== null && trim($iso) !== '') {
			return $iso;
		}

		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}              
}           
