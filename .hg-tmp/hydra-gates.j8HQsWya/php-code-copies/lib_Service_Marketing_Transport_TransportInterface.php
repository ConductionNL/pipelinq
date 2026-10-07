<?php

   
                               
  
                                                                      
                                                                 
                                                                       
                                                                     
                                                                          
                                                                            
                             
  
                    
                                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

   
                                   
  
                                                                                                                                             
   
interface TransportInterface {
	   
                           
   
                                                                          
                                                                       
                                                                          
                                                                       
                                                                        
                                                                      
                                                                        
                                                      
   
                                                        
   
                                   
   
                                                                                                                                              
    
	public function send(RenderedMail $mail): SendResult;
}               
