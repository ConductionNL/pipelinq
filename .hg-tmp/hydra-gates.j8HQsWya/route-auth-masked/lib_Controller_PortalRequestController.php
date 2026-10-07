<?php

   
                                    
  
                                                                      
                                                                                
                                                                                 
                                                                           
                                                                             
                                                      
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalRequestService;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                              
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalRequestController extends PortalApiController {
	   
                
   
                                         
                                                      
                                              
                                                              
                                                          
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalRequestService $requests,
		private PortalTenantService $tenant,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                 
   
                                                
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$page = $this->requests->getForAccount(
					$ctx['account'],
					$this->intParam(name: 'page', default: 1),
					$this->intParam(name: 'perPage', default: 10)
				);
				return [$page, Http::STATUS_OK];
			}
		);
	}             

	   
                                           
   
                                     
   
                                            
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function show(string $id): JSONResponse {
		return $this->guarded(
			handler: function () use ($id): array {
				$ctx = $this->requireSession();
				$expose = ($this->tenant->getConfig($ctx['tenantId'])['exposeAssigneeName'] ?? false) === true;
				$detail = $this->requests->getDetailForAccount(account: $ctx['account'], requestId: $id, exposeAssigneeName: $expose);
				if ($detail === null) {
					return [['errorCode' => 'notFound', 'message' => 'Niet gevonden.'], Http::STATUS_NOT_FOUND];
				}

				return [$detail, Http::STATUS_OK];
			}
		);
	}            

	   
                         
   
                                                           
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 30, period: 60)]
	public function create(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$attachments = $this->request->getParam('attachments', []);
				if (is_array($attachments) === false) {
					$attachments = [];
				}

				$result = $this->requests->submit(
					account: $ctx['account'],
					tenantId: $ctx['tenantId'],
					subject: $this->strParam(name: 'subject'),
					body: $this->strParam(name: 'body'),
					attachments: $attachments,
					categoryId: $this->strParam(name: 'categoryId')
				);
				return [$result, Http::STATUS_CREATED];
			}
		);
	}              

	   
                                      
   
                                     
   
                                            
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 30, period: 60)]
	public function reply(string $id): JSONResponse {
		return $this->guarded(
			handler: function () use ($id): array {
				$ctx = $this->requireSession();
				$detail = $this->requests->addReply(
					account: $ctx['account'],
					tenantId: $ctx['tenantId'],
					requestId: $id,
					message: $this->strParam(name: 'message')
				);
				return [$detail, Http::STATUS_OK];
			}
		);
	}             
}           
