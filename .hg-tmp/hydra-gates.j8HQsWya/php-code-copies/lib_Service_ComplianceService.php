<?php

   
                              
  
                                                                       
                                                                         
                                                                        
                                                              
                                                                     
                                                                       
                                                                      
                      
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                               
  
                                                  
  
                                                                        
                                                                    
                                                                     
                                                                                
                                                                        
                                                                
                                                                        
                                
  
                                                                                                                                        
                                                                                                                                            
  
                                                                                                       
   
class ComplianceService {
	   
                                                                     
                      
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                                                      
    
	private const DEFAULT_CONSENT_RECORD_SCHEMA_SLUG = 'consentRecord';

	   
                                                                  
                                                            
    
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	   
                                                    
                                                                   
                                                                     
   
                           
    
	private const QUEUED_DELIVERY_STATUSES = ['queued'];

	   
                                                  
                                               
    
	private const STATUS_UNSUBSCRIBED_BEFORE_SEND = 'unsubscribed-before-send';

	   
                                                                 
                                             
    
	private const UNSUBSCRIBE_TOKEN = '{{unsubscribe_link}}';

	   
                                                                 
   
                                                                    
                                                                    
                                                                
                                                 
   
                           
    
	private const LAWFUL_BASIS_ALLOWED = ['consent', 'legitimate-interest', 'contract', self::LAWFUL_BASIS_SOFT_OPT_IN];

	   
                                                                        
                                                                      
                                                                          
                                                          
   
               
    
	public const LAWFUL_BASIS_SOFT_OPT_IN = 'soft-opt-in';

	   
                                                        
   
               
    
	public const INTENT_PROMOTIONAL = 'promotional';

	   
                                                         
   
               
    
	public const INTENT_SERVICE = 'service';

	   
                                                              
   
               
    
	public const REASON_NO_CONSENT = 'no_consent';

	   
                                                        
   
               
    
	public const REASON_SUPPRESSED = 'suppressed_dunning';

	   
                                                                   
                                                                    
                                                             
   
                           
    
	private const LAWFUL_BASIS_UNSATISFYING = ['imported'];

	   
                
   
                                                                        
                                                     
                                                                    
                                          
                                                                                
                                                                                     
   
                                                                                                        
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private SegmentService $segmentService,
		private LoggerInterface $logger,
		private SegmentSignalService $signals,
		private IntegriqMarketingConsent $integriq,
	) {
	}                   

	   
                                                                     
   
                                                                        
                                                                     
                                                                         
                                                                     
                                                                       
                                  
   
                                                  
                                            
                                                     
                                                                      
   
                                                                                
                                                           
   
                                                                                                                                                                 
    
	public function permitsSend(string $contactId, string $channel, string $intent = self::INTENT_PROMOTIONAL, ?string $listId = null): array {
		if ($this->integriq->isActive() === true) {
			                                                                  
			                                                    
			[$category, $requiresConsent] = $this->integriq->categoryFor(intent: $intent);
			$decision = $this->integriq->decide(
				contactId: $contactId,
				channel: strtolower(trim($channel)),
				category: $category,
				requiresConsent: $requiresConsent,
				listId: $listId
			);
			if ($decision['send'] === false || $this->isBounceWithdrawn(contactId: $contactId, channel: $channel, listId: $listId) === true) {
				return ['allowed' => false, 'reason' => self::REASON_NO_CONSENT];
			}

			if ($this->isSuppressed(contactId: $contactId, intent: $intent) === true) {
				return ['allowed' => false, 'reason' => self::REASON_SUPPRESSED];
			}

			return ['allowed' => true, 'reason' => ''];
		}        

		$hasConsent = $this->hasConsentForChannel(contactId: $contactId, channel: $channel);
		if ($listId !== null && $listId !== '') {
			$hasConsent = $this->hasConsentForList(contactId: $contactId, listId: $listId, channel: $channel);
		}

		if ($hasConsent === false) {
			return ['allowed' => false, 'reason' => self::REASON_NO_CONSENT];
		}

		if ($this->isSuppressed(contactId: $contactId, intent: $intent) === true) {
			return ['allowed' => false, 'reason' => self::REASON_SUPPRESSED];
		}

		return ['allowed' => true, 'reason' => ''];
	}                   

	   
                                                             
   
                                                                 
                                                                        
                                                                      
   
                                                  
                                                     
   
                                               
   
                                                                                                                                                                 
    
	public function isSuppressed(string $contactId, string $intent = self::INTENT_PROMOTIONAL): bool {
		if ($intent !== self::INTENT_PROMOTIONAL) {
			return false;
		}

		if ($this->appConfig->getValueBool(Application::APP_ID, 'marketing.suppress_late_payers', true) === false) {
			return false;
		}

		$state = $this->signals->dunningStateForContact(contactId: $contactId);
		if ($state === null) {
			return false;
		}

		return in_array($state, $this->suppressingStates(), true);
	}                    

	   
                                                           
   
                                                                          
    
	private function suppressingStates(): array {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, 'marketing.suppression_states', ''));
		if ($raw === '') {
			return SegmentSignalService::SUPPRESSING_DUNNING_STATES;
		}

		$states = [];
		foreach (explode(',', $raw) as $state) {
			$state = strtolower(trim($state));
			if ($state !== '') {
				$states[] = $state;
			}
		}

		if ($states === []) {
			return SegmentSignalService::SUPPRESSING_DUNNING_STATES;
		}

		return $states;
	}                         

	   
                                                                     
                      
   
                     
   
                                                                  
                                                                       
                                                            
   
                                                             
                                                                      
                                                                     
                                                                      
                                                                    
   
                                                  
                                            
                                                                                            
   
                                                                                                                                               
   
                                                                                                        
                                                                                                                                                                 
    
	public function checkSegmentCompliance(string $segmentId, string $channel, string $intent = self::INTENT_PROMOTIONAL): array {
		$channel = strtolower(trim($channel));
		$members = $this->segmentService->getMembersForBlast(segmentId: $segmentId);
		if ($members === []) {
			return [
				'compliant' => true,
				'missingConsent' => [],
				'missingCount' => 0,
				'suppressed' => [],
				'suppressedCount' => 0,
			];
		}

		if ($this->integriq->isActive() === true) {
			                                                     
			return $this->segmentComplianceViaIntegriq(members: $members, channel: $channel, intent: $intent);
		}

		$missing = [];
		$suppressed = [];
		foreach ($members as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '') {
				                                                          
				                                                 
				$missing[] = '';
				continue;
			}

			if ($this->hasConsentForChannel(contactId: $contactId, channel: $channel) === false) {
				$missing[] = $contactId;
				continue;
			}

			if ($this->isSuppressed(contactId: $contactId, intent: $intent) === true) {
				$suppressed[] = $contactId;
			}
		}

		$missing = array_values(array_unique($missing));
		$suppressed = array_values(array_unique($suppressed));

		                                                             
		                                                                    
		                                                                     
		                                                       
		return [
			'compliant' => ($missing === []),
			'missingConsent' => $missing,
			'missingCount' => count($missing),
			'suppressed' => $suppressed,
			'suppressedCount' => count($suppressed),
		];
	}                              

	   
                                                                                  
   
                                                                           
                                                                   
   
                                                                         
                                                                      
                                                                                
   
                  
                        
                                           
                          
                                       
                             
                                          
                                                                
   
                                                                                                                                                               
    
	private function segmentComplianceViaIntegriq(array $members, string $channel, string $intent): array {
		[$category, $requiresConsent] = $this->integriq->categoryFor(intent: $intent);
		$decisions = $this->integriq->decideMembers(members: $members, channel: $channel, category: $category, requiresConsent: $requiresConsent);

		$missing = [];
		$suppressed = [];
		$unsubscribe = [];
		foreach ($members as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			$decision = ($decisions[$contactId] ?? ['send' => false, 'unsubscribe' => null]);
			if ($contactId === '' || $decision['send'] === false || $this->isBounceWithdrawn(contactId: $contactId, channel: $channel) === true) {
				$missing[] = $contactId;
				continue;
			}

			$unsubscribe[$contactId] = (string)($decision['unsubscribe']['url'] ?? '');
			if ($this->isSuppressed(contactId: $contactId, intent: $intent) === true) {
				$suppressed[] = $contactId;
			}
		}

		$missing = array_values(array_unique($missing));
		$suppressed = array_values(array_unique($suppressed));
		return [
			'compliant' => ($missing === []),
			'missingConsent' => $missing,
			'missingCount' => count($missing),
			'suppressed' => $suppressed,
			'suppressedCount' => count($suppressed),
			'unsubscribe' => array_filter($unsubscribe),
		];
	}                                    

	   
                                                                       
   
                                                           
                                                                   
                                                                   
                                                                       
                                                                        
                                                                      
                                                                  
                                                     
   
                                                                    
                                                                   
                                                         
   
                                                 
                                                                   
                                            
                                                                                            
   
                                                       
                                                                                  
                                                      
   
                                                                                                        
    
	public function preflightBlast(string $segmentId, array $template, string $channel, string $intent = self::INTENT_PROMOTIONAL): array {
		$templateError = $this->validateTemplate(templateData: $template, channel: $channel);
		$segmentCheck = $this->checkSegmentCompliance(segmentId: $segmentId, channel: $channel, intent: $intent);

		$valid = ($templateError === null && $segmentCheck['compliant'] === true);

		return [
			'valid' => $valid,
			'templateError' => $templateError,
			'segmentCompliance' => $segmentCheck,
		];
	}                      

	   
                                                                       
   
                                                                    
                                                             
                                                            
                                                                    
   
                                                 
                                            
   
                                                                      
   
                                                                                                        
    
	public function hasConsentForChannel(string $contactId, string $channel): bool {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $channel === '') {
			return false;
		}

		if ($this->integriq->isActive() === true) {
			$decision = $this->integriq->decide(
				contactId: $contactId,
				channel: $channel,
				category: IntegriqConsentClient::CATEGORY_MARKETING,
				requiresConsent: true
			);
			return ($decision['send'] === true && $this->isBounceWithdrawn(contactId: $contactId, channel: $channel, listId: null) === false);
		}

		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: null);
		return $this->recordPermitsSend(record: $record, contactId: $contactId, channel: $channel);
	}                            

	   
                                                         
   
                                                                         
                                                                        
                                                                        
                                 
   
                                                                         
                                                       
                                                  
                                            
   
                                                                   
   
                                                                                                          
    
	public function hasConsentForList(string $contactId, string $listId, string $channel = 'email'): bool {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $listId === '' || $channel === '') {
			return false;
		}

		if ($this->integriq->isActive() === true) {
			$decision = $this->integriq->decide(
				contactId: $contactId,
				channel: $channel,
				category: IntegriqConsentClient::CATEGORY_MARKETING,
				requiresConsent: true,
				listId: $listId
			);
			return ($decision['send'] === true && $this->isBounceWithdrawn(contactId: $contactId, channel: $channel, listId: $listId) === false);
		}

		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: $listId);
		return $this->recordPermitsSend(record: $record, contactId: $contactId, channel: $channel);
	}                         

	   
                                                                                      
   
                                                      
                                              
                                                                         
   
                                                                               
   
                                                                                                                                                                   
    
	private function isBounceWithdrawn(string $contactId, string $channel, ?string $listId = null): bool {
		$channel = strtolower(trim($channel));
		$records = [$this->findConsentRecord(contactId: $contactId, channel: $channel, listId: null)];
		if ($listId !== null && $listId !== '') {
			$records[] = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: $listId);
		}

		foreach ($records as $record) {
			$withdrawn = trim((string)($record['withdrawnAt'] ?? ''));
			$reason = (string)($record['withdrawnReason'] ?? '');
			if ($withdrawn !== '' && in_array($reason, IntegriqMarketingConsent::BOUNCE_REASONS, true) === true) {
				return true;
			}
		}

		return false;
	}                         

	   
                                                                 
   
                                                                      
                                                                          
                                                                
   
                                                                 
                                                            
                                                       
   
                                                     
   
                                                                                                                       
    
	private function recordPermitsSend(?array $record, string $contactId, string $channel): bool {
		if ($record === null) {
			return false;
		}

		$lawfulBasis = strtolower(trim((string)($record['lawfulBasis'] ?? '')));
		if ($lawfulBasis === '') {
			return false;
		}

		if (in_array($lawfulBasis, self::LAWFUL_BASIS_UNSATISFYING, true) === true) {
			                                                      
			                                                        
			                                                         
			                                
			$this->logger->info(
				'ComplianceService.recordPermitsSend: blocked — lawfulBasis "imported" does not permit marketing sends',
				['contactId' => $contactId, 'channel' => $channel]
			);
			return false;
		}

		if (in_array($lawfulBasis, self::LAWFUL_BASIS_ALLOWED, true) === false) {
			return false;
		}

		if ($lawfulBasis === self::LAWFUL_BASIS_SOFT_OPT_IN && $this->objectionWasOffered(record: $record) === false) {
			                                                             
			                                                              
			                                                              
			$this->logger->info(
				'ComplianceService.recordPermitsSend: blocked — soft opt-in needs the objection recorded in evidence',
				['contactId' => $contactId, 'channel' => $channel]
			);
			return false;
		}

		$withdrawnAt = ($record['withdrawnAt'] ?? null);
		if (is_string($withdrawnAt) === true && trim($withdrawnAt) !== '') {
			return false;
		}

		return true;
	}                         

	   
                                                                     
   
                                                                  
   
                                                          
    
	private function objectionWasOffered(array $record): bool {
		$evidence = ($record['evidence'] ?? null);
		if (is_array($evidence) === false) {
			return false;
		}

		return (bool)($evidence['objectionOffered'] ?? false);
	}                           

	   
                                                       
   
                                                                          
                                                                       
                                                                        
                                                                         
                                                             
   
                                                                         
                                                       
                                                  
                                            
                                                 
                                                                
                                                                  
   
                
   
                                                                                                                                
    
	public function recordListConsent(
		string $contactId,
		string $listId,
		string $channel,
		string $lawfulBasis,
		string $consentSource,
		array $evidence,
	): void {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $listId === '' || $channel === '') {
			return;
		}

		if ($this->integriq->isActive() === true
			&& $this->integriq->recordListConsent(
				contactId: $contactId,
				listId: $listId,
				channel: $channel,
				lawfulBasis: $lawfulBasis,
				source: $consentSource,
				evidence: $evidence
			) === true
		) {
			                                                           
			return;
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');
		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: $listId);
		$payload = ($record ?? ['softBounceCount' => 0]);
		$payload['contactId'] = $contactId;
		$payload['channel'] = $channel;
		$payload['listId'] = $listId;
		$payload['lawfulBasis'] = $lawfulBasis;
		$payload['consentSource'] = $consentSource;
		$payload['consentedAt'] = $now;
		$payload['evidence'] = $evidence;

		                                                                   
		                                                                    
		                                                                    
		                         
		unset($payload['withdrawnAt'], $payload['withdrawnReason']);

		if ($record === null) {
			$this->persistConsentObject(payload: $payload, id: null);
			return;
		}

		$this->persistConsentObject(payload: $payload, id: $this->extractObjectId(payload: $record));
	}                         

	   
                                                                    
   
                                                                  
                                                                 
                                                                    
                                                                       
                                                                            
                                                                      
                       
   
                                                                 
                                                                    
                                                       
   
                                                            
                                                               
                                           
   
                                                                       
                                            
   
                                                         
   
                                                                                                                
    
	public function validateTemplate(array $templateData, string $channel): ?string {
		$channel = strtolower(trim($channel));
		if ($channel !== 'email') {
			return null;
		}

		$bodyHtml = (string)($templateData['bodyHtml'] ?? '');
		$bodyText = (string)($templateData['bodyText'] ?? '');
		$footerOverride = (string)($templateData['footerOverride'] ?? '');

		$haystack = $bodyHtml . "\n" . $bodyText . "\n" . $footerOverride;

		if (str_contains($haystack, self::UNSUBSCRIBE_TOKEN) === false) {
			return sprintf(
				'Email templates must embed the %s token (GDPR Art. 7(3) withdrawal).',
				self::UNSUBSCRIBE_TOKEN
			);
		}

		if (trim($footerOverride) === '') {
			return 'Email templates must include the sender\'s physical address in the footer '
				. '(footerOverride) per CAN-SPAM § 7704(a)(5). It is placed at '
				. '{{physical_address}} / {{sender_address}} / {{company_address}} / '
				. '{{address_block}} when the body has one, and at the end otherwise.';
		}

		return null;
	}                        

	   
                                                    
   
                                                           
                                                 
   
                                                                                                                           
   
                                                                                                                             
    
	public function listTemplates(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->loadTemplatesRaw();
		$total = count($all);
		$slice = array_slice($all, (($page - 1) * $limit), $limit);
		return [
			'data' => $slice,
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}                     

	   
                                                         
   
                                                               
                                                              
   
                                      
                                
   
                                               
    
	private function computePages(int $total, int $limit): int {
		if ($total <= 0 || $limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}                    

	   
                                     
   
                                                    
   
                                                               
   
                                                                                                                             
    
	public function getTemplateById(string $templateId): ?array {
		if ($templateId === '') {
			return null;
		}

		$register = $this->getRegisterSlug();
		$schema = $this->getCampaignTemplateSchemaSlug();
		$objectService = $this->getObjectService();
		if ($register === '' || $schema === '' || $objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $templateId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'ComplianceService.getTemplateById: not found',
				['templateId' => $templateId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}                       

	   
                                                          
   
                                                               
                                                                     
                                                                  
                                             
   
                                                                  
                                                      
   
                                                                  
   
                                                                                                                             
                                                                                                                                     
    
	public function createTemplate(array $payload, string $createdByUid): array {
		$name = (string)($payload['name'] ?? '');
		if (trim($name) === '') {
			return ['error' => 'Invalid name'];
		}

		$channel = strtolower((string)($payload['channel'] ?? ''));
		if (in_array($channel, ['email', 'sms'], true) === false) {
			return ['error' => 'Invalid channel'];
		}

		$validationError = $this->validateTemplate(templateData: $payload, channel: $channel);
		if ($validationError !== null) {
			return ['error' => $validationError];
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');
		$object = [
			'name' => $name,
			'channel' => $channel,
			'subject' => (string)($payload['subject'] ?? ''),
			'bodyHtml' => (string)($payload['bodyHtml'] ?? ''),
			'bodyText' => (string)($payload['bodyText'] ?? ''),
			'senderName' => (string)($payload['senderName'] ?? ''),
			'senderEmail' => (string)($payload['senderEmail'] ?? ''),
			'replyTo' => (string)($payload['replyTo'] ?? ''),
			'footerOverride' => (string)($payload['footerOverride'] ?? ''),
			'articleIds' => $this->normaliseArticleIds(value: ($payload['articleIds'] ?? [])),
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];

		$saved = $this->saveTemplateObject(payload: $object);
		if ($saved === null) {
			return ['error' => 'Could not create template'];
		}

		return ['template' => $saved];
	}                      

	   
                                                                     
   
                                                          
                                                                   
                                                             
                                                           
   
                                                    
                                                     
   
                                                                  
   
                                                                                                                             
                                                                                                                                     
    
	public function patchTemplate(string $templateId, array $patch): array {
		$existing = $this->getTemplateById(templateId: $templateId);
		if ($existing === null) {
			return ['error' => 'Template not found'];
		}

		$editable = ['name', 'subject', 'bodyHtml', 'bodyText', 'senderName', 'senderEmail', 'replyTo', 'footerOverride'];
		$payload = $existing;
		foreach ($editable as $field) {
			if (array_key_exists($field, $patch) === true && is_string($patch[$field]) === true) {
				$payload[$field] = $patch[$field];
			}
		}

		if (array_key_exists('articleIds', $patch) === true) {
			$payload['articleIds'] = $this->normaliseArticleIds(value: $patch['articleIds']);
		}

		$channel = strtolower((string)($existing['channel'] ?? 'email'));
		$error = $this->validateTemplate(templateData: $payload, channel: $channel);
		if ($error !== null) {
			return ['error' => $error];
		}

		$payload['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$saved = $this->saveTemplateObject(payload: $payload, id: $this->extractTemplateId(payload: $existing));
		if ($saved === null) {
			return ['error' => 'Could not update template'];
		}

		return ['template' => $saved];
	}                     

	   
                                                                    
   
                                                                
                                                                        
                                                                   
   
                                                             
   
                                                                        
   
                                                                                                                                 
    
	private function normaliseArticleIds(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $id) {
			if (is_scalar($id) === false) {
				continue;
			}

			$id = trim((string)$id);
			if ($id !== '' && in_array($id, $out, true) === false) {
				$out[] = $id;
			}
		}

		return $out;
	}                           

	   
                                                  
   
                                                            
    
	private function loadTemplatesRaw(): array {
		$register = $this->getRegisterSlug();
		$schema = $this->getCampaignTemplateSchemaSlug();
		$objectService = $this->getObjectService();
		if ($register === '' || $schema === '' || $objectService === null) {
			return [];
		}

		try {
			                                                                        
			                                                                                 
			                                                                              
			                                                                   
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.loadTemplatesRaw: findAll failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}                        

	   
                                                 
   
                                                          
                                                     
   
                                                                   
    
	private function saveTemplateObject(array $payload, ?string $id = null): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getCampaignTemplateSchemaSlug();
		$objectService = $this->getObjectService();
		if ($register === '' || $schema === '' || $objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.saveTemplateObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}                          

	   
                                                              
   
                                                 
   
                                      
   
                                                                                                                         
    
	private function extractTemplateId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                         

	   
                                                             
   
                               
    
	private function getCampaignTemplateSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'campaignTemplate_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'campaignTemplate';
	}                                     

	   
                                                               
                                               
   
                                                                
                                                                  
                                            
                                                               
                                                                   
                                                                     
                                                            
                             
   
                                                                     
                                                                 
                                                                     
                                                                      
   
                                                 
                                            
                                           
                                                              
                                                            
                                       
                                                                   
                                                                     
                                                                          
                                                                  
                                                   
   
                
   
                                                                                               
                                                                                                                                               
   
                                                                                             
                                                                                 
    
	public function recordConsentWithdrawal(
		string $contactId,
		string $channel,
		string $reason,
		?string $sourceBlastId = null,
		?string $listId = null,
	): void {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $channel === '' || $reason === '') {
			return;
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');

		                                                                    
		                                                               
		if ($this->integriq->isActive() === true
			&& $this->integriq->recordWithdrawal(contactId: $contactId, channel: $channel, reason: $reason, listId: $listId) === true
		) {
			$this->transitionQueuedDeliveries(contactId: $contactId, sourceBlastId: $sourceBlastId);
			$this->logger->info(
				'ComplianceService.recordConsentWithdrawal: withdrawal recorded in integriq',
				['contactId' => $contactId, 'channel' => $channel, 'listId' => $listId, 'reason' => $reason]
			);
			return;
		}

		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: $listId);
		if ($record === null) {
			$this->persistWithdrawalLedger(
				contactId: $contactId,
				channel: $channel,
				reason: $reason,
				now: $now,
				listId: $listId,
			);
		}

		if ($record !== null) {
			$existingWithdrawnAt = $record['withdrawnAt'] ?? null;
			$alreadyWithdrawn = (is_string($existingWithdrawnAt) === true && trim($existingWithdrawnAt) !== '');
			if ($alreadyWithdrawn === true) {
				                                                       
				$this->logger->info(
					'ComplianceService.recordConsentWithdrawal: already withdrawn',
					[
						'contactId' => $contactId,
						'channel' => $channel,
						'listId' => $listId,
						'sourceBlastId' => $sourceBlastId,
					]
				);
			}

			if ($alreadyWithdrawn === false) {
				$record['withdrawnAt'] = $now;
				$record['withdrawnReason'] = $reason;
				$this->persistConsentObject(payload: $record, id: $this->extractObjectId(payload: $record));
			}
		}        

		$this->transitionQueuedDeliveries(contactId: $contactId, sourceBlastId: $sourceBlastId);

		$this->logger->info(
			'ComplianceService.recordConsentWithdrawal: withdrawal recorded',
			[
				'contactId' => $contactId,
				'channel' => $channel,
				'listId' => $listId,
				'reason' => $reason,
				'sourceBlastId' => $sourceBlastId,
			]
		);
	}                               

	   
                                                                
   
                                                                        
                                                                       
   
                                                        
                                                                 
   
                
    
	private function persistConsentObject(array $payload, ?string $id): void {
		$register = $this->getRegisterSlug();
		$schema = $this->getConsentRecordSchemaSlug();
		if ($register === '' || $schema === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		                                                                  
		                                                                   
		                                                                   
		                                                                     
		                                      
		try {
			if ($id !== null && $id !== '') {
				$objectService->updateObject(
					id: $id,
					object: $payload,
					register: $register,
					schema: $schema,
				);
				return;
			}

			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.persistConsentObject: write failed',
				[
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
		}         
	}                            

	   
                                                                         
   
                                                                 
                                                              
   
                                                 
                                            
                                            
                                            
                                                                    
                                                  
   
                
    
	private function persistWithdrawalLedger(
		string $contactId,
		string $channel,
		string $reason,
		string $now,
		?string $listId = null,
	): void {
		$payload = [
			'contactId' => $contactId,
			'channel' => $channel,
			'lawfulBasis' => 'consent',
			'consentSource' => 'auto-withdrawal-ledger',
			'consentedAt' => $now,
			'withdrawnAt' => $now,
			'withdrawnReason' => $reason,
			'softBounceCount' => 0,
		];

		if ($listId !== null && $listId !== '') {
			$payload['listId'] = $listId;
		}

		$this->persistConsentObject(payload: $payload, id: null);
	}                               

	   
                                                           
                                                               
   
                                                                         
                                                                  
                                                                    
                       
   
                                                 
                                                                   
   
                
   
                                                                                                                        
                                                                                                                        
    
	private function transitionQueuedDeliveries(string $contactId, ?string $sourceBlastId): void {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'ComplianceService.transitionQueuedDeliveries: findAll failed',
				[
					'contactId' => $contactId,
					'exception' => $e->getMessage(),
				]
			);
			return;
		}

		foreach (($rows ?? []) as $row) {
			$array = $this->toArray(value: $row);
			if ($array === []) {
				continue;
			}

			$status = strtolower((string)($array['status'] ?? ''));
			if (in_array($status, self::QUEUED_DELIVERY_STATUSES, true) === false) {
				continue;
			}

			if ($sourceBlastId !== null && $sourceBlastId !== '') {
				$rowBlast = (string)($array['blastId'] ?? '');
				if ($rowBlast !== '' && $rowBlast !== $sourceBlastId) {
					                                                  
					                                    
					continue;
				}
			}

			$id = $this->extractObjectId(payload: $array);
			if ($id === '') {
				continue;
			}

			$array['status'] = self::STATUS_UNSUBSCRIBED_BEFORE_SEND;
			$array['unsubscribedAt'] = gmdate('Y-m-d\TH:i:s\Z');

			try {
				$objectService->updateObject(
					id: $id,
					object: $array,
					register: $register,
					schema: $schema,
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'ComplianceService.transitionQueuedDeliveries: updateObject failed',
					[
						'id' => $id,
						'exception' => $e->getMessage(),
					]
				);
			}
		}             
	}                                  

	   
                                                                
   
                                                                       
                                                                         
                                                                          
                                                                        
                                                                         
                                                                        
                      
   
                                                 
                                            
                                                              
                                                   
   
                                                           
    
	private function findConsentRecord(string $contactId, string $channel, ?string $listId = null): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getConsentRecordSchemaSlug();
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'channel' => $channel,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.findConsentRecord: findAll failed',
				[
					'contactId' => $contactId,
					'channel' => $channel,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}         

		$wanted = '';
		if ($listId !== null) {
			$wanted = $listId;
		}

		foreach (($rows ?? []) as $row) {
			$array = $this->toArray(value: $row);
			$isMatch = $this->isConsentRecordFor(
				row: $array,
				contactId: $contactId,
				channel: $channel,
				wantedList: $wanted,
			);
			if ($isMatch === true) {
				return $array;
			}
		}

		return null;
	}                         

	   
                                                                        
   
                                                                        
                                                                          
                                                    
   
                                                       
                                                         
                                                   
                                                                     
                                                  
   
                                                        
    
	private function isConsentRecordFor(array $row, string $contactId, string $channel, string $wantedList): bool {
		if ($row === []) {
			return false;
		}

		if ((string)($row['contactId'] ?? '') !== $contactId) {
			return false;
		}

		if (strtolower((string)($row['channel'] ?? '')) !== $channel) {
			return false;
		}

		return (string)($row['listId'] ?? '') === $wantedList;
	}                          

	   
                                              
   
                                 
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                                          
   
                               
    
	private function getConsentRecordSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'consent_record_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_CONSENT_RECORD_SCHEMA_SLUG;
	}                                  

	   
                                                          
   
                               
    
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blast_delivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}                                  

	   
                                                
   
                                                                   
                                   
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}                        

	   
                                                                 
   
                                               
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               

	   
                                                                 
   
                                                        
   
                                              
   
                                                                                                                   
    
	private function extractObjectId(array $payload): string {
		foreach (['id', 'uuid', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true) {
				$value = (string)$payload[$key];
				if ($value !== '') {
					return $value;
				}
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				if (isset($payload['@self'][$key]) === true && is_scalar($payload['@self'][$key]) === true) {
					$value = (string)$payload['@self'][$key];
					if ($value !== '') {
						return $value;
					}
				}
			}
		}

		return '';
	}                       
}           
