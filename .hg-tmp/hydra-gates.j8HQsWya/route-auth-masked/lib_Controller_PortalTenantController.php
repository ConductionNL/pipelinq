<?php

   
                                   
  
                                                                              
                                                                               
                                                                            
                                                                          
                                                                            
                                                                
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                   
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalTenantController extends PortalApiController {
	   
                
   
                                         
                                                      
                                              
                                                          
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalTenantService $tenant,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                                                       
   
                                             
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 240, period: 60)]
	public function config(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				return [$this->tenant->getPublicConfig(tenantId: $tenantId), Http::STATUS_OK];
			}
		);
	}              
}           
