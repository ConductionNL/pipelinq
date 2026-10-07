<?php

   
                                       
  
                                                                                 
                                                                              
                                                                               
                                                                             
                                                             
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                   
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalDelegationController extends PortalApiController {
	   
                
   
                                         
                                                      
                                              
                                                                       
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalDelegationService $delegations,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                                        
   
                                         
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 120, period: 60)]
	public function index(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$this->requireB2b(account: $ctx['account']);
				return [['delegations' => $this->delegations->listGrantedBy(granterAccountId: $ctx['accountId'])], Http::STATUS_OK];
			}
		);
	}             

	   
                                                                              
                                 
   
                                                                           
                           
   
                                                
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function create(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$this->requireB2b(account: $ctx['account']);

				$scopes = $this->request->getParam('scopes', []);
				if (is_array($scopes) === false) {
					$scopes = [];
				}

				$validUntil = $this->strParam(name: 'validUntil');
				if ($validUntil === '') {
					$validUntil = null;
				}

				$delegation = $this->delegations->grant(
					granterAccountId: $ctx['accountId'],
					tenantId: $ctx['tenantId'],
					granteeEmail: $this->strParam(name: 'granteeEmail'),
					scopes: $scopes,
					validUntil: $validUntil
				);
				return [$delegation, Http::STATUS_CREATED];
			}
		);
	}              

	   
                                                     
   
                                        
   
                                    
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 30, period: 60)]
	public function destroy(string $id): JSONResponse {
		return $this->guarded(
			handler: function () use ($id): array {
				$ctx = $this->requireSession();
				$this->requireB2b(account: $ctx['account']);
				$this->delegations->revoke(delegationId: $id, granterAccountId: $ctx['accountId'], tenantId: $ctx['tenantId']);
				return [['status' => 'revoked'], Http::STATUS_OK];
			}
		);
	}               

	   
                                                                     
   
                                                     
   
                
   
                                                        
    
	private function requireB2b(array $account): void {
		if (($account['accountType'] ?? 'b2c') !== 'b2b') {
			throw new PortalException(status: Http::STATUS_FORBIDDEN, errorCode: 'b2bOnly', message: 'Delegatie is alleen voor zakelijke accounts.');
		}
	}                  
}           
