<?php

   
                                    
  
                                                                                
                                                                         
                                                                             
                                                                                
                                                                         
                                        
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalAccountService;
use OCA\Pipelinq\Service\Portal\PortalExportService;
use OCA\Pipelinq\Service\Portal\PortalProfileService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                                  
  
                                                                                  
                                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalAccountController extends PortalApiController {
	   
                
   
                                         
                                                      
                                              
                                                             
                                                             
                                                          
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalProfileService $profile,
		private PortalAccountService $account,
		private PortalExportService $export,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                       
   
                                     
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function profile(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				return [$this->profile->present($ctx['account']), Http::STATUS_OK];
			}
		);
	}               

	   
                                         
   
                                             
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 30, period: 60)]
	public function updateProfile(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$changes = [];
				foreach (['displayName', 'phone', 'locale', 'address', 'jobTitle', 'email'] as $field) {
					$value = $this->request->getParam($field, null);
					if ($value !== null) {
						$changes[$field] = $value;
						if (is_string($value) === true) {
							$changes[$field] = trim($value);
						}
					}
				}

				$updated = $this->profile->update($ctx['account'], $ctx['tenantId'], $changes);
				return [$updated, Http::STATUS_OK];
			}
		);
	}                     

	   
                                               
   
                                                                              
                           
   
                                    
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function verifyEmail(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$ok = $this->profile->verifyEmail($this->strParam(name: 'token'), $tenantId);
				if ($ok === false) {
					return [['errorCode' => 'invalidToken', 'message' => 'Ongeldige of verlopen link.'], Http::STATUS_BAD_REQUEST];
				}

				return [['status' => 'email-verified'], Http::STATUS_OK];
			}
		);
	}                   

	   
                               
   
                                                                            
                                                                         
   
                                                        
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestExport(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$result = $this->export->requestExport($ctx['account'], $ctx['tenantId']);
				return [$result, Http::STATUS_ACCEPTED];
			}
		);
	}                     

	   
                                                         
   
                                             
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestClose(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$this->account->requestClosure(account: $ctx['account'], tenantId: $ctx['tenantId']);
				return [['status' => 'closure-requested'], Http::STATUS_OK];
			}
		);
	}                    

	   
                                         
   
                                    
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function confirmClose(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$this->account->close(token: $this->strParam(name: 'token'), tenantId: $tenantId);
				return [['status' => 'account-closed'], Http::STATUS_OK];
			}
		);
	}                    
}           
