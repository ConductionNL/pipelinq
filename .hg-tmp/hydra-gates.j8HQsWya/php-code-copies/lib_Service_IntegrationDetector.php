<?php

   
                                                                              
  
                                                                               
                                                                              
                                                                       
                                                                              
                                                 
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\IURLGenerator;

   
                                                         
  
                                                                                                                                         
   
class IntegrationDetector {
	   
                
   
                                                        
                                                                               
    
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urlGenerator,
	) {
	}                   

	   
                                                      
   
                                                                                           
   
                                                                                      
    
	public function shillinq(): array {
		$installed = $this->appManager->isInstalled('shillinq');
		$url = '';
		if ($installed === true) {
			$url = $this->urlGenerator->getAbsoluteURL('/index.php/apps/shillinq/');
		}

		return ['installed' => $installed, 'url' => $url];
	}                

	   
                                                        
   
                                                                  
                                                                    
                                                                     
   
                                                                                                     
   
                                                                                      
    
	public function xwiki(): array {
		if ($this->appManager->isInstalled('xwiki') === true) {
			return ['available' => true, 'source' => 'xwiki-app'];
		}

		                                                                    
		                                                                      
		if ($this->appManager->isInstalled('openregister') === true
			&& FleetAppId::isInstalled($this->appManager, 'integriq') === true
		) {
			return ['available' => true, 'source' => 'openregister'];
		}

		return ['available' => false, 'source' => 'none'];
	}             

	   
                                                 
   
                                                                                                               
   
                                                                                      
    
	public function all(): array {
		return ['shillinq' => $this->shillinq(), 'xwiki' => $this->xwiki()];
	}           
}           
