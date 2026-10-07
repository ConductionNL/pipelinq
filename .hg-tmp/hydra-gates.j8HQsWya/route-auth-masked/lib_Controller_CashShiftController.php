<?php

   
                                
  
                                                                                
                                                                         
                                                                              
                                                                             
                                     
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CashShiftService;
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

   
                                                      
  
                                                                            
                                                                     
                                                                              
                                                                             
                                     
  
                                                  
  
                                                          
   
class CashShiftController extends Controller {
	   
                
   
                                         
                                                            
                                                      
                                                
                                              
    
	public function __construct(
		IRequest $request,
		private CashShiftService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                          
   
                                           
   
                                                           
    
	#[NoAdminRequired]
	public function open(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$drawer = (string)$this->request->getParam('drawer', '');
		$floatAmount = (float)$this->request->getParam('floatAmount', 0);
		$reference = (string)$this->request->getParam('reference', '');
		$notes = (string)$this->request->getParam('notes', '');

		return $this->run(
			action: fn (): array => [
				'shift' => $this->service->openShift(
					drawer: $drawer,
					floatAmount: $floatAmount,
					userId: $uid,
					reference: $reference,
					notes: $notes
				),
			],
			label: 'open'
		);
	}            

	   
                                                
   
                                     
   
                                          
   
                                                           
    
	#[NoAdminRequired]
	public function drop(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$amount = (float)$this->request->getParam('amount', 0);
		$reason = (string)$this->request->getParam('reason', '');

		return $this->run(
			action: fn (): array => [
				'drop' => $this->service->recordDrop(shiftId: $id, amount: $amount, reason: $reason, userId: $uid),
			],
			label: 'drop'
		);
	}            

	   
                                                                             
   
                                     
   
                                                           
   
                                                           
    
	#[NoAdminRequired]
	public function count(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$amount = (float)$this->request->getParam('amount', 0);
		$notes = (string)$this->request->getParam('notes', '');

		return $this->run(
			action: fn (): array => $this->service->recordCount(
				shiftId: $id,
				amount: $amount,
				userId: $uid,
				notes: $notes
			),
			label: 'count'
		);
	}             

	   
                                                                          
   
                                                                                
   
                                           
   
                                                           
    
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$diffId = (string)$this->request->getParam('diffId', $id);

		return $this->run(
			action: fn (): array => [
				'diff' => $this->service->approveDiff(diffId: $diffId, userId: $uid),
			],
			label: 'approve'
		);
	}               

	   
                                                                      
   
                                                                                
   
                                           
   
                                                           
    
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$diffId = (string)$this->request->getParam('diffId', $id);
		$reason = (string)$this->request->getParam('reason', '');

		return $this->run(
			action: fn (): array => [
				'diff' => $this->service->rejectDiff(diffId: $diffId, reason: $reason, userId: $uid),
			],
			label: 'reject'
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
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('CashShiftController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}         
	}           
}           
