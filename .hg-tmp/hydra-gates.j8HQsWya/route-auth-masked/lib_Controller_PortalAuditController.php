<?php

   
                                  
  
                                                                             
                                                                                
                                                                               
                                                                               
                           
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                   
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalAuditController extends PortalApiController {
	   
                
   
                                         
                                                      
                                              
                                                       
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalAuditService $audit,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                                                          
   
                                    
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$events = $this->audit->getForAccount($ctx['accountId']);
				$page = max(1, $this->intParam(name: 'page', default: 1));
				$perPage = min(100, max(1, $this->intParam(name: 'perPage', default: 25)));

				return [
					[
						'total' => count($events),
						'page' => $page,
						'perPage' => $perPage,
						'items' => array_slice($events, (($page - 1) * $perPage), $perPage),
					],
					Http::STATUS_OK,
				];
			}
		);
	}             
}           
