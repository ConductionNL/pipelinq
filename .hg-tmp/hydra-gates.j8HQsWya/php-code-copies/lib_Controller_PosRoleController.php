<?php

   
                              
  
                                                                             
                                                                            
                                                 
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosRoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                          
  
                                                                         
                                                                             
                                                                             
                                                                           
                                              
  
                                                  
  
                                                              
   
class PosRoleController extends Controller {
	   
                
   
                                         
                                                        
                                                      
                                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private PosRoleService $service,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                       
   
                                       
   
                                                                 
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => ['roles' => $this->service->listRoles()],
			label: 'index'
		);
	}             

	   
                          
   
                                    
   
                                  
   
                                                                 
    
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => ['role' => $this->service->getRole(id: $id)],
			label: 'show'
		);
	}            

	   
                                   
   
                                                                                                                            
   
                                          
   
                                                                 
    
	public function create(): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		return $this->run(
			action: fn (): array => ['role' => $this->service->saveRole(data: $this->payload())],
			label: 'create'
		);
	}              

	   
                                   
   
                                    
   
                                          
   
                                                                          
                                                            
   
                                                                 
    
	public function update(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		                                                                     
		                                                                       
		$this->service->getRole(id: $id);

		return $this->run(
			action: fn (): array => ['role' => $this->service->saveRole(data: $this->payload(), id: $id)],
			label: 'update'
		);
	}              

	   
                                   
   
                                    
   
                                                  
   
                                                                         
                                                            
   
                                                                 
    
	public function destroy(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		return $this->run(
			action: function () use ($id): array {
				$this->service->deleteRole(id: $id);
				return ['deleted' => true];
			},
			label: 'destroy'
		);
	}               

	   
                                                
   
                                                     
    
	private function payload(): array {
		return [
			'name' => (string)$this->request->getParam('name', ''),
			'description' => (string)$this->request->getParam('description', ''),
			'canVoid' => (bool)$this->request->getParam('canVoid', false),
			'maxDiscountPercent' => (int)$this->request->getParam('maxDiscountPercent', 0),
			'canRefund' => (bool)$this->request->getParam('canRefund', false),
			'canNoSale' => (bool)$this->request->getParam('canNoSale', false),
		];
	}               

	   
                                  
   
                                                                       
    
	private function requireUserId(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $user->getUID();
	}                     

	   
                                        
   
                                                                                  
    
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Admin privileges required')],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}                    

	   
                                             
   
                                       
                                                       
   
                                      
    
	private function run(callable $action, string $label): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('PosRoleController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
