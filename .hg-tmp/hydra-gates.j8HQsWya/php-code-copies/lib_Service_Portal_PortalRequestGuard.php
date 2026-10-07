<?php

   
                               
  
                                                                             
                                                                               
                                                                                
                                                                                 
                                                                         
                                                                             
                                                                          
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Http;
use OCP\IRequest;

   
                                                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalRequestGuard {
	   
                             
   
               
    
	private const ACCOUNT_SCHEMA = 'crmPortalAccount';

	   
                
   
                                                                           
                                                              
                                                          
    
	public function __construct(
		private PortalObjectRepository $repository,
		private PortalSessionManager $sessions,
		private PortalTenantService $tenant,
	) {
	}                   

	   
                                                                         
   
                                                                       
                                                                           
                                                                           
   
                                                  
   
                                          
   
                                                                                                   
   
                                                                                                                                               
    
	public function resolveTenant(IRequest $request): string {
		$widgetTenant = $this->headerOrNull(request: $request, name: 'X-Portal-Tenant');

		$tenantId = $this->tenant->resolveTenantId(
			$request->getServerHost(),
			$widgetTenant
		);

		                                                                    
		                                                                        
		                                                                     
		                                                                      
		                                                                       
		                                                                       
		                                                                      
		                                                                   
		                                                                       
		                                               
		if ($widgetTenant !== null && trim($widgetTenant) !== '') {
			$origin = $this->headerOrNull(request: $request, name: 'Origin');
			if ($this->tenant->isWidgetOriginAllowed(tenantId: $tenantId, origin: $origin) === false) {
				throw new PortalException(
					Http::STATUS_FORBIDDEN,
					'originNotAllowed',
					'Deze widget mag niet vanaf deze locatie worden gebruikt.'
				);
			}
		}

		return $tenantId;
	}                     

	   
                                                      
   
                                                                       
                                                                           
               
   
                                                  
   
                                                                                                                    
   
                                                          
                                                                                         
                                                                            
                                                                            
                               
    
	public function authenticate(IRequest $request): array {
		$tenantId = $this->resolveTenant(request: $request);
		$token = $this->bearerToken(request: $request);

		$session = $this->sessions->validateSession(token: $token, tenantId: $tenantId);
		if ($session === null) {
			throw new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.');
		}

		$accountId = (string)($session['accountId'] ?? '');
		$account = $this->repository->find(self::ACCOUNT_SCHEMA, $accountId);
		if ($account === null || ($account['status'] ?? 'active') === 'closed') {
			throw new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.');
		}

		return [
			'account' => $account,
			'accountId' => $accountId,
			'session' => $session,
			'tenantId' => $tenantId,
		];
	}                    

	   
                                                               
   
                                         
   
                               
                                                                                         
                                                                            
                                                                            
                               
    
	public function ipHash(IRequest $request): string {
		return hash('sha256', $request->getRemoteAddress());
	}              

	   
                                              
   
                                         
   
                                       
                                                                                         
                                                                            
                                                                            
                               
    
	public function userAgentHash(IRequest $request): string {
		return hash('sha256', $this->headerOrNull(request: $request, name: 'User-Agent') ?? '');
	}                     

	   
                                                                    
   
                                         
   
                                           
    
	private function bearerToken(IRequest $request): ?string {
		$header = $request->getHeader('Authorization');
		if ($header === '' || stripos($header, 'bearer ') !== 0) {
			return null;
		}

		$token = trim(substr($header, 7));
		if ($token === '') {
			return null;
		}

		return $token;
	}                   

	   
                                              
   
                                         
                                        
   
                                                  
    
	private function headerOrNull(IRequest $request, string $name): ?string {
		$value = $request->getHeader($name);
		if ($value === '') {
			return null;
		}

		return $value;
	}                    
}           
