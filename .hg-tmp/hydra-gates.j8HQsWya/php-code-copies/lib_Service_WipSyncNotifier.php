<?php

   
                            
  
                                                                             
                                                                             
        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

   
                                                 
  
                                                                           
                                                                               
                                         
  
                                                                                                                                  
   
class WipSyncNotifier {
	   
                
   
                                                                             
                                                                            
    
	public function __construct(
		private NotificationService $notificationService,
		private IGroupManager $groupManager,
	) {
	}                   

	   
                                                                   
   
                                                                              
                                                                            
   
                
   
                                                                                                                                   
    
	public function notifyFailure(string $title, string $uuid): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: 'wip_sync_failed',
				parameters: [
					'title' => $title,
				],
				objectType: 'billingTimeEntry',
				objectId: $uuid
			);
		}
	}                     
}           
