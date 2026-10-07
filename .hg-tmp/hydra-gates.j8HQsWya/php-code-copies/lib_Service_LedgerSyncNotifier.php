<?php

   
                               
  
                                                                            
                                                                               
               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

   
                                                    
  
                                                                              
                                                                                 
                                  
  
                                                                                     
   
class LedgerSyncNotifier {
	   
                
   
                                                                             
                                                                            
    
	public function __construct(
		private NotificationService $notificationService,
		private IGroupManager $groupManager,
	) {
	}                   

	   
                                                                      
   
                                                
                                                                        
                                                                         
   
                
   
                                                                                      
    
	public function notifyFailure(string $projectName, string $eventType, string $uuid): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: 'ledger_sync_failed',
				parameters: [
					'projectName' => $projectName,
					'eventType' => $eventType,
				],
				objectType: 'project',
				objectId: $uuid
			);
		}
	}                     
}           
