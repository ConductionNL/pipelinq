<?php

   
                               
  
                                                                      
                                                                             
                                       
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosStaffService;
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

   
                                                                
  
                       
                                                                   
                                                                         
                                                                          
                                                                            
                                                                           
                                                                        
                                                                   
  
                                                  
  
                                                              
   
class PosStaffController extends Controller {
	   
                
   
                                         
                                                          
                                                      
                                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private PosStaffService $service,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                               
   
                                                                                                                                                
   
                                        
   
                                                                 
    
	public function index(): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		return $this->run(
			action: fn (): array => ['staff' => $this->service->listStaff()],
			label: 'index'
		);
	}             

	   
                                                                           
   
                                     
   
                                          
   
                                                                        
                                                                 
                               
   
                                                                 
    
	public function show(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';

		                                                  
		$this->service->authorizeStaff(staffId: $id, userId: $uid);

		return $this->run(
			action: fn (): array => ['staff' => $this->service->getStaff(id: $id)],
			label: 'show'
		);
	}            

	   
                                       
   
                                                                                                                                                  
   
                                                  
   
                                                                 
    
	public function create(): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		return $this->run(
			action: fn (): array => ['staff' => $this->service->saveStaff(data: $this->payload())],
			label: 'create'
		);
	}              

	   
                                       
   
                                     
   
                                                  
   
                                                                         
                                                                         
                               
   
                                                                 
    
	public function update(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';

		                                                  
		$this->service->authorizeStaff(staffId: $id, userId: $uid);

		return $this->run(
			action: fn (): array => ['staff' => $this->service->saveStaff(data: $this->payload(), id: $id)],
			label: 'update'
		);
	}              

	   
                                       
   
                                     
   
                                                  
   
                                                                      
                                                                    
                               
   
                                                                 
    
	public function destroy(string $id): JSONResponse {
		$forbidden = $this->requireAdmin();
		if ($forbidden instanceof JSONResponse) {
			return $forbidden;
		}

		$uid = $this->userSession->getUser()?->getUID() ?? '';

		                                                  
		$this->service->authorizeStaff(staffId: $id, userId: $uid);

		return $this->run(
			action: function () use ($id): array {
				$this->service->deleteStaff(id: $id);
				return ['deleted' => true];
			},
			label: 'destroy'
		);
	}               

	   
                                                                  
   
                                                                           
                                                                         
                                                                        
                                                 
   
                                                                                              
   
                                                                 
    
	#[NoAdminRequired]
	public function authenticate(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$staffId = (string)$this->request->getParam('staffId', '');
		$pin = (string)$this->request->getParam('pin', '');

		if ($staffId === '' || $pin === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('Staff and PIN are required')],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $this->run(
			action: fn (): array => ['session' => $this->service->validatePin(staffId: $staffId, pin: $pin)],
			label: 'authenticate'
		);
	}                    

	   
                                                 
   
                                                     
    
	private function payload(): array {
		$payload = [
			'displayName' => (string)$this->request->getParam('displayName', ''),
			'userId' => (string)$this->request->getParam('userId', ''),
			'posRole' => (string)$this->request->getParam('posRole', ''),
			'isActive' => (bool)$this->request->getParam('isActive', true),
			'pin' => (string)$this->request->getParam('pin', ''),
		];

		return $payload;
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
			$this->logger->error('PosStaffController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
