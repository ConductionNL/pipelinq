<?php

   
                                   
  
                                                                        
                                                                         
                                                                       
                                                                     
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

   
                                                              
  
                                                                           
                                                                          
           
  
                                                      
   
class BillingHandoffNotifier {
	   
                
   
                                                                             
                                                                            
    
	public function __construct(
		private NotificationService $notificationService,
		private IGroupManager $groupManager,
	) {
	}                   

	   
                                                                            
   
                                                                                        
                                                                            
                                                                  
   
                
   
                                                       
    
	public function notifyFailure(string $clientName, string $clientId, string $batchId): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: 'billing_handoff_failed',
				parameters: [
					'title' => $clientName,
					'batchId' => $batchId,
				],
				objectType: 'client',
				objectId: $clientId
			);
		}
	}                     
}           
