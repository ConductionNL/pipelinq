<?php

   
                          
  
                                                                     
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ConnectionReportService;
use OCA\Pipelinq\Service\CtiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                  
  
             
                                                                                
                                                                   
                                                                   
                                                                      
                                                                      
                                                      
                                                      
                                                      
                                                      
  
                                                                             
                                                                          
  
                                                                 
   
class CtiController extends Controller {
	   
                
   
                                              
                                                  
                                                      
                                                                          
                                                         
                                              
                                                                                                              
    
	public function __construct(
		IRequest $request,
		private CtiService $ctiService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private ConnectionReportService $connectionReports,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                            
   
                                                                  
                                                                     
   
                                                                          
                                                                    
   
                                                             
   
                                         
   
                                                                            
                                                                           
   
                                                                  
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function webhook(string $platform): JSONResponse {
		$rawBody = (string)file_get_contents('php://input');
		$signature = (string)$this->request->getHeader('X-Pipelinq-Signature');
		if ($signature === '') {
			$signature = (string)$this->request->getHeader('Validation-Token');
		}

		if ($signature === '') {
			$signature = (string)($this->request->getParam('signature', '') ?? '');
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			$payload = (array)$this->request->getParams();
		}

		$signatureArg = null;
		if ($signature !== '') {
			$signatureArg = $signature;
		}

		try {
			$result = $this->ctiService->handleWebhook(
				platform: $platform,
				payload: $payload,
				rawBody: $rawBody,
				signature: $signatureArg,
			);
		} catch (\InvalidArgumentException $e) {
			                                                                  
			                                                                  
			                                                                   
			$this->logger->warning(
				'CTI webhook: unparseable payload',
				['platform' => $platform, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'The webhook payload could not be parsed'],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\RuntimeException $e) {
			$this->logger->warning(
				'CTI webhook: unknown platform',
				['platform' => $platform, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($result['valid'] === false) {
			                                                                  
			                                                                       
			return new JSONResponse(
				['error' => 'Invalid webhook signature', 'logged' => true],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return new JSONResponse(
			[
				'ok' => true,
				'interactionId' => ($result['interactionId'] ?? null),
			]
		);
	}               

	   
                                
   
                                               
   
                                                                  
    
	#[NoAdminRequired]
	public function screenPop(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                        
		                                                                   
		                             
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$fromNumber = (string)$this->request->getParam('fromNumber', '');
		if ($fromNumber === '') {
			return new JSONResponse(['error' => 'fromNumber is required'], Http::STATUS_BAD_REQUEST);
		}

		$result = $this->ctiService->initiateScreenPop($fromNumber);
		return new JSONResponse($result->toArray());
	}                 

	   
                                       
   
                                              
   
                                                                  
    
	#[NoAdminRequired]
	public function clickToDial(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                        
		                                                                       
		                                                                      
		           
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$targetNumber = (string)$this->request->getParam('targetNumber', '');
		$extension = (string)$this->request->getParam('extension', '');
		if ($targetNumber === '' || $extension === '') {
			return new JSONResponse(
				['error' => 'targetNumber and extension are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		                               
		  
		                                                                    
		                                                             
		                                                                        
		                                                                     
		                                                       
		  
		                                                                      
		                                                                        
		                                                           
		if (preg_match('/^\+?[0-9][0-9 ()-]{1,20}$/', $targetNumber) !== 1) {
			return new JSONResponse(
				['error' => 'targetNumber is not a dialable number'],
				Http::STATUS_BAD_REQUEST
			);
		}

		if (preg_match('/^[0-9]{1,10}$/', $extension) !== 1) {
			return new JSONResponse(
				['error' => 'extension is not a dialable extension'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->ctiService->originateCall(
			userId: $user->getUID(),
			extension: $extension,
			targetNumber: $targetNumber,
		);

		$status = Http::STATUS_BAD_GATEWAY;
		if ($result->success === true) {
			$status = Http::STATUS_OK;
		}

		return new JSONResponse($result->toArray(), $status);
	}                   

	   
                              
   
                                         
   
                                                       
   
                                                                  
    
	#[NoAdminRequired]
	public function disposition(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                 
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$subject = (string)$this->request->getParam('subject', '');
		$outcome = (string)$this->request->getParam('outcome', '');
		$notes = (string)$this->request->getParam('notes', '');

		if ($subject === '' || $outcome === '') {
			return new JSONResponse(
				['error' => 'subject and outcome are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->ctiService->processDisposition(
				interactionId: $id,
				subject: $subject,
				outcome: $outcome,
				notes: $notes,
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('CTI disposition failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => 'Disposition processing failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result);
	}                   

	   
                                                 
   
                                                                          
                                                                       
          
   
                                         
   
                                         
   
                                                                  
    
	#[NoAdminRequired]
	public function attachRecording(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		                                                                        
		                                                                   
		                             
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$recordingUrl = (string)$this->request->getParam('recordingUrl', '');
		$expiresAt = (string)$this->request->getParam('expiresAt', '');
		if ($recordingUrl === '') {
			return new JSONResponse(['error' => 'recordingUrl is required'], Http::STATUS_BAD_REQUEST);
		}

		$attached = $this->ctiService->attachRecording($id, $recordingUrl, $expiresAt);
		if ($attached === false) {
			return new JSONResponse(
				['ok' => false, 'error' => 'The recording could not be attached'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['ok' => true]);
	}                       

	   
                                                 
   
                                                                           
                                                                             
                                                                             
                                                                        
                                                                             
                                                                             
   
                                                                                                                                     
   
                                                                     
   
                                                                  
    
	public function getConfig(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$config = $this->ctiService->loadConfig();
		                                  
		unset($config['webhook_secret']);
		return new JSONResponse($config);
	}                 

	   
                                           
   
                                                                 
   
                                                                          
                                                                           
                                                                         
                                                             
   
                                                                            
                                                                          
                          
   
                                                 
   
                                                                  
                                                                                                                                                     
    
	public function updateConfig(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$allowedFields = [
			'platform',
			'api_base_url',
			'auth_method',
			'credentials_ref',
			'screen_pop_enabled',
			'screen_pop_delay_ms',
			'click_to_dial_enabled',
			'default_outbound_caller_id',
			'webhook_secret',
			'default_country_code',
		];

		$payload = [];
		foreach ($allowedFields as $field) {
			$value = $this->request->getParam($field, null);
			if ($value !== null) {
				$payload[$field] = $value;
			}
		}

		$saved = $this->ctiService->saveConfig($payload);
		$this->logger->info(
			'CTI config updated',
			['user' => $user->getUID(), 'fields' => array_keys($payload)]
		);

		if ($saved !== []) {
			$this->connectionReports->reportCtiCheck(outcome: $this->ctiService->testConnection());
		}

		unset($saved['webhook_secret']);
		return new JSONResponse($saved);
	}                    

	   
                                            
   
                                                                                                                                              
   
                                                                          
                                                      
   
                                      
   
                                                                  
                                                                                                                                                     
    
	public function testConnection(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$outcome = $this->ctiService->testConnection();
		$this->connectionReports->reportCtiCheck(outcome: $outcome);

		return new JSONResponse($outcome);
	}                      

	   
                                                
   
                                                                                                                                
   
                                                                  
   
                                                                  
    
	public function eventLog(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'Admin required'], Http::STATUS_FORBIDDEN);
		}

		$filters = array_filter(
			[
				'platform' => (string)$this->request->getParam('platform', ''),
				'event_type' => (string)$this->request->getParam('event_type', ''),
			]
		);

		$limit = (int)$this->request->getParam('limit', 50);
		$offset = (int)$this->request->getParam('offset', 0);
		$events = $this->ctiService->listEventLog($filters, $limit, $offset);

		return new JSONResponse(['events' => $events, 'limit' => $limit, 'offset' => $offset]);
	}                
}           
