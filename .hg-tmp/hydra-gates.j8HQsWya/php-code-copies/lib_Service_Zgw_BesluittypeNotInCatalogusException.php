<?php

   
                                               
  
                                                                          
                                                                           
                                                                     
                                         
  
                      
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

   
                                                       
  
                                                                                      
                
   
class BesluittypeNotInCatalogusException extends ZgwException {
	   
                
   
                                                                                     
    
	public function __construct(
		public readonly string $omschrijving,
	) {
		parent::__construct(
			message: sprintf(
				'ZGW: besluittype with omschrijving "%s" not found in catalogus',
				$omschrijving
			)
		);
	}                   
}           
