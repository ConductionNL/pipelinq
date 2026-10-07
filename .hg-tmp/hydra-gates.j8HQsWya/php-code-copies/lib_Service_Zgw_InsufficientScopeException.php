<?php

   
                                       
  
                                                                               
                                                                        
                                                                        
                                                            
  
                                                                           
                                                                        
                                  
  
                                                                          
                                       
  
                      
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

   
                                     
  
                                                                                      
                
   
class InsufficientScopeException extends ZgwException {
	   
                
   
                                                                    
                                                                                    
                                                                          
    
	public function __construct(
		public readonly string $scope,
		public readonly string $caseTypeUrl,
		string $additionalInfo = '',
	) {
		$suffix = '';
		if ($additionalInfo !== '') {
			$suffix = ' ' . $additionalInfo;
		}

		$msg = sprintf(
			'ZGW: missing scope "%s" on resource "%s".%s',
			$scope,
			$caseTypeUrl,
			$suffix
		);
		parent::__construct(message: $msg);
	}                   
}           
