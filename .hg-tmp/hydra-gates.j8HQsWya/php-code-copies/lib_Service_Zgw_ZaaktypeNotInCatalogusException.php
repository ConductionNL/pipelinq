<?php

   
                                            
  
                                                                          
                                                                        
                                                                   
                                                                      
        
  
                      
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

   
                                                    
  
                                                                                      
                
   
class ZaaktypeNotInCatalogusException extends ZgwException {
	   
                
   
                                                                                  
    
	public function __construct(
		public readonly string $omschrijving,
	) {
		parent::__construct(
			message: sprintf(
				'ZGW: zaaktype with omschrijving "%s" not found in catalogus',
				$omschrijving
			)
		);
	}                   
}           
