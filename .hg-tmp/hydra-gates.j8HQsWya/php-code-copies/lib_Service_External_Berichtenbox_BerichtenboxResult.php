<?php

   
                                                                        
  
                    
                                                       
  
                                                              
                                  
                                                                                    
  
                            
  
                                                                                      
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\External\Berichtenbox;

   
                                                                     
           
  
                                                        
                                                              
                                                               
                                                                   
                                                        
  
                                                                                      
   
final class BerichtenboxResult {
	   
                                      
   
                                        
                                              
                                             
                                           
                                       
                                           
                                    
                                          
                                      
                                    
                                 
                                                        
                                               
                                                      
                                                   
                                                         
                                                         
    
	public function __construct(
		public readonly string $outcome,
		public readonly string $logiusReference,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}                   
}           
