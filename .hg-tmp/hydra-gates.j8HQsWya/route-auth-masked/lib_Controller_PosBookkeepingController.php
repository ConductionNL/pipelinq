<?php

   
                                     
  
                                                                 
                                                                        
                                                                 
                                                                         
                                                                               
                                                                          
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                        
  
                                                                            
                                                                            
                                                                            
                                               
  
                                                  
  
                                                                      
   
class PosBookkeepingController extends Controller {
	   
                
   
                                         
                                                                  
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private PosBookkeepingService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                       
   
                                                                       
                                                                
                                                                        
                                                                       
                                 
   
                                                                      
   
                                                                                                                       
    
	#[NoAdminRequired]
	public function post(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$zReportId = (string)$this->request->getParam('zReportId', '');
		if ($zReportId === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('zReportId is required')],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->run(
			action: fn (): array => [
				'zReport' => $this->service->raiseJournalEntry(
					zReportId: $zReportId,
					userId: $uid
				),
			],
			label: 'post'
		);
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

	   
                                                      
   
                                                           
                                                                        
   
                                              
                                                       
   
                                      
    
	private function run(callable $action, string $label): JSONResponse {
		try {
			return new JSONResponse($action(), Http::STATUS_ACCEPTED);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('PosBookkeepingController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
