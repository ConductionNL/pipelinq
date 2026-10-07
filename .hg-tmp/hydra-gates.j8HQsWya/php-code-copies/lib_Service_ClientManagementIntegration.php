<?php

   
                                        
  
                                                                 
                                                              
                                                                    
          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                       
  
                       
                                                              
                                                          
  
                                                                   
   
class ClientManagementIntegration {
	   
                
   
                                                            
                                          
                                                                                                        
   
                                                                    
    
	public function __construct(
		private ConsentService $consentService,
		private LoggerInterface $logger,
		private IntegriqConsentClient $integriq,
	) {
	}                   

	   
                                                
   
                                          
   
                                               
   
                                                                    
                                                                                                                                                      
    
	public function onContactDeleted(string $contactId): int {
		if ($contactId === '') {
			return 0;
		}

		                                                                     
		                                                                        
		                                      
		$erased = $this->integriq->eraseContact(contactRef: $contactId);
		if ($erased['recorded'] === false) {
			$this->logger->warning(
				'ClientManagementIntegration.onContactDeleted: integriq did not clear the contact link',
				['contactId' => $contactId, 'code' => $erased['code']]
			);
		}

		try {
			return $this->consentService->deleteForContact(contactId: $contactId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ClientManagementIntegration.onContactDeleted: failed',
				['contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}                        
}           
