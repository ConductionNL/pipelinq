<?php

   
                                
  
                                                                          
                                                                            
                                                                          
                                                                             
                                                                             
                                                       
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

   
                                 
  
             
                                                                                   
                                                                                   
                                                                                   
                                                                       
  
                                                                            
                          
  
                                                                                                   
   
class MessagingController extends Controller {
	   
                
   
                                          
                                                                         
                                                                      
                                                          
                                                  
                                                                          
    
	public function __construct(
		IRequest $request,
		private MessagingService $messagingService,
		private ChannelProviderRepository $providerRepo,
		private ConsentService $consentService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                         
   
                                                 
                                               
                                                                      
                                                                             
                                                                         
                                                            
                                                                 
                                                                                  
   
                                                        
   
                                                                                                    
    
	#[NoAdminRequired]
	public function send(
		string $contactId = '',
		string $channel = '',
		string $body = '',
		?string $templateId = null,
		array $parameters = [],
		?string $providerHint = null,
		string $clientId = '',
		string $inReplyTo = '',
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                 
		                                                                       
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($channel !== 'sms' && $channel !== 'whatsapp') {
			return new JSONResponse(['status' => 'invalid-channel'], Http::STATUS_BAD_REQUEST);
		}

		                                                                    
		                                                                   
		$contact = $this->messagingService->loadContact(contactId: $contactId);
		if ($contact === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$outcome = $this->messagingService->send(
			contact: $contact,
			channel: $channel,
			body: $body,
			templateId: $templateId,
			parameters: $parameters,
			providerHint: $providerHint,
			actor: $user->getUID(),
			clientId: $clientId,
			inReplyTo: $inReplyTo
		);

		return new JSONResponse($outcome, $this->httpStatusForOutcome(status: (string)$outcome['status']));
	}            

	   
                                                          
   
                                                 
   
                                                                                
   
                                                                                                    
    
	#[NoAdminRequired]
	public function preflight(string $contactId = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                
		                                            
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$contact = $this->messagingService->loadContact(contactId: $contactId);
		if ($contact === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->messagingService->preflight(contactId: $contactId));
	}                 

	   
                                                                         
   
                                          
                                               
                                                
                                                               
                                                           
                                                         
   
                                            
   
                                                                                                       
   
                                                                           
                                                                           
                                                       
                                                                            
                                                                         
    
	#[NoAdminRequired]
	public function consent(
		string $contactId = '',
		string $channel = '',
		string $action = '',
		string $source = 'manual',
		string $evidence = '',
		string $legalBasis = '',
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                 
		                                                                       
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($channel !== 'sms' && $channel !== 'whatsapp') {
			return new JSONResponse(['status' => 'invalid-channel'], Http::STATUS_BAD_REQUEST);
		}

		if ($evidence === '' || $legalBasis === '') {
			return new JSONResponse(['status' => 'evidence-and-legal-basis-required'], Http::STATUS_BAD_REQUEST);
		}

		if ($action !== 'opt-in' && $action !== 'opt-out') {
			return new JSONResponse(['status' => 'invalid-action'], Http::STATUS_BAD_REQUEST);
		}

		                                    
		$contact = $this->messagingService->loadContact(contactId: $contactId);
		if ($contact === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$attributedEvidence = sprintf('%s (recorded by %s)', $evidence, $user->getUID());
		$this->recordConsent(
			action: $action,
			contactId: $contactId,
			channel: $channel,
			source: $source,
			evidence: $attributedEvidence,
			legalBasis: $legalBasis
		);

		return new JSONResponse(
			[
				'status' => 'recorded',
				'state' => $this->consentService->latestState(contactId: $contactId, channel: $channel),
			]
		);
	}               

	   
                                                                      
   
                                                                    
                                          
                                               
                                      
                                                      
                                               
   
                
   
                                                                                                       
    
	private function recordConsent(
		string $action,
		string $contactId,
		string $channel,
		string $source,
		string $evidence,
		string $legalBasis,
	): void {
		if ($action === 'opt-in') {
			$this->consentService->recordOptIn(
				contactId: $contactId,
				channel: $channel,
				source: $source,
				evidence: $evidence,
				legalBasis: $legalBasis
			);
			return;
		}

		$this->consentService->recordOptOut(
			contactId: $contactId,
			channel: $channel,
			source: $source,
			evidence: $evidence,
			legalBasis: $legalBasis
		);
	}                     

	   
                                                                   
   
                                    
   
                                                                      
   
                                                                                                               
    
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function testProvider(string $id = ''): JSONResponse {
		$provider = $this->providerRepo->findById(id: $id);
		if ($provider === null) {
			return new JSONResponse(['reachable' => false, 'cause' => 'provider-not-found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->messagingService->runProviderTest(provider: $provider));
	}                    

	   
                                                       
   
                                                       
   
                                 
    
	private function httpStatusForOutcome(string $status): int {
		if ($status === 'sent') {
			return Http::STATUS_OK;
		}

		if ($status === 'failed') {
			return Http::STATUS_BAD_GATEWAY;
		}

		                                                                       
		                                                     
		return Http::STATUS_UNPROCESSABLE_ENTITY;
	}                            
}           
