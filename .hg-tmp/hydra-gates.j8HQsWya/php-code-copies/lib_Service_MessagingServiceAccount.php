<?php

   
                                    
  
                                                                           
                                                                        
                                                                             
                                                                             
                                                                             
                                                                               
                                                                            
                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;

   
                                                                        
  
                                                                                                      
   
class MessagingServiceAccount extends ServiceAccount {
	   
                                                                    
   
               
    
	public const CONFIG_KEY = 'messaging_service_account';

	   
                                                                              
   
               
    
	public const GROUP = 'pipelinq-messaging-service';

	   
                                       
   
                           
                                                                                                       
    
	protected function configKey(): string {
		return self::CONFIG_KEY;
	}                 

	   
                                          
   
                                
                                                                                                       
    
	public function group(): string {
		return self::GROUP;
	}             

	   
                                        
   
                                             
                                                         
   
                 
   
                                                      
                                                                                                       
    
	protected function refuse(string $userId, string $reason): never {
		$this->logger->error(
			'Pipelinq messaging: no usable messaging service account, the SMS and WhatsApp webhooks write nothing',
			['app' => Application::APP_ID, 'userId' => $userId, 'reason' => $reason]
		);
		throw new ServiceAccountUnavailableException('No usable messaging service account.');
	}              
}           
