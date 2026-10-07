<?php

   
                                  
  
                                                                                
                                                                              
                                                                        
                                                                            
                                                                                 
                                                                               
                                
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                                
  
                                                                                   
                                                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalAdminController extends Controller {
	   
                
   
                                         
                                                          
                                                       
                                                                           
                                                      
                                                         
                                              
                                                                                 
    
	public function __construct(
		IRequest $request,
		private PortalTenantService $tenant,
		private PortalAuditService $audit,
		private PortalObjectRepository $repository,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private PortalServiceAccount $serviceAccount,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                                 
   
                                                                                                                                               
   
                                                             
                                                                                         
                                                                            
                                                                            
                               
    
	public function getConfig(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				return [$this->tenant->getAdminConfig(tenantId: $this->tenantId()), Http::STATUS_OK];
			}
		);
	}                 

	   
                                            
   
                                                                                                                       
   
                                                       
                                                                                         
                                                                            
                                                                            
                               
    
	public function saveConfig(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				$tenantId = $this->tenantId();
				$config = $this->request->getParam('config', []);
				if (is_array($config) === false) {
					return [['errorCode' => 'badRequest', 'message' => 'Ongeldige configuratie.'], Http::STATUS_BAD_REQUEST];
				}

				$saved = $this->tenant->saveConfig($tenantId, $config);
				return [$saved, Http::STATUS_OK];
			}
		);
	}                  

	   
                                                       
   
                                                                                                                             
   
                                      
                                                                                         
                                                                            
                                                                            
                               
    
	public function accounts(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				$accounts = $this->repository->findAll('crmPortalAccount', ['tenantId' => $this->tenantId()]);
				$safe = array_map(
					static fn (array $account): array => [
						'id' => ($account['@self']['id'] ?? $account['id'] ?? null),
						'email' => ($account['email'] ?? null),
						'displayName' => ($account['displayName'] ?? null),
						'accountType' => ($account['accountType'] ?? null),
						'status' => ($account['status'] ?? null),
						'lastLoginAt' => ($account['lastLoginAt'] ?? null),
					],
					$accounts
				);
				return [['accounts' => $safe], Http::STATUS_OK];
			}
		);
	}                

	   
                                             
   
                                                                                                                                          
   
                                    
                                                                                         
                                                                            
                                                                            
                               
    
	public function auditEvents(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				return [['events' => $this->audit->getForTenant(tenantId: $this->tenantId())], Http::STATUS_OK];
			}
		);
	}                   

	   
                                                                           
   
                                 
    
	private function tenantId(): string {
		$value = $this->request->getParam('tenantId', PortalTenantService::DEFAULT_TENANT);
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return PortalTenantService::DEFAULT_TENANT;
	}                

	   
                                                                   
   
                                                                                                                            
   
                                                           
                                                                                         
                                                                            
                                                                            
                               
    
	public function getServiceAccount(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				return [$this->serviceAccountBody(status: $this->serviceAccount->status()), Http::STATUS_OK];
			}
		);
	}                         

	   
                                                                           
                                   
   
                                                                                                                               
   
                                                                                
                                                                                         
                                                                            
                                                                            
                               
    
	public function saveServiceAccount(): JSONResponse {
		return $this->adminGuarded(
			handler: function (): array {
				try {
					$status = $this->serviceAccount->assign(userId: (string)$this->request->getParam('userId', ''));
				} catch (\InvalidArgumentException $e) {
					return [['errorCode' => 'badRequest', 'message' => $e->getMessage()], Http::STATUS_BAD_REQUEST];
				}

				return [$this->serviceAccountBody(status: $status), Http::STATUS_OK];
			}
		);
	}                          

	   
                                                            
   
                                                                                       
   
                                                   
    
	private function serviceAccountBody(array $status): array {
		return array_merge($status, ['group' => PortalServiceAccount::GROUP]);
	}                          

	   
                                                                            
   
                                                                      
   
                                      
    
	private function adminGuarded(callable $handler): JSONResponse {
		try {
			$this->assertAdmin();
			[$body, $status] = $handler();
			return new JSONResponse($body, $status);
		} catch (PortalException $e) {
			return new JSONResponse($e->toBody(), $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq portal admin: unhandled error', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['errorCode' => 'serverError', 'message' => 'Er is een fout opgetreden.'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}                    

	   
                                                  
   
                
   
                                              
    
	private function assertAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			throw new PortalException(status: Http::STATUS_FORBIDDEN, errorCode: 'forbidden', message: 'Geen toegang.');
		}
	}                   
}           
