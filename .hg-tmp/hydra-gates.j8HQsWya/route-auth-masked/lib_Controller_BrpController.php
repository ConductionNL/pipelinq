<?php

   
                          
  
                                                                                        
                                          
  
                       
                                    
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                  
                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\HaalCentraalException;
use OCA\Pipelinq\Service\OptOutService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                              
  
                                                  
                                                                                               
                                                                                           
  
                                                                          
                                                                          
   
class BrpController extends Controller {
	   
                                               
   
                                                                   
   
                          
    
	private const DEFAULT_ALLOWED_GROUPS = [
		'behandelaar-burgerzaken',
		'behandelaar-avg',
	];

	   
                
   
                                         
                                                  
                                                                                
                                                     
                            
                                            
                                                             
                                                   
                                                                
                                                
                                                 
                                                                        
                                          
                                                                             
   
                                                                                           
                                                                     
    
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private BsnValidationService $validation,
		private BrpCacheService $cacheService,
		private HaalCentraalClient $haalCentraal,
		private BsnAuditService $audit,
		private OptOutService $optOut,
		private BrpMutationWebhookListener $webhookListener,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}                   

	   
                                                                 
   
                
                                                                      
                                                   
                                                      
                                                   
                                                
                                                
                                                       
   
                                                                                                 
   
                                                                              
                                                                              
                                                                              
   
                                                                                             
                                                                                            
                                                                            
                                                                                                   
                                                                                              
                                                                              
                                                                                                
                                                                       
    
	#[NoAdminRequired]
	public function lookup(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$actor = $user->getUID();

		                                                              
		                                                                      
		                                                               
		                                                                     
		                                                                       
		                                                             
		                                                       
		if ($this->accessPolicy->isPrivileged(uid: $actor) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$rawBsn = (string)$this->request->getParam('bsn', '');
		$verzoekreden = trim((string)$this->request->getParam('verzoekreden', ''));
		$purposeBinding = trim((string)$this->request->getParam('purposeBinding', ''));
		$basis = trim((string)$this->request->getParam('basis', ''));
		$requestId = (string)$this->request->getParam('linkedRequest', '');
		$contactId = (string)$this->request->getParam('gekoppeldContact', '');
		$vogScreening = (bool)$this->request->getParam('vogScreening', false);

		                                            
		if ($verzoekreden === '' || $purposeBinding === '') {
			return new JSONResponse(
				[
					'errorCode' => 'doelbinding-missing',
					'errorMessage' => $this->l10n->t('Verzoekreden en doelbinding zijn verplicht.'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($basis === '') {
			$basis = $purposeBinding;
		}

		                                        
		$actorRole = $this->resolveActorRole(actor: $actor);
		if ($actorRole === null) {
			$linkedRequest = null;
			if ($requestId !== '') {
				$linkedRequest = $requestId;
			}

			$this->audit->recordLookup(
				actor: $actor,
				rawBsn: $rawBsn,
				verzoekreden: $verzoekreden,
				purposeBinding: $purposeBinding,
				outcome: 'refused-unauthorised',
				action: 'brp-lookup-refused',
				responseCode: 403,
				linkedRequest: $linkedRequest,
				vogScreening: $vogScreening,
			);
			return new JSONResponse(
				[
					'errorCode' => 'unauthorized',
					'errorMessage' => $this->l10n->t('U bent niet bevoegd voor deze lookup.'),
				],
				Http::STATUS_FORBIDDEN
			);
		}        

		                                                                                
		                                                                  
		$validation = $this->validation->validate($rawBsn);
		if ($validation['isFormalValid'] === false) {
			return new JSONResponse(
				[
					'errorCode' => 'invalid-bsn',
					'errorMessage' => $validation['errorMessage'] ?? $this->l10n->t('Ongeldig BSN.'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		                   
		$cached = $this->cacheService->get($rawBsn);
		$person = null;
		$outcome = 'error';
		$responseCode = 0;
		$correlationId = null;
		$responseInCache = false;
		$responseDurationMs = null;

		if ($cached !== null) {
			$person = $cached;
			$responseInCache = true;
			$outcome = 'succeeded';
			$responseCode = 200;
		}

		if ($cached === null) {
			try {
				$requestRef = null;
				if ($requestId !== '') {
					$requestRef = $requestId;
				}

				$remote = $this->haalCentraal->lookupPersoon($rawBsn, $requestRef);
				if ($remote === null) {
					$outcome = 'not-found';
					$responseCode = 404;
				}

				if ($remote !== null) {
					$correlationId = $remote['_correlationId'] ?? null;
					$responseDurationMs = $remote['_responseDurationMs'] ?? null;
					$responseCode = (int)($remote['_responseStatus'] ?? 200);
					unset($remote['_correlationId'], $remote['_responseDurationMs'], $remote['_responseStatus']);

					$remote['bsnHash'] = BsnValidationService::hash($rawBsn);
					$remote['lookupRequestId'] = '';
					                                        
					$remote['gekoppeldContact'] = $contactId;
					$person = $this->cacheService->set($remote);
					$outcome = 'succeeded';

					                              
					$this->optOut->recordFromBrpResponse(
						$rawBsn,
						(string)($person['indicationSecret'] ?? '0')
					);
				}        
			} catch (HaalCentraalException $e) {
				$outcome = 'error';
				if ($e->getStatusCode() === 0) {
					$outcome = 'timeout';
				}

				$responseCode = $e->getStatusCode();
				$correlationId = $e->getCorrelationId();
			} catch (Throwable $e) {
				$this->logger->error(
					'Unexpected BRP lookup failure',
					['error' => $e->getMessage()]
				);
				$outcome = 'error';
				$responseCode = 500;
			}         
		}        

		                                           
		$gekoppeldRequestRef = null;
		if ($requestId !== '') {
			$gekoppeldRequestRef = $requestId;
		}

		$gekoppeldContactRef = null;
		if ($contactId !== '') {
			$gekoppeldContactRef = $contactId;
		}

		$cacheExpiresOn = null;
		if ($person !== null) {
			$cacheExpiresOn = ($person['retentionTo'] ?? null);
		}

		$request = [
			'bsnHash' => BsnValidationService::hash($rawBsn),
			'verzoekreden' => $verzoekreden,
			'purposeBinding' => $purposeBinding,
			'basis' => $basis,
			'requestedBy' => $actor,
			'requestedOnBehalfOf' => $actorRole,
			'requestMoment' => $now->format(DATE_ATOM),
			'linkedRequest' => $gekoppeldRequestRef,
			'gekoppeldContact' => $gekoppeldContactRef,
			'responseStatus' => $outcome,
			'responseMoment' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
			'responseDurationMs' => $responseDurationMs,
			'haalcentraalCorrelationId' => $correlationId,
			'responseContainsSecrecy' => $person !== null && (string)($person['indicationSecret'] ?? '0') === '1',
			'responseInCache' => $responseInCache,
			'cacheExpiresOn' => $cacheExpiresOn,
		];
		$request = array_filter($request, static fn ($v) => $v !== null);
		$requestSaved = $this->saveLookupRequest(request: $request);
		$verzoekUuid = (string)($requestSaved['@self']['id'] ?? $requestSaved['id'] ?? '');

		                                                              
		if ($person !== null && $responseInCache === false && $verzoekUuid !== '') {
			$person['lookupRequestId'] = $verzoekUuid;
			$this->saveBrpPerson(person: $person);
		}

		                            
		$this->audit->recordLookup(
			actor: $actor,
			rawBsn: $rawBsn,
			verzoekreden: $verzoekreden,
			purposeBinding: $purposeBinding,
			outcome: $outcome,
			responseCode: $responseCode,
			haalcentraalCorrelationId: $correlationId,
			linkedRequest: $gekoppeldRequestRef,
			actorRole: $actorRole,
			vogScreening: $vogScreening,
		);

		                                   
		if ($person !== null && $contactId !== '') {
			$this->stampContactWithVerifiedBsn(
				contactId: $contactId,
				brpPersonId: (string)($person['@self']['id'] ?? $person['id'] ?? ''),
				secrecy: ((string)($person['indicationSecret'] ?? '0')) === '1',
			);
		}

		                                                                  
		if ($person !== null) {
			unset($person['bsn']);
		}

		if ($outcome === 'not-found') {
			return new JSONResponse(
				[
					'errorCode' => 'not-found',
					'errorMessage' => $this->l10n->t('BSN niet aangetroffen in BRP — controleer invoer.'),
					'responseInCache' => false,
					'lookupRequestId' => $verzoekUuid,
				],
				Http::STATUS_NOT_FOUND
			);
		}

		if ($outcome === 'error' || $outcome === 'timeout') {
			return new JSONResponse(
				[
					'errorCode' => 'brp-unavailable',
					'errorMessage' => $this->l10n->t('BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.'),
					'responseInCache' => false,
					'lookupRequestId' => $verzoekUuid,
				],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(
			[
				'persoon' => $person,
				'responseInCache' => $responseInCache,
				'lookupRequestId' => $verzoekUuid,
			],
			Http::STATUS_OK
		);
	}              

	   
                                                                            
                              
   
                                   
   
                        
   
                                                                              
    
	#[NoAdminRequired]
	public function revealAddress(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$actor = $user->getUID();
		if ($this->resolveActorRole(actor: $actor) === null) {
			return new JSONResponse(
				[
					'errorCode' => 'unauthorized',
					'errorMessage' => $this->l10n->t('U bent niet bevoegd voor deze actie.'),
				],
				Http::STATUS_FORBIDDEN
			);
		}

		                                                                                   
		                                                                       
		$person = $this->findLatestPersonForContact(contactId: $id);
		if ($person === null) {
			return new JSONResponse(
				[
					'errorCode' => 'not-found',
					'errorMessage' => $this->l10n->t('Geen BRP-persoon gekoppeld aan dit contact.'),
				],
				Http::STATUS_NOT_FOUND
			);
		}

		                                                                           
		                                                                        
		                                                                      
		                                                                         
		                          
		$auditId = $this->audit->recordLookup(
			actor: $actor,
			rawBsn: '',
			verzoekreden: 'Adres onthuld op behandelaarsverantwoording',
			purposeBinding: 'Wet BRP art. 3.3 (uitzondering geheimhouding)',
			outcome: 'address-revealed',
			action: 'brp-address-revealed',
			responseCode: 200,
			linkedRequest: null,
			actorRole: $this->resolveActorRole(actor: $actor),
			bsnHash: (string)($person['bsnHash'] ?? ''),
		);

		                                
		  
		                                                                        
		                                                                      
		                                                                        
		                                                                     
		                                                                
		                                                         
		if ($auditId === '') {
			return new JSONResponse(
				[
					'error' => $this->l10n->t('Address reveal could not be recorded'),
					'errorMessage' => $this->l10n->t(
						'De onthulling kon niet worden vastgelegd in het BSN-auditlogboek. '
						. 'Het adres is daarom niet getoond.'
					),
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(
			[
				'residence' => $person['residence'] ?? null,
			],
			Http::STATUS_OK
		);
	}                     

	   
                                                                   
   
                        
   
                                                                              
    
	#[NoAdminRequired]
	public function optOutCreate(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$actor = $user->getUID();
		if ($this->resolveActorRole(actor: $actor) === null) {
			return new JSONResponse(
				[
					'errorCode' => 'unauthorized',
					'errorMessage' => $this->l10n->t('U bent niet bevoegd voor deze actie.'),
				],
				Http::STATUS_FORBIDDEN
			);
		}

		$rawBsn = (string)$this->request->getParam('bsn', '');
		$note = $this->request->getParam('note');
		$validation = $this->validation->validate($rawBsn);
		if ($validation['isFormalValid'] === false) {
			return new JSONResponse(
				[
					'errorCode' => 'invalid-bsn',
					'errorMessage' => $validation['errorMessage'] ?? $this->l10n->t('Ongeldig BSN.'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$noteValue = null;
		if (is_string($note) === true) {
			$noteValue = $note;
		}

		$recorded = $this->optOut->recordLocalOptOut($rawBsn, $actor, $noteValue);
		if ($recorded === false) {
			return new JSONResponse(
				[
					'errorCode' => 'internal',
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['result' => 'ok'], Http::STATUS_CREATED);
	}                    

	   
                                                                     
   
                                                                          
   
                                                                            
                                                                            
                                                     
   
                        
   
                                                                              
    
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function mutationWebhook(): JSONResponse {
		$rawBody = (string)file_get_contents('php://input');
		$signature = (string)$this->request->getHeader('X-Signature');
		if ($signature === '') {
			$signature = (string)$this->request->getHeader('x-signature');
		}

		                                                           
		  
		                                                                       
		                                                                     
		                                                                
		                                                                       
		                                                                       
		                                                                      
		                                                                      
		                                                                     
		               
		  
		                                                                       
		                                                                       
		                                                                       
		                   
		$outcome = $this->webhookListener->handle($rawBody, $signature);

		$status = Http::STATUS_OK;
		if ($outcome['result'] === BrpMutationWebhookListener::RESULT_FORBIDDEN) {
			$status = Http::STATUS_FORBIDDEN;
		}

		if ($outcome['result'] === BrpMutationWebhookListener::RESULT_BAD_REQUEST) {
			$status = Http::STATUS_BAD_REQUEST;
		}

		return new JSONResponse(
			[
				'result' => $outcome['result'],
				'invalidated' => $outcome['invalidated'],
			],
			$status
		);
	}                       

	   
                                                                                
   
                                                                        
   
                        
   
                                                                           
    
	#[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
	public function monitor(): JSONResponse {
		                                                                       
		                                              
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$reportRaw = $this->appConfig->getValueString(Application::APP_ID, 'brp.monitor_report', '');
		$certRaw = $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_health', '');

		$report = null;
		if ($reportRaw !== '') {
			$report = json_decode($reportRaw, true);
		}

		$cert = null;
		if ($certRaw !== '') {
			$cert = json_decode($certRaw, true);
		}

		$reportPayload = null;
		if (is_array($report) === true) {
			$reportPayload = $report;
		}

		$certPayload = null;
		if (is_array($cert) === true) {
			$certPayload = $cert;
		}

		return new JSONResponse(
			[
				'report' => $reportPayload,
				'cert' => $certPayload,
			],
			Http::STATUS_OK
		);
	}               

	   
                                               
   
                                                                                  
                                                       
   
                                  
   
                                                             
    
	private function resolveActorRole(string $actor): ?string {
		if ($this->groupManager->isAdmin($actor) === true) {
			return 'beheerder';
		}

		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'brp.allowed_groups',
			''
		);
		$allowed = self::DEFAULT_ALLOWED_GROUPS;
		if ($configured !== '') {
			$allowed = array_filter(array_map('trim', explode(',', $configured)));
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		foreach ($allowed as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group !== null && $group->inGroup($user) === true) {
				return $groupId;
			}
		}

		return null;
	}                       

	   
                                      
   
                                                                              
   
                               
    
	private function saveLookupRequest(array $request): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpLookupVerzoek_schema', '');
		if ($register === '' || $schema === '') {
			return $request;
		}

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $request,
				extend: [],
				register: $register,
				schema: $schema,
			);
			if (is_array($saved) === true) {
				return $saved;
			}

			if (method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			return $request;
		} catch (Throwable $e) {
			$this->logger->error('lookupVerzoek save failed', ['error' => $e->getMessage()]);
			return $request;
		}
	}                         

	   
                                
   
                                                                          
   
                               
    
	private function saveBrpPerson(array $person): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
		$uuid = (string)($person['@self']['id'] ?? $person['id'] ?? '');
		if ($register === '' || $schema === '') {
			return $person;
		}

		$uuidRef = null;
		if ($uuid !== '') {
			$uuidRef = $uuid;
		}

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $person,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuidRef,
			);
			if (is_array($saved) === true) {
				return $saved;
			}

			if (method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			return $person;
		} catch (Throwable $e) {
			$this->logger->error('brpPersoon save failed', ['error' => $e->getMessage()]);
			return $person;
		}         
	}                      

	   
                                                                           
   
                                          
                                               
                                                     
   
                
    
	private function stampContactWithVerifiedBsn(string $contactId, string $brpPersonId, bool $secrecy): void {
		if ($contactId === '' || $brpPersonId === '') {
			return;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$existing = $this->getObjectService()->find(
				id: $contactId,
				register: $register,
				schema: $schema,
			);
			$existingArr = [];
			if (is_array($existing) === true) {
				$existingArr = $existing;
			} elseif (method_exists($existing, 'jsonSerialize') === true) {
				$existingArr = (array)$existing->jsonSerialize();
			}

			if (empty($existingArr) === true) {
				return;
			}

			$existingArr['verifiedBSN'] = true;
			$existingArr['brpPersonId'] = $brpPersonId;
			$existingArr['secrecy'] = $secrecy;
			$this->getObjectService()->saveObject(
				object: $existingArr,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $contactId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'contact stamp with verifiedBSN failed',
				['contactId' => $contactId, 'error' => $e->getMessage()]
			);
		}         
	}                                   

	   
                                                                  
   
                                          
   
                                    
    
	private function findLatestPersonForContact(string $contactId): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'gekoppeldContact' => $contactId,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
			$latest = null;
			foreach (($results ?? []) as $object) {
				$arr = [];
				if (is_array($object) === true) {
					$arr = $object;
				} elseif (method_exists($object, 'jsonSerialize') === true) {
					$arr = (array)$object->jsonSerialize();
				}

				if ($latest === null || (string)($arr['fetchedOn'] ?? '') > (string)($latest['fetchedOn'] ?? '')) {
					$latest = $arr;
				}
			}

			return $latest;
		} catch (Throwable $e) {
			$this->logger->error('findLatestPersoonForContact failed', ['error' => $e->getMessage()]);
			return null;
		}         
	}                                   

	   
                          
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
