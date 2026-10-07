<?php

   
                                         
  
                                                                              
                                
  
                                                                           
                                                                               
                                                                               
                                                                       
                                                                        
                                                          
                                                                           
  
                                                                                
                                                                             
                                                                           
                                                                            
                                            
  
                                                                               
                                                                              
                                                                                
                                                                               
                                                                               
                                                              
  
                     
                                      
                                             
                                  
                                                                                    
  
                                                 
  
                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

   
                                                                           
                                                       
   
interface PresenceSubscribingInterface {
	   
                                                                 
   
                                      
                                             
   
                
   
                                                      
    
	public function subscribeToPresence(string $userId, string $extension): void;
}               
