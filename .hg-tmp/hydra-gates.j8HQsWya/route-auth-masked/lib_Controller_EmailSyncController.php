<?php

   
                                
  
                                                                              
                                                                             
                                                                             
                                                                         
                                                                             
                                 
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\EmailMatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                           
                       
  
                                                   
   
class EmailSyncController extends Controller {
	   
                
   
                                         
                                                                 
                                                  
                                            
                                          
    
	public function __construct(
		IRequest $request,
		private EmailMatchService $emailMatchService,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}                   

	   
                                                
   
                        
   
                    
   
                                                    
    
	public function getSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$settings = $this->emailMatchService->getSettings(userId: $user->getUID());
			return new JSONResponse($this->responseShape(settings: $settings));
		} catch (Throwable $e) {
			$this->logger->error('EmailSyncController::getSettings failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}                   

	   
                                                 
   
                        
   
                    
   
                                                    
    
	public function saveSettings(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$accountParam = $this->request->getParam('account');
		if ($accountParam === null || is_numeric($accountParam) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid account')], Http::STATUS_BAD_REQUEST);
		}

		$account = (int)$accountParam;
		if ($account < 0) {
			return new JSONResponse(['error' => $this->l10n->t('Invalid account')], Http::STATUS_BAD_REQUEST);
		}

		$enabled = (bool)$this->request->getParam('enabled', false);
		$excluded = $this->request->getParam('excludedAddresses', []);

		try {
			$current = $this->emailMatchService->getSettings(userId: $user->getUID());
			$this->emailMatchService->writeSettings(
				userId: $user->getUID(),
				settings: [
					'account' => $account,
					'enabled' => $enabled,
					'excludedAddresses' => $excluded,
					'cursor' => $current['cursor'],
				]
			);
			$settings = $this->emailMatchService->getSettings(userId: $user->getUID());
			return new JSONResponse($this->responseShape(settings: $settings));
		} catch (Throwable $e) {
			$this->logger->error('EmailSyncController::saveSettings failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         

	}                    

	   
                                                   
   
                        
   
                    
   
                                                    
    
	public function trigger(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$result = $this->emailMatchService->runForUser(userId: $user->getUID());
			return new JSONResponse(
				[
					'linked' => (int)$result['linked'],
					'scanned' => (int)$result['scanned'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error('EmailSyncController::trigger failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}               

	   
                                              
   
                        
   
                    
   
                                                    
    
	public function getStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$status = $this->emailMatchService->getStatus(userId: $user->getUID());
			return new JSONResponse($status);
		} catch (Throwable $e) {
			$this->logger->error('EmailSyncController::getStatus failed', ['exception' => $e]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

	}                 

	   
                                                                 
   
                                                                
   
                                                                 
   
                                                                               
    
	private function responseShape(array $settings): array {
		$excludedAddresses = [];
		if (is_array($settings['excludedAddresses'] ?? null) === true) {
			$excludedAddresses = array_values($settings['excludedAddresses']);
		}

		return [
			'account' => (int)($settings['account'] ?? 0),
			'enabled' => (bool)($settings['enabled'] ?? false),
			'excludedAddresses' => $excludedAddresses,
		];

	}                     
}           
