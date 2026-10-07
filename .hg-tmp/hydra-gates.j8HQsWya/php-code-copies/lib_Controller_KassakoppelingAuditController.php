<?php

   
                                          
  
                                                                         
                                                                       
                                                                         
                                                                  
                                                                           
                                                                        
  
                                                                          
                                                              
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                             
  
                    
  
                                                                             
                                                                             
                                                                                 
                                                                             
                                                                             
  
                                                                           
                                                      
  
                                                                         
                                                                           
                         
  
                                                               
   
class KassakoppelingAuditController extends Controller {
	   
                
   
                                         
                                                                 
                                                      
                                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private KassakoppelingAuditService $service,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                             
   
                                                                        
                                                                        
                                                                          
                                                                 
   
                                                          
   
                                                                
    
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$data = [
			'operatorId' => (string)$this->request->getParam('operatorId', $uid),
			'registerNumber' => (string)$this->request->getParam('registerNumber', ''),
			'action' => (string)$this->request->getParam('action', ''),
			'amount' => $this->request->getParam('amount', 0),
			'itemCount' => $this->request->getParam('itemCount', null),
			'taxAmount' => $this->request->getParam('taxAmount', null),
			'timestamp' => (string)$this->request->getParam('timestamp', ''),
			'transactionUuid' => $this->request->getParam('transactionUuid', null),
			'description' => $this->request->getParam('description', null),
		];

		return $this->run(
			action: fn (): array => ['entry' => $this->service->createEntry(data: $data)],
			label: 'create',
			successStatus: Http::STATUS_CREATED
		);
	}              

	   
                       
   
                                                                   
                                              
   
                                                     
   
                                                                
    
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$filters = [
			'registerNumber' => (string)$this->request->getParam('registerNumber', ''),
			'operatorId' => (string)$this->request->getParam('operatorId', ''),
			'action' => (string)$this->request->getParam('action', ''),
			'from' => (string)$this->request->getParam('from', ''),
			'to' => (string)$this->request->getParam('to', ''),
		];

		return $this->run(
			action: fn (): array => ['entries' => $this->service->listEntries(filters: $filters)],
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
			action: fn (): array => ['entry' => $this->service->getEntry(id: $id)],
			label: 'show'
		);
	}            

	   
                                                
   
                                                         
   
                                                              
   
                                                                
    
	#[NoAdminRequired]
	public function verify(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->verifyEntry(id: $id),
			label: 'verify'
		);
	}              

	   
                                                              
   
                                                                         
                                                                  
                                                                            
                                                                           
                                                                        
                                                                          
                                                
                                                                
                                      
   
                                                                          
                                                                           
                                   
   
                                                                      
   
                                                                
    
	public function export(): DataDownloadResponse|JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		if ($this->groupManager->isAdmin($uid) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Alleen administrators kunnen exporteren naar de Belastingdienst.')],
				Http::STATUS_FORBIDDEN
			);
		}

		$fromDate = (string)$this->request->getParam('from', '');
		$toDate = (string)$this->request->getParam('to', '');
		$format = (string)$this->request->getParam('format', 'xml');
		if ($fromDate === '' || $toDate === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('Parameters "from" en "to" zijn verplicht (YYYY-MM-DD).')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$payload = $this->service->exportForBelastingdienst(
				fromDate: $fromDate,
				toDate: $toDate,
				format: $format
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('KassakoppelingAuditController::export failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('Belastingdienst export mislukt.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new DataDownloadResponse(
			(string)$payload['body'],
			(string)$payload['filename'],
			(string)$payload['contentType']
		);
	}              

	   
                                                       
   
                                                              
   
                                                                
    
	private function requireUserId(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authenticatie vereist.')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $user->getUID();
	}                     

	   
                                                           
   
                                                                   
                                                                        
                          
   
                                              
                                                       
                                                                    
   
                                      
    
	private function run(callable $action, string $label, int $successStatus = Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($action(), $successStatus);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error(
				'KassakoppelingAuditController::' . $label . ' failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => $this->l10n->t('Onverwachte fout in de audit log.')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
