<?php

   
                                 
  
                                                                             
                                                                                
                                                                              
                                                                               
                                                                             
                                                            
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

   
                                    
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalInvoiceService extends AbstractPortalReadFacade {
	   
                 
   
                                  
    
	protected function schemaKey(): string {
		return 'posTransaction';
	}                 

	   
                 
   
                             
    
	protected function delegationScope(): string {
		return 'view-invoices';
	}                       

	   
                 
   
                                                               
   
                                                                            
    
	protected function ownerIds(array $object): array {
		return [null, $this->readId(value: ($object['client'] ?? null))];
	}                

	   
                 
   
                                                               
                                                              
   
                                                 
    
	protected function present(array $object, ?string $delegatedFrom): array {
		return [
			'id' => $this->objectId(object: $object),
			'invoiceNumber' => ($object['invoiceNumber'] ?? $object['reference'] ?? null),
			'date' => ($object['confirmedAt'] ?? $object['settledAt'] ?? null),
			'amount' => ($object['total'] ?? null),
			'status' => ($object['status'] ?? null),
			'delegatedFrom' => $delegatedFrom,
		];
	}               

	   
                 
   
                                                               
   
                                
    
	protected function sortKey(array $object): string {
		return (string)($object['confirmedAt'] ?? $object['settledAt'] ?? '');
	}               
}           
