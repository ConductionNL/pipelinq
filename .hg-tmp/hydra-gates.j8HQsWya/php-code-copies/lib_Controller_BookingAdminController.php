<?php

   
                                   
  
                                                                             
                                                                              
                                                                       
                                                                             
                                                                         
                                                                             
                                                 
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\BookingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                          
  
                                                                            
                                                                              
                                                                              
                                                                           
                                                                            
                                             
  
                                                                         
                                                                
  
                                                   
   
class BookingAdminController extends Controller {
	   
                
   
                                              
                                                                  
                                                                                   
                                                              
                                                                                
                                            
                                              
    
	public function __construct(
		IRequest $request,
		private BookingService $bookings,
		private AppointmentEmailService $emailService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                             
   
                                                                         
                                                                  
                                                  
   
                                                
   
                                                           
   
                                                    
    
	#[NoAdminRequired]
	public function reschedule(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorised();
		}

		                                                                   
		                                                 
		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return $this->error(message: $this->l10n->t('Forbidden'), status: Http::STATUS_FORBIDDEN);
		}

		$newStartAt = (string)$this->request->getParam('newStartAt', '');
		if ($newStartAt === '') {
			return $this->error(message: $this->l10n->t('newStartAt is required'), status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->run(
			label: 'reschedule',
			handler: function () use ($id, $newStartAt): array {
				$newId = $this->bookings->rescheduleBooking(bookingId: $id, newStartAt: $newStartAt);
				return ['bookingId' => $newId];
			}
		);
	}                  

	   
                     
   
                                                                        
                                                                        
   
                                       
   
                                                 
                                                                
    
	#[NoAdminRequired]
	public function cancel(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorised();
		}

		$reason = (string)$this->request->getParam('reason', '');
		return $this->run(
			label: 'cancel',
			handler: function () use ($id, $reason, $uid): array {
				$this->bookings->cancelBooking(bookingId: $id, reason: $reason, cancelledBy: $uid);
				return ['cancelled' => true];
			}
		);
	}              

	   
                                
   
                                       
   
                                                 
   
                                                    
    
	#[NoAdminRequired]
	public function markCompleted(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorised();
		}

		                                                                   
		                                                 
		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return $this->error(message: $this->l10n->t('Forbidden'), status: Http::STATUS_FORBIDDEN);
		}

		return $this->run(
			label: 'markCompleted',
			handler: function () use ($id): array {
				$this->bookings->completeBooking(bookingId: $id);
				return ['completed' => true];
			}
		);
	}                     

	   
                              
   
                                                                     
                                                                        
                  
   
                                       
   
                                                 
                                                    
    
	#[NoAdminRequired]
	public function markNoShow(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorised();
		}

		                                                                   
		                                                                        
		                                                                     
		                                                                  
		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return $this->error(message: $this->l10n->t('Forbidden'), status: Http::STATUS_FORBIDDEN);
		}

		return $this->run(
			label: 'markNoShow',
			handler: function () use ($id, $uid): array {
				$this->bookings->markNoShow(bookingId: $id, staffUserId: $uid);
				return ['markedNoShow' => true];
			}
		);
	}                  

	   
                                              
   
                                                            
                                                                       
                                                                    
                                                                           
   
                                       
   
                                                 
   
                                                    
    
	#[NoAdminRequired]
	public function sendReminder(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorised();
		}

		                                                                   
		                                                 
		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return $this->error(message: $this->l10n->t('Forbidden'), status: Http::STATUS_FORBIDDEN);
		}

		return $this->run(
			label: 'sendReminder',
			handler: function () use ($id): array {
				$sent = $this->emailService->sendReminder(bookingId: $id);
				$refusal = $this->emailService->lastRefusal();
				if ($sent === false && $refusal !== null) {
					                                                              
					return [
						'sent' => false,
						'reason' => $refusal['code'],
						'message' => $this->l10n->t('De herinnering is niet verstuurd: %s', [$refusal['code']]),
					];
				}

				return ['sent' => $sent];
			}
		);
	}                    

	   
                                                                 
   
                                                                    
                                                                       
                                      
   
                                       
   
                                                 
   
                                                    
    
	#[NoAdminRequired]
	public function confirmDeposit(string $id): JSONResponse {
		$uid = $this->requireUser();
		if ($uid === null) {
			return $this->unauthorised();
		}

		                                                                   
		                                                 
		if ($this->accessPolicy->isPrivileged(uid: $uid) === false) {
			return $this->error(message: $this->l10n->t('Forbidden'), status: Http::STATUS_FORBIDDEN);
		}

		$reason = (string)$this->request->getParam('reason', '');
		if ($reason === '') {
			$reason = 'Deposit confirmed by staff';
		}

		return $this->run(
			label: 'confirmDeposit',
			handler: function () use ($id, $reason): array {
				$this->bookings->confirmBooking(bookingId: $id, reason: $reason);
				return ['confirmed' => true];
			}
		);
	}                      

	   
                                                                    
   
                                                                        
                                                                         
                                                                     
   
                                                                          
    
	private function requireUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}                   

	   
                         
   
                                          
    
	private function unauthorised(): JSONResponse {
		return $this->error(message: $this->l10n->t('Authentication required'), status: Http::STATUS_UNAUTHORIZED);
	}                    

	   
                                                      
   
                                                    
                                       
   
                                            
    
	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}             

	   
                                                              
   
                                                        
                                                                       
   
                                      
    
	private function run(string $label, callable $handler): JSONResponse {
		try {
			return new JSONResponse($handler());
		} catch (InvalidArgumentException $e) {
			return $this->error(message: $e->getMessage(), status: Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('BookingAdminController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return $this->error(message: $this->l10n->t('An unexpected error occurred'), status: Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}           
}           
