<?php

   
                                 
  
                                                                                
                                                                             
                                                                                 
                                                                                
                                                                           
                                                                         
                                                                     
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Service\Portal\PasswordResetService;
use OCA\Pipelinq\Service\Portal\PortalAuthService;
use OCA\Pipelinq\Service\Portal\PortalMfaService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

   
                                   
  
                                                                                    
                                                                             
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalAuthController extends PortalApiController {
	   
                             
   
               
    
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	   
                
   
                                         
                                                      
                                              
                                                    
                                                              
                                                 
                                                                  
                                                                           
    
	public function __construct(
		IRequest $request,
		PortalRequestGuard $guard,
		LoggerInterface $logger,
		private PortalAuthService $auth,
		private PortalSessionManager $sessions,
		private PortalMfaService $mfa,
		private PasswordResetService $reset,
		private PortalObjectRepository $repository,
	) {
		parent::__construct(request: $request, guard: $guard, logger: $logger);
	}                   

	   
                                                         
   
                                                                       
                                                                  
                                                                          
                                                                        
                                                                       
                                         
   
                                                                         
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function login(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$totpCode = $this->strParam(name: 'totpCode');
				if ($totpCode === '') {
					$totpCode = null;
				}

				$result = $this->auth->login(
					email: $this->strParam(name: 'email'),
					password: (string)$this->request->getParam('password', ''),
					tenantId: $tenantId,
					totpCode: $totpCode,
					ipHash: $this->guard->ipHash(request: $this->request),
					userAgentHash: $this->guard->userAgentHash(request: $this->request)
				);

				return [$result, Http::STATUS_OK];
			}
		);
	}             

	   
                               
   
                                                    
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 60, period: 60)]
	public function logout(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$sessionId = (string)$this->repository->idOf(object: $ctx['session']);
				$this->sessions->revokeSession(sessionId: $sessionId, reason: 'logout');
				return [['status' => 'logged-out'], Http::STATUS_OK];
			}
		);
	}              

	   
                                     
   
                                        
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 60, period: 60)]
	public function extendSession(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$sessionId = (string)$this->repository->idOf(object: $ctx['session']);
				$updated = $this->sessions->extendSessionOrThrow(sessionId: $sessionId);

				return [['expiresAt' => ($updated['expiresAt'] ?? null)], Http::STATUS_OK];
			}
		);
	}                     

	   
                                                                       
   
                                                                            
                                                                     
                            
   
                                                     
   
                    
                                                                               
                                                                           
          
   
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 10, period: 60)]
	public function passwordResetRequest(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$this->reset->requestReset(email: $this->strParam(name: 'email'), tenantId: $tenantId);
				return [['status' => 'ok'], Http::STATUS_OK];
			}
		);
	}                            

	   
                                                          
   
                                                                        
                                                                           
                                       
   
                                    
   
                    
                                                                              
                                                                            
                       
   
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function passwordReset(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$tenantId = $this->requireTenant();
				$this->reset->resetPassword(
					$this->strParam(name: 'token'),
					(string)$this->request->getParam('password', ''),
					$tenantId
				);
				return [['status' => 'password-reset'], Http::STATUS_OK];
			}
		);
	}                     

	   
                                                                           
                                                                           
   
                                                
   
                    
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 20, period: 60)]
	public function mfaEnroll(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$account = $ctx['account'];
				$secret = $this->mfa->generateSecret();
				$uri = $this->mfa->provisioningUri(
					secret: $secret,
					accountLabel: (string)($account['email'] ?? 'portal'),
					issuer: 'Pipelinq'
				);

				                                                         
				  
				                                                                
				                                                          
				                                                            
				                                                               
				                                                            
				                                                            
				                                       
				  
				                                                               
				                                                            
				                                                         
				$account['mfaPendingSecret'] = $this->mfa->encryptSecret(secret: $secret);
				$this->repository->save(self::ACCOUNT_SCHEMA, $account, $ctx['accountId']);

				return [['secret' => $secret, 'otpauthUri' => $uri], Http::STATUS_OK];
			}
		);
	}                 

	   
                                                      
   
                                                                           
                                                                        
                                    
   
                                    
   
                    
                                                                             
                                                                              
            
   
                   
               
                                                                                         
                                                                            
                                                                            
                               
    
	#[AnonRateLimit(limit: 10, period: 60)]
	public function mfaVerify(): JSONResponse {
		return $this->guarded(
			handler: function (): array {
				$ctx = $this->requireSession();
				$account = $ctx['account'];

				                                                            
				                                                              
				                                                                
				                                              
				$pending = ($account['mfaPendingSecret'] ?? null);
				$candidate = ($pending ?? ($account['mfaSecret'] ?? null));
				if ($this->mfa->verifyCode(encryptedSecret: $candidate, code: $this->strParam(name: 'code')) === false) {
					return [['errorCode' => 'invalidMfaCode', 'message' => 'Ongeldige code.'], Http::STATUS_BAD_REQUEST];
				}

				                                                                
				if ($pending !== null && $pending !== '') {
					$account['mfaSecret'] = $pending;
				}

				unset($account['mfaPendingSecret']);
				$account['mfaEnabled'] = true;
				$this->repository->save(self::ACCOUNT_SCHEMA, $account, $ctx['accountId']);
				return [['status' => 'mfa-enabled'], Http::STATUS_OK];
			}
		);
	}                 
}           
