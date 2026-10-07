<?php

   
                                  
  
                                                                                
                                                                                 
                                                                                
                                                                             
                                                          
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

   
                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalContractService extends AbstractPortalReadFacade {
	   
                 
   
                                  
    
	protected function schemaKey(): string {
		return 'salesContract';
	}                 

	   
                 
   
                             
    
	protected function delegationScope(): string {
		return 'view-contracts';
	}                       

	   
                 
   
                                                         
   
                                                                            
    
	protected function ownerIds(array $object): array {
		return [
			$this->readId(value: ($object['contact'] ?? null)),
			$this->readId(value: ($object['client'] ?? null)),
		];
	}                

	   
                 
   
                                                         
                                                              
   
                                                  
    
	protected function present(array $object, ?string $delegatedFrom): array {
		return [
			'id' => $this->objectId(object: $object),
			'contractNumber' => ($object['contractNumber'] ?? $object['reference'] ?? null),
			'startDate' => ($object['startDate'] ?? null),
			'endDate' => ($object['endDate'] ?? null),
			'value' => ($object['value'] ?? null),
			'status' => ($object['status'] ?? null),
			'delegatedFrom' => $delegatedFrom,
		];
	}               

	   
                 
   
                                                         
   
                                
    
	protected function sortKey(array $object): string {
		return (string)($object['startDate'] ?? '');
	}               
}           
