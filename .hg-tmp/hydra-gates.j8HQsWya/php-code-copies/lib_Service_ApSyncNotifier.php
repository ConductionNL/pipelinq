<?php

   
                           
  
                                                                        
                                                                       
                                                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

   
                                                         
  
                                                                              
                                                                          
                                         
  
                                                                             
   
class ApSyncNotifier {
	   
                
   
                                                                             
                                                                            
    
	public function __construct(
		private NotificationService $notificationService,
		private IGroupManager $groupManager,
	) {
	}                   

	   
                                                                   
   
                                                                              
                                                                         
   
                
   
                                                                              
    
	public function notifyFailure(string $expenseTitle, string $uuid): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: 'ap_sync_failed',
				parameters: [
					'expenseTitle' => $expenseTitle,
					'id' => $uuid,
				],
				objectType: 'expense',
				objectId: $uuid
			);
		}
	}                     
}           
