<?php

   
                                 
  
                                                                              
                                                                          
                                                                       
                                                                            
                                                                             
                                                                    
                                                                       
  
                                                                           
                                                                                
                                                                                
                                                       
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                        
                                                                           
                                                                           
                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ServiceAccount;
use OCP\AppFramework\Http;

   
                                                                    
  
                                                                 
                                                                            
                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalServiceAccount extends ServiceAccount {
	   
                                                                 
   
               
    
	public const CONFIG_KEY = 'portal_service_account';

	   
                                                            
   
               
    
	public const GROUP = 'pipelinq-portal-service';

	   
                                       
   
                           
                                                                                         
    
	protected function configKey(): string {
		return self::CONFIG_KEY;
	}                 

	   
                                       
   
                                
                                                                                         
                                                                            
                                                                            
                               
    
	public function group(): string {
		return self::GROUP;
	}             

	   
                                                         
   
                                             
                                                         
   
                 
   
                                                  
                                                                                         
    
	protected function refuse(string $userId, string $reason): never {
		$this->logger->error(
			'Pipelinq portal: no usable portal service account, the portal refuses every write',
			['app' => Application::APP_ID, 'userId' => $userId, 'reason' => $reason]
		);
		                                                            
		throw new PortalException(
			Http::STATUS_SERVICE_UNAVAILABLE,
			'portalUnavailable',
			'Het portaal is tijdelijk niet beschikbaar.'
		);
	}              
}           
