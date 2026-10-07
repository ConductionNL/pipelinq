<?php

   
                              
  
                                                                                 
                                                                                 
                                                                          
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
                                                              
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\GiftCardService;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\RedemptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

   
                           
  
                                                  
                                                                            
                                                                             
                                                                             
                                                                            
                                                                           
                                                                      
                                                                                                        
   
class LoyaltyController extends Controller {
	   
                
   
                                         
                                                                     
                                                                 
                                                                       
                                                                  
                                                                           
                                                                          
                                                      
                                     
    
	public function __construct(
		IRequest $request,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private RedemptionService $redemptionService,
		private GiftCardService $giftCardService,
		private LoyaltyProgrammeService $programmeService,
		private ObjectOwnerAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                            
   
                                              
   
                        
   
                                                               
    
	#[NoAdminRequired]
	public function getAccount(string $accountId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Account not found')],
				Http::STATUS_NOT_FOUND
			);
		}

		                                                                         
		unset($account['optInTermsVersion']);

		return new JSONResponse($account);
	}                  

	   
                                          
   
                                              
   
                        
   
                                                               
    
	#[NoAdminRequired]
	public function getAccountHistory(string $accountId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$entries = $this->ledgerService->getLedgerHistory(accountId: $accountId);
		return new JSONResponse(['entries' => $entries]);
	}                         

	   
                                                                             
   
                                                  
                                              
   
                        
   
                                                               
    
	#[NoAdminRequired]
	public function getRedemptionOptions(string $programmeId, string $accountId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$options = $this->redemptionService->getValidRedemptionOptions(
			accountId: $accountId,
			programmeId: $programmeId
		);

		return new JSONResponse(['options' => $options]);
	}                            

	   
                                                         
   
                                              
                                            
   
                        
   
                                                                  
    
	#[NoAdminRequired]
	public function initiateRedemption(string $accountId, string $optionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$redemption = $this->redemptionService->initiateRedemption(
				accountId: $accountId,
				optionId: $optionId
			);
			return new JSONResponse($redemption, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}
	}                          

	   
                                                                                  
   
                                         
   
                        
   
                                                                  
    
	#[NoAdminRequired]
	public function lookupRedemptionCode(string $code): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->redemptionService->validateCode(code: $code);
		return new JSONResponse($result);
	}                            

	   
                                                    
   
                                         
   
                        
   
                                                                                             
    
	#[NoAdminRequired]
	public function useRedemptionCode(string $code): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$validation = $this->redemptionService->validateCode(code: $code);
		if ($validation['valid'] === false) {
			return new JSONResponse(
				['error' => $validation['reason'] ?? 'Invalid code'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$redemption = $validation['redemption'] ?? null;
		if ($redemption === null) {
			return new JSONResponse(['error' => 'Redemption missing'], Http::STATUS_BAD_REQUEST);
		}

		$self = $redemption['@self'] ?? [];
		$selfId = '';
		if (is_array($self) === true) {
			$selfId = ($self['id'] ?? '');
		}

		$redemptionId = (string)$selfId;
		if ($redemptionId === '') {
			$redemptionId = (string)($redemption['uuid'] ?? $redemption['id'] ?? '');
		}

		try {
			$posTransactionId = $this->request->getParam('posTransactionId');
			$posTransactionRef = null;
			if (is_string($posTransactionId) === true) {
				$posTransactionRef = $posTransactionId;
			}

			$updated = $this->redemptionService->markRedemptionUsed(
				redemptionId: $redemptionId,
				posTransactionId: $posTransactionRef
			);
			return new JSONResponse($updated);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}                         

	   
                                                    
   
                        
   
                                                               
    
	#[NoAdminRequired]
	public function lookupGiftCard(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$serial = (string)$this->request->getParam('serial', '');
		$pin = (string)$this->request->getParam('pin', '');
		if ($serial === '' || $pin === '') {
			return new JSONResponse(
				['error' => 'serial and pin are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->giftCardService->validateBySerial(serial: $serial, pin: $pin);
		return new JSONResponse($result);
	}                      

	   
                                              
   
                        
   
                                                                  
    
	#[NoAdminRequired]
	public function redeemGiftCard(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$giftCardId = (string)$this->request->getParam('giftCardId', '');
		$pin = (string)$this->request->getParam('pin', '');
		$amount = (float)$this->request->getParam('amount', 0);
		$posTransactionId = $this->request->getParam('posTransactionId');
		if ($giftCardId === '' || $pin === '' || $amount <= 0) {
			return new JSONResponse(
				['error' => 'giftCardId, pin and amount (>0) are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$posTransactionRef = null;
		if (is_string($posTransactionId) === true) {
			$posTransactionRef = $posTransactionId;
		}

		try {
			$result = $this->giftCardService->redeemGiftCard(
				giftCardId: $giftCardId,
				pin: $pin,
				amount: $amount,
				posTransactionId: $posTransactionRef
			);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}                      

	   
                                                            
   
                                            
   
                        
   
                                                                  
    
	#[NoAdminRequired]
	public function activateGiftCard(string $giftCardId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$posTransactionId = $this->request->getParam('posTransactionId');
		$posTransactionRef = null;
		if (is_string($posTransactionId) === true) {
			$posTransactionRef = $posTransactionId;
		}

		try {
			$card = $this->giftCardService->activateGiftCard(
				giftCardId: $giftCardId,
				posTransactionId: $posTransactionRef
			);
		} catch (Throwable $e) {
			                                                                    
			                                                                 
			                                                                   
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		if ($card === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Gift card not found')],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($card);
	}                        

	   
                                                         
   
                                                                            
                                                                            
                                                                            
                                                                          
                                                                    
   
                                                                   
                                                               
                    
   
                                                                                        
   
                                                                           
                                                                            
                                                                    
                                                                        
                                                                     
   
                                                                                                                                     
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function issueGiftCard(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$initialBalance = (float)$this->request->getParam('initialBalance', 0);
		if ($initialBalance <= 0) {
			return new JSONResponse(
				['error' => $this->l10n->t('initialBalance must be a positive amount')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$programmeIdRaw = $this->request->getParam('programmeId');
		$programmeId = null;
		if (is_string($programmeIdRaw) === true && $programmeIdRaw !== '') {
			$programmeId = $programmeIdRaw;
		}

		$expiryDays = (int)$this->request->getParam('expiryDays', 365);
		if ($expiryDays <= 0) {
			$expiryDays = 365;
		}

		$channel = (string)$this->request->getParam('channel', 'purchased');
		$uitgegevenInRaw = $this->request->getParam('issuedIn');
		$issuedIn = null;
		if (is_string($uitgegevenInRaw) === true && $uitgegevenInRaw !== '') {
			$issuedIn = $uitgegevenInRaw;
		}

		try {
			$result = $this->giftCardService->issueGiftCard(
				programmeId: $programmeId,
				initialBalance: $initialBalance,
				expiryDays: $expiryDays,
				channel: $channel,
				issuedIn: $issuedIn
			);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}                     

	   
                                                                                        
   
                                                  
   
                        
   
                                                                  
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function activateProgramme(string $programmeId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		                                                                     
		                                                                        
		                                                            
		                                                                             
		                                               
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$programme = $this->programmeService->activate(
				programmeId: $programmeId,
				activatedBy: (string)$user->getUID()
			);
			return new JSONResponse($programme);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}                         
}           
