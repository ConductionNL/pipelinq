<?php

   
                               
  
                                                                         
                                                                            
                                                                        
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

   
                                                                         
  
                                                                         
                                                                           
                                                                          
                                                                              
                            
  
                                                                                
   
class ExportAccessPolicy {
	   
                                                
   
               
    
	public const ANALYST_GROUP_KEY = 'export_analyst_group';

	   
                
   
                                                
                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}                   

	   
                                                                
   
                                                                    
                                                                           
                        
   
                                              
   
                                                             
   
                                                                                 
    
	public function isExportAdmin(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$group = $this->appConfig->getValueString(Application::APP_ID, self::ANALYST_GROUP_KEY, '');
		if ($group === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $group);
	}                     
}           
