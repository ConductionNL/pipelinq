<?php

   
                              
  
                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                           
  
                                                                            
   
class PipelineStageData {
	   
                                        
   
                                                                           
                                                                          
                                             
   
                                                                        
                                                                               
   
                                                 
                                                                                     
                                                                 
    
	public function getSalesPipelineData(?string $viewId=null, string $currency='EUR'): array {
		return $this->withViewId(
			data: [
				'title'            => 'Sales Pipeline',
				'description'      => 'Default sales pipeline for tracking leads from first contact through to won or lost.',
				'propertyMappings' => [
					['schemaSlug' => 'lead', 'columnProperty' => 'stage', 'totalsProperty' => 'value'],
					['schemaSlug' => 'request', 'columnProperty' => 'stage'],
				],
				'totalsLabel'      => $currency,
				'isDefault'        => true,
				'stages'           => $this->getSalesStages(),
			],
			viewId: $viewId
		);
	}                            

	   
                                                   
   
                                                                      
   
                                                            
                                                                                     
                                                                 
    
	public function getServiceRequestsPipelineData(?string $viewId=null): array {
		return $this->withViewId(
			data: [
				'title'            => 'Service Requests',
				'description'      => 'Default pipeline for tracking service requests from intake through completion.',
				'propertyMappings' => [
					['schemaSlug' => 'request', 'columnProperty' => 'status'],
				],
				'isDefault'        => false,
				'stages'           => $this->getServiceRequestStages(),
			],
			viewId: $viewId
		);
	}                                      

	   
                                                                           
                                      
   
                                             
                                                
   
                                    
                                                                 
    
	private function withViewId(array $data, ?string $viewId): array {
		if ($viewId !== null) {
			$data['viewId'] = $viewId;
		}

		return $data;
	}                  

	   
                                          
   
                                            
    
	private function getSalesStages(): array {
		return [
			[
				'name' => 'New',
				'order' => 0,
				'probability' => 10,
				'color' => '#3b82f6',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Contacted',
				'order' => 1,
				'probability' => 20,
				'color' => '#8b5cf6',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Qualified',
				'order' => 2,
				'probability' => 40,
				'color' => '#f59e0b',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Proposal',
				'order' => 3,
				'probability' => 60,
				'color' => '#f97316',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Negotiation',
				'order' => 4,
				'probability' => 80,
				'color' => '#ef4444',
				'isClosed' => false,
				'isWon' => false,
			],
			[
				'name' => 'Won',
				'order' => 5,
				'probability' => 100,
				'color' => '#22c55e',
				'isClosed' => true,
				'isWon' => true,
			],
			[
				'name' => 'Lost',
				'order' => 6,
				'probability' => 0,
				'color' => '#6b7280',
				'isClosed' => true,
				'isWon' => false,
			],
		];
	}                      

	   
                                                    
   
                                                      
    
	private function getServiceRequestStages(): array {
		return [
			['name' => 'New', 'order' => 0, 'color' => '#3b82f6', 'isClosed' => false, 'isWon' => false],
			['name' => 'In Progress', 'order' => 1, 'color' => '#f59e0b', 'isClosed' => false, 'isWon' => false],
			['name' => 'Completed', 'order' => 2, 'color' => '#22c55e', 'isClosed' => true, 'isWon' => true],
			['name' => 'Rejected', 'order' => 3, 'color' => '#ef4444', 'isClosed' => true, 'isWon' => false],
			['name' => 'Converted to Case', 'order' => 4, 'color' => '#8b5cf6', 'isClosed' => true, 'isWon' => false],
		];
	}                               
}           
