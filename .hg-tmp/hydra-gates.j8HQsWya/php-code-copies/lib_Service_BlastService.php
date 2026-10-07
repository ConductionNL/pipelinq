<?php

   
                         
  
                                                                     
                                                                        
                                                                             
                                                                       
                             
                                                              
                                                                         
                                                                      
                                                                            
                                                 
  
                                                                         
                                                                       
                                                                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                            
  
                       
                                                                               
                                                                                    
                                                     
                                                           
                                                                            
                                                                 
                                                               
                                                                        
                                                                          
  
                                                                                                          
  
                                                                                  
                                                                                
                                          
                                                                                   
                                                                                 
                                                                                   
                                                                                    
                                                                                   
                                                                          
   
class BlastService {
	   
                                                                          
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                                                    
                 
    
	private const DEFAULT_BLAST_SCHEMA_SLUG = 'blast';

	   
                                                  
                                                   
    
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	   
                                                     
                                                      
    
	private const DEFAULT_CAMPAIGN_TEMPLATE_SCHEMA_SLUG = 'campaignTemplate';

	   
                                                                   
                                                            
    
	private const DEFAULT_DISPATCH_BATCH_SIZE = 50;

	   
                                                              
                                      
    
	private const STATUS_UNSUBSCRIBED_BEFORE_SEND = 'unsubscribed-before-send';

	   
                
   
                                                      
                                                     
                                                            
                                                                                                   
                                          
   
                                                                                                           
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private SegmentService $segmentService,
		private MailTransportService $mailTransportService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                            
   
                                                         
                                                                 
                                                                  
                                                                   
                                                            
   
                                                                      
                                                                     
                                                                     
                                                                     
                                                               
   
                                                                    
                                                                      
                                                                      
   
                                              
                                                                
                                                     
   
                                              
                                                                          
                                                                        
   
                                                                                                           
   
                                                                                  
                                                                                      
                                                                                    
                                               
                                                                                            
                                                    
                                                                                   
                                                           
    
	public function sendBlast(string $blastId, bool $isDraft = false): array {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return $this->emptySummary(status: 'not-found');
		}

		$status = (string)($blast['status'] ?? 'draft');
		if ($status !== 'draft') {
			return $this->emptySummary(status: 'not-draft-' . $status);
		}

		$segmentId = (string)($blast['segmentId'] ?? '');
		$listId = (string)($blast['listId'] ?? '');
		if ($segmentId === '' && $listId === '') {
			return $this->emptySummary(status: 'no-audience');
		}

		$channel = (string)($blast['channel'] ?? 'email');

		$audience = $this->resolveAudience(segmentId: $segmentId, listId: $listId, channel: $channel);
		$missingConsent = $audience['missingConsent'];
		$suppressed = ($audience['suppressed'] ?? []);
		$compliantMembers = $audience['members'];

		if ($compliantMembers === []) {
			$this->logger->info(
				'BlastService.sendBlast: no compliant recipients — keeping draft',
				['blastId' => $blastId, 'channel' => $channel, 'missingCount' => count($missingConsent)]
			);
			return [
				'queued' => 0,
				'skippedNoConsent' => count($missingConsent),
				'skippedSuppressed' => count($suppressed),
				'variantA' => 0,
				'variantB' => 0,
				'variantBlastId' => null,
				'status' => 'skipped-no-consent',
			];
		}

		$abSplit = $this->extractAbSplitPercent(blast: $blast);
		$variantBlastId = null;
		if ($abSplit !== null && $isDraft === false) {
			$variantBlastId = $this->createAbVariant(
				parentBlastId: $blastId,
				variantData: ['suffix' => '-B'],
			);
		}

		$sliced = $this->sliceMembersForAb(
			members: $compliantMembers,
			abSplitPercent: $abSplit,
			parentBlastId: $blastId,
			variantBlastId: $variantBlastId,
		);

		$variantACount = 0;
		$variantBCount = 0;

		                                                                       
		                                                                    
		                                                  
		$countableRows = $sliced;
		if ($isDraft === false) {
			$countableRows = [];
			foreach ($sliced as $row) {
				$persisted = $this->persistQueuedDelivery(
					blastId: $row['blastId'],
					member: $row['member'],
					channel: $channel,
				);
				if ($persisted === false) {
					continue;
				}

				$countableRows[] = $row;
			}

			$this->updateBlastStatus(blastId: $blastId, newStatus: 'sending');
			if ($variantBlastId !== null) {
				$this->updateBlastStatus(blastId: $variantBlastId, newStatus: 'sending');
			}

			$this->updateBlastTotals(blastId: $blastId);
			if ($variantBlastId !== null) {
				$this->updateBlastTotals(blastId: $variantBlastId);
			}
		}        

		foreach ($countableRows as $row) {
			if ($row['variant'] === 'B') {
				$variantBCount++;
				continue;
			}

			$variantACount++;
		}

		return [
			'queued' => ($variantACount + $variantBCount),
			'skippedNoConsent' => count($missingConsent),
			'skippedSuppressed' => count($suppressed),
			'variantA' => $variantACount,
			'variantB' => $variantBCount,
			'variantBlastId' => $variantBlastId,
			'status' => $this->summaryStatusFor(isDraft: $isDraft),
		];
	}                 

	   
                                                     
   
                                                                
   
                                
    
	private function summaryStatusFor(bool $isDraft): string {
		if ($isDraft === true) {
			return 'draft-preview';
		}

		return 'queued';
	}                        

	   
                                                                                    
   
                                                                         
                                                                              
                                                                            
                                                                          
                                                                       
                                                                    
                                                                   
                                                                          
                                                                         
                                                             
   
                                                                         
                                                                    
                                                                         
                                                                       
                            
   
                                              
                                                          
   
                                                
   
                                                                                                                                              
   
                                                                                    
                                                                 
                                                                              
                                                                              
                                                          
    
	public function dispatchBlastDeliveries(string $blastId, int $maxPerSecond = 100): int {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return 0;
		}

		$template = $this->loadTemplate(templateId: (string)($blast['templateId'] ?? ''));
		if ($template === null) {
			$this->logger->warning(
				'BlastService.dispatchBlastDeliveries: template missing',
				['blastId' => $blastId, 'templateId' => ($blast['templateId'] ?? null)]
			);
			return 0;
		}

		                                                                    
		                                                                   
		                                                        
		$template['bodyHtml'] = $this->decorateCampaignLinks(
			html: (string)($template['bodyHtml'] ?? ''),
			blast: $blast,
			template: $template,
		);

		$transport = $this->mailTransportService->resolveTransport(blast: $blast);
		if ($transport === null) {
			$this->logger->warning(
				'BlastService.dispatchBlastDeliveries: no mail transport resolved for blast',
				['blastId' => $blastId]
			);
			return 0;
		}

		$rateLimit = $this->mailTransportService->resolveRateLimit(transport: $transport, callerRate: $maxPerSecond);
		$batchSize = $this->resolveBatchSize();

		$queued = $this->loadQueuedDeliveries(blastId: $blastId);
		if ($queued === []) {
			return 0;
		}

		$dispatched = 0;
		$batches = array_chunk($queued, $batchSize);
		foreach ($batches as $batchIndex => $batch) {
			$start = microtime(true);
			foreach ($batch as $delivery) {
				$result = $this->mailTransportService->sendOneDelivery(
					delivery: $delivery,
					template: $template,
					transport: $transport,
				);
				if ($result === false) {
					continue;
				}

				$dispatched++;
			}

			                                                                 
			                                     
			$expectedDuration = (count($batch) / max($rateLimit, 1));
			$elapsed = (microtime(true) - $start);
			$remaining = ($expectedDuration - $elapsed);
			if ($remaining > 0.0 && $batchIndex < (count($batches) - 1)) {
				$this->throttle(seconds: $remaining);
			}
		}             

		$this->updateBlastTotals(blastId: $blastId);

		return $dispatched;
	}                               

	   
                                                            
   
                                                                      
                                                                     
                                                                   
                                                           
                   
   
                                                           
                                                             
                                                                            
   
                                                           
   
                                                                                                           
    
	public function createAbVariant(string $parentBlastId, array $variantData): string {
		$parent = $this->loadBlast(blastId: $parentBlastId);
		if ($parent === null) {
			return '';
		}

		$suffix = (string)($variantData['suffix'] ?? ' (Variant B)');
		$baseName = (string)($parent['name'] ?? 'Blast');
		$override = (string)($variantData['name'] ?? '');
		$childName = ($baseName . $suffix);
		if ($override !== '') {
			$childName = $override;
		}

		$childPayload = [
			'name' => $childName,
			'segmentId' => (string)($parent['segmentId'] ?? ''),
			'templateId' => (string)($variantData['templateId'] ?? ($parent['templateId'] ?? '')),
			'channel' => (string)($parent['channel'] ?? 'email'),
			'status' => 'draft',
			'abVariantOf' => (string)($parent['@self']['uuid'] ?? $parent['uuid'] ?? $parentBlastId),
			'connectorSourceId' => (string)($parent['connectorSourceId'] ?? ''),
			'transportId' => (string)($parent['transportId'] ?? ''),
			'totals' => $this->emptyTotals(),
			'createdBy' => (string)($parent['createdBy'] ?? ''),
			'createdAt' => $this->nowIso(),
		];

		$created = $this->saveObject(
			payload: $childPayload,
			schemaSlug: $this->getBlastSchemaSlug(),
		);
		if ($created === null) {
			return '';
		}

		return $this->extractId(payload: $created);
	}                       

	   
                                                                           
   
                                                  
                                                               
                                                                
                                                                                    
   
                                              
   
                
   
                                                                                                           
    
	public function updateBlastTotals(string $blastId): void {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return;
		}

		$deliveries = $this->loadAllDeliveriesForBlast(blastId: $blastId);
		$totals = $this->emptyTotals();
		foreach ($deliveries as $delivery) {
			$status = (string)($delivery['status'] ?? '');
			if ($status === '' || isset($totals[$status]) === false) {
				continue;
			}

			$totals[$status]++;
		}

		$payload = $blast;
		$payload['totals'] = $totals;
		$this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastSchemaSlug(),
			id: $this->extractId(payload: $blast),
		);
	}                         

	   
                                                                       
   
                                                                       
                                                                    
                                                                     
                                                                      
   
                                                  
                                                     
                                                   
                                                         
   
                
   
                                                                                                           
    
	public function transitionQueuedDeliveries(string $contactId, string $blastId, string $newStatus = self::STATUS_UNSUBSCRIBED_BEFORE_SEND): void {
		$deliveries = $this->loadQueuedDeliveriesForContact(blastId: $blastId, contactId: $contactId);
		foreach ($deliveries as $delivery) {
			$payload = $delivery;
			$payload['status'] = $newStatus;
			$payload['unsubscribedAt'] = $this->nowIso();
			$this->saveObject(
				payload: $payload,
				schemaSlug: $this->getBlastDeliverySchemaSlug(),
				id: $this->extractId(payload: $delivery),
			);
		}

		if ($deliveries !== []) {
			$this->updateBlastTotals(blastId: $blastId);
		}
	}                                  

	   
                                                                    
   
                                                                        
                                                                         
                                                               
                                                                        
   
                                                       
                                                                                        
                                                           
                                                 
   
                                                                                                                           
   
                                                                                                                          
    
	public function listBlasts(?string $status, int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$filters = [];
		if ($status !== null && $status !== '') {
			$filters['status'] = $status;
		}

		$schemaSlug = $this->getBlastSchemaSlug();
		$offset = (($page - 1) * $limit);
		$total = $this->countObjects(schemaSlug: $schemaSlug, filters: $filters);
		                                                                         
		                                                                      
		                                                                      
		                                                                        
		                                                                        
		                                                                     
		$slice = $this->loadObjects(
			schemaSlug: $schemaSlug,
			filters: $filters,
			limit: $limit,
			offset: $offset,
			sort: ['createdAt' => 'desc'],
		);
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

	   
                                                
   
                                                                    
                                                                      
                               
   
                                              
   
                                                            
   
                                                                                                                          
    
	public function getBlastById(string $blastId): ?array {
		if ($blastId === '') {
			return null;
		}

		return $this->loadBlast(blastId: $blastId);
	}                    

	   
                                                                     
   
                                                                     
                                                                      
                                                                      
                                                                      
                                                                   
                                  
   
                                                         
                                                      
   
                                                               
   
                                                                                                                          
    
	public function createDraftBlast(array $payload, string $createdByUid): array {
		$error = $this->validateDraftBlastPayload(payload: $payload);
		if ($error !== null) {
			return ['error' => $error];
		}

		$now = $this->nowIso();
		$object = [
			'name' => (string)$payload['name'],
			'segmentId' => (string)($payload['segmentId'] ?? ''),
			'listId' => (string)($payload['listId'] ?? ''),
			'templateId' => (string)$payload['templateId'],
			'channel' => (string)$payload['channel'],
			'connectorSourceId' => (string)($payload['connectorSourceId'] ?? ''),
			'transportId' => (string)($payload['transportId'] ?? ''),
			'status' => 'draft',
			'totals' => $this->emptyTotals(),
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];
		if (isset($payload['scheduledFor']) === true && is_string($payload['scheduledFor']) === true) {
			$object['scheduledFor'] = $payload['scheduledFor'];
		}

		$saved = $this->saveObject(payload: $object, schemaSlug: $this->getBlastSchemaSlug());
		if ($saved === null) {
			return ['error' => 'Could not create blast'];
		}

		return ['blast' => $saved];
	}                        

	   
                                                      
   
                                                         
   
                                                         
    
	private function validateDraftBlastPayload(array $payload): ?string {
		$name = (string)($payload['name'] ?? '');
		if (trim($name) === '') {
			return 'Invalid name';
		}

		$audienceError = $this->validateAudience(payload: $payload);
		if ($audienceError !== null) {
			return $audienceError;
		}

		$templateId = (string)($payload['templateId'] ?? '');
		if (trim($templateId) === '' || $this->loadTemplate(templateId: $templateId) === null) {
			return 'Invalid template';
		}

		$channel = strtolower((string)($payload['channel'] ?? ''));
		if (in_array($channel, ['email', 'sms'], true) === false) {
			return 'Invalid channel';
		}

		return null;
	}                                 

	   
                                                        
   
                                                                        
                                                                         
                                                                    
                                                                        
           
   
                                                         
   
                                                                           
   
                                                                                              
    
	private function validateAudience(array $payload): ?string {
		$segmentId = trim((string)($payload['segmentId'] ?? ''));
		$listId = trim((string)($payload['listId'] ?? ''));

		if ($segmentId !== '' && $listId !== '') {
			return 'Choose either a segment or a mailing list, not both';
		}

		if ($segmentId === '' && $listId === '') {
			return 'Choose a segment or a mailing list';
		}

		if ($listId !== '') {
			if ($this->loadOne(id: $listId, schemaSlug: $this->getMailingListSchemaSlug()) === null) {
				return 'Invalid mailing list';
			}

			return null;
		}

		if ($this->loadOne(id: $segmentId, schemaSlug: $this->segmentService->getSegmentSchemaSlugPublic()) === null) {
			return 'Invalid segment';
		}

		return null;
	}                        

	   
                                                        
   
                               
    
	private function getMailingListSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'mailing_list_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'mailingList';
	}                                

	   
                                                                         
   
                                                                      
                                                                    
   
                                              
                                                       
   
                                                                     
   
                                                                                                                          
    
	public function patchBlastName(string $blastId, string $name): ?array {
		if (trim($name) === '') {
			return null;
		}

		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return null;
		}

		$payload = $blast;
		$payload['name'] = $name;
		$payload['updatedAt'] = $this->nowIso();
		return $this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastSchemaSlug(),
			id: $this->extractId(payload: $blast),
		);
	}                      

	   
                                                                  
                                                           
   
                                                                       
                                                                      
                                                                     
                  
   
                                              
   
                                                                    
   
                                                                                                                          
    
	public function cancelBlast(string $blastId): array {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return ['status' => 'not-found', 'cancelledDeliveries' => 0];
		}

		$current = (string)($blast['status'] ?? 'draft');
		if (in_array($current, ['sent', 'failed', 'cancelled'], true) === true) {
			return ['status' => 'noop-' . $current, 'cancelledDeliveries' => 0];
		}

		$queued = $this->loadQueuedDeliveries(blastId: $blastId);
		foreach ($queued as $delivery) {
			$row = $delivery;
			$row['status'] = self::STATUS_UNSUBSCRIBED_BEFORE_SEND;
			$row['unsubscribedAt'] = $this->nowIso();
			$this->saveObject(
				payload: $row,
				schemaSlug: $this->getBlastDeliverySchemaSlug(),
				id: $this->extractId(payload: $delivery),
			);
		}

		$this->updateBlastStatus(blastId: $blastId, newStatus: 'cancelled');
		$this->updateBlastTotals(blastId: $blastId);
		return ['status' => 'cancelled', 'cancelledDeliveries' => count($queued)];
	}                   

	   
                                                        
   
                                                                     
                                                             
                
   
                                              
                                            
                                                 
   
                                                                                                                           
   
                                                                                                                          
    
	public function listDeliveriesForBlast(string $blastId, int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		if ($blastId === '') {
			return [
				'data' => [],
				'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0],
			];
		}

		$filters = ['blastId' => $blastId];
		$offset = (($page - 1) * $limit);
		$total = $this->countDeliveries(filters: $filters);
		$slice = $this->loadDeliveries(filters: $filters, limit: $limit, offset: $offset);
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

	   
                                                                        
                                                   
   
                                          
                                                    
                                                           
                                                         
                                                                           
                                                                           
   
                                                            
    
	private function loadObjects(
		string $schemaSlug,
		array $filters,
		?int $limit = null,
		?int $offset = null,
		?array $sort = null
	): array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$config = [
			'filters' => array_merge(['register' => $register, 'schema' => $schemaSlug], $filters),
		];
		if ($limit !== null) {
			$config['limit'] = $limit;
		}

		if ($offset !== null) {
			$config['offset'] = $offset;
		}

		if ($sort !== null) {
			$config['sort'] = $sort;
		}

		try {
			$rows = $objectService->findAll(config: $config);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.loadObjects: findAll failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}                   

	   
                                                                          
   
                                                                    
                                                                     
   
                                              
                                                          
   
                                                                    
   
                                                                          
    
	private function countObjects(string $schemaSlug, array $filters): int {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return 0;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			return $objectService->count(
				config: [
					'filters' => array_merge(['register' => $register, 'schema' => $schemaSlug], $filters),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.countObjects: count failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}                    

	   
                                                                 
   
                                                                     
                                                                     
                           
   
                                                                     
                                                        
                                                 
                                                          
   
                                                                                              
   
                                                                                                           
    
	public function sliceMembersForAb(array $members, ?int $abSplitPercent, string $parentBlastId, ?string $variantBlastId): array {
		$sliced = [];
		foreach ($members as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '') {
				continue;
			}

			$variant = $this->variantFor(contactId: $contactId, abSplitPercent: $abSplitPercent);
			$targetId = $parentBlastId;
			if ($variant === 'B' && $variantBlastId !== null && $variantBlastId !== '') {
				$targetId = $variantBlastId;
			}

			$sliced[] = [
				'member' => $member,
				'variant' => $variant,
				'blastId' => $targetId,
			];
		}

		return $sliced;
	}                         

	   
                                                                      
                 
   
                                                                       
                                                                      
                                                                       
                                                                       
                                                                    
                                               
   
                                                                              
                                                                                  
                                                     
   
                                                                                                                                
   
                                                                                              
                                                                                                                                                                 
    
	protected function resolveAudience(string $segmentId, string $listId, string $channel): array {
		if ($listId !== '') {
			return $this->resolveListAudience(listId: $listId);
		}

		$complianceResult = $this->checkSegmentCompliance(segmentId: $segmentId, channel: $channel);
		$missingConsent = $complianceResult['missingConsent'];
		$missingSet = array_flip($missingConsent);
		                                                                   
		                                                                
		                                                                  
		                                                   
		$suppressedSet = array_flip($complianceResult['suppressed']);
		                                                                     
		                                                               
		$links = ($complianceResult['unsubscribe'] ?? []);

		$members = [];
		foreach ($this->segmentService->getMembersForBlast(segmentId: $segmentId) as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '' || isset($missingSet[$contactId]) === true || isset($suppressedSet[$contactId]) === true) {
				continue;
			}

			if ((string)($member['unsubscribeUrl'] ?? '') === '' && (string)($links[$contactId] ?? '') !== '') {
				$member['unsubscribeUrl'] = (string)$links[$contactId];
			}

			$members[] = $member;
		}

		return ['members' => $members, 'missingConsent' => $missingConsent, 'suppressed' => $complianceResult['suppressed']];
	}                       

	   
                                                                       
   
                                                               
                                                                     
                                                                 
   
                                                                         
                                                                      
                                                                  
   
                                                   
   
                                                                                                                                
   
                                                                                                          
    
	protected function resolveListAudience(string $listId): array {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\Marketing\\SubscriptionQueryService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.resolveListAudience: SubscriptionQueryService unavailable — failing closed',
				['listId' => $listId, 'exception' => $e->getMessage()]
			);
			return ['members' => [], 'missingConsent' => [], 'suppressed' => []];
		}

		try {
			$audience = $service->getBlastAudienceForList($listId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.resolveListAudience: call failed — failing closed',
				['listId' => $listId, 'exception' => $e->getMessage()]
			);
			return ['members' => [], 'missingConsent' => [], 'suppressed' => []];
		}

		if (is_array($audience) === false) {
			return ['members' => [], 'missingConsent' => [], 'suppressed' => []];
		}

		$members = [];
		foreach (($audience['members'] ?? []) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$members[] = [
				'contactId' => (string)($row['contactId'] ?? ''),
				'email' => (string)($row['email'] ?? ''),
				'unsubscribeUrl' => (string)($row['unsubscribeUrl'] ?? ''),
			];
		}

		$skipped = [];
		foreach (($audience['skipped'] ?? []) as $contactId) {
			if (is_scalar($contactId) === true) {
				$skipped[] = (string)$contactId;
			}
		}

		return ['members' => $members, 'missingConsent' => $skipped, 'suppressed' => []];
	}                           

	   
                                                                   
                                                                 
                                                                
                               
   
                                                  
                                                     
   
                                                                                             
   
                                                                                                        
                                                                                                                                                                 
    
	protected function checkSegmentCompliance(string $segmentId, string $channel): array {
		try {
			$service = $this->container->get('OCA\\Pipelinq\\Service\\ComplianceService');
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.checkSegmentCompliance: ComplianceService unavailable',
				['exception' => $e->getMessage()]
			);
			$members = $this->segmentService->getMembersForBlast(segmentId: $segmentId);
			$missingConsent = [];
			foreach ($members as $member) {
				$contactId = (string)($member['contactId'] ?? '');
				if ($contactId !== '') {
					$missingConsent[] = $contactId;
				}
			}

			return [
				'compliant' => false,
				'missingConsent' => $missingConsent,
				'missingCount' => count($missingConsent),
				'suppressed' => [],
			];
		}         

		try {
			$result = $service->checkSegmentCompliance($segmentId, $channel);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.checkSegmentCompliance: call failed — failing closed',
				['segmentId' => $segmentId, 'channel' => $channel, 'exception' => $e->getMessage()]
			);
			return ['compliant' => false, 'missingConsent' => [], 'missingCount' => 0, 'suppressed' => []];
		}

		if (is_array($result) === false) {
			return ['compliant' => false, 'missingConsent' => [], 'missingCount' => 0, 'suppressed' => []];
		}

		$missingScalars = $this->contactIdsOf(value: ($result['missingConsent'] ?? []));
		$suppressedScalars = $this->contactIdsOf(value: ($result['suppressed'] ?? []));

		$links = [];
		foreach ((array)($result['unsubscribe'] ?? []) as $contactId => $url) {
			if (is_string($url) === true && $url !== '') {
				$links[(string)$contactId] = $url;
			}
		}

		return [
			'compliant' => (bool)($result['compliant'] ?? false),
			'missingConsent' => $missingScalars,
			'missingCount' => (int)($result['missingCount'] ?? count($missingScalars)),
			'suppressed' => $suppressedScalars,
			'unsubscribe' => $links,
		];
	}                              

	   
                                                                          
   
                                     
   
                                       
   
                                                                                                                                                                 
    
	private function contactIdsOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$ids = [];
		foreach ($value as $id) {
			if (is_scalar($id) === true) {
				$ids[] = (string)$id;
			}
		}

		return $ids;
	}                    

	   
                                                
   
                                                                     
                                                                   
                                                                      
                               
   
                                        
                                                  
   
                              
   
                                                                                                           
    
	public function variantFor(string $contactId, ?int $abSplitPercent): string {
		if ($abSplitPercent === null || $abSplitPercent <= 0 || $abSplitPercent > 100) {
			return 'A';
		}

		$bucket = (crc32($contactId) % 100);
		if ($bucket < $abSplitPercent) {
			return 'B';
		}

		return 'A';
	}                  

	   
                                       
   
                                           
                                                              
                                   
   
                                                 
    
	private function persistQueuedDelivery(string $blastId, array $member, string $channel): bool {
		$payload = [
			'blastId' => $blastId,
			'contactId' => (string)($member['contactId'] ?? ''),
			'email' => (string)($member['email'] ?? ''),
			'status' => 'queued',
		];
		$unsubscribeUrl = (string)($member['unsubscribeUrl'] ?? '');
		if ($unsubscribeUrl !== '') {
			$payload['unsubscribeUrl'] = $unsubscribeUrl;
		}

		if ($channel === 'sms') {
			$payload['phone'] = (string)($member['phone'] ?? '');
		}

		$created = $this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastDeliverySchemaSlug(),
		);
		return ($created !== null);
	}                             

	   
                                                                       
                                             
   
                                                                        
                                                                       
                                                                     
                      
   
                                                                       
                                                                    
                                                                     
                                          
   
                                           
                                                     
                                                           
   
                                                                  
   
                                                                                                                                                         
    
	private function decorateCampaignLinks(string $html, array $blast, array $template): string {
		if ($html === '') {
			return $html;
		}

		try {
			$decorator = $this->container->get('OCA\\Pipelinq\\Service\\CampaignLinkDecorator');
			return $decorator->decorate(
				html: $html,
				blast: $blast,
				template: $template,
				campaign: $this->campaignForBlast(blast: $blast),
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.decorateCampaignLinks: decorator unavailable or failed, sending links as authored',
				['exception' => $e->getMessage()]
			);
			return $html;
		}
	}                             

	   
                                                       
   
                                                                     
                                                                 
                               
   
                                                     
   
                                                                 
   
                                                                                                                                                         
    
	private function campaignForBlast(array $blast): array {
		if (trim((string)($blast['campaignId'] ?? '')) === '') {
			return [];
		}

		try {
			$campaigns = $this->container->get('OCA\\Pipelinq\\Service\\CampaignService');
			return $campaigns->forBlast(blast: $blast);
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.campaignForBlast: campaign unavailable, using the per-blast parameters',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}                        

	   
                                                                     
                                 
   
                                            
   
                
    
	protected function throttle(float $seconds): void {
		$micro = (int)round(($seconds * 1_000_000));
		if ($micro > 0) {
			usleep($micro);
		}
	}                

	   
                                                    
   
                           
    
	private function resolveBatchSize(): int {
		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'blast.dispatch_batch_size',
			(string)self::DEFAULT_DISPATCH_BATCH_SIZE,
		);
		if ($configured === '' || is_numeric($configured) === false) {
			return self::DEFAULT_DISPATCH_BATCH_SIZE;
		}

		$size = (int)$configured;
		if ($size <= 0) {
			return self::DEFAULT_DISPATCH_BATCH_SIZE;
		}

		return $size;
	}                        

	   
                                                       
   
                                                 
                                          
                                                      
   
                                                                   
    
	private function saveObject(array $payload, string $schemaSlug, ?string $id = null): ?array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schemaSlug,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.saveObject: save failed',
				['schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}                  

	   
                           
   
                                              
   
                                                            
    
	private function loadBlast(string $blastId): ?array {
		return $this->loadOne(id: $blastId, schemaSlug: $this->getBlastSchemaSlug());
	}                 

	   
                                      
   
                                                    
   
                                                               
    
	private function loadTemplate(string $templateId): ?array {
		if ($templateId === '') {
			return null;
		}

		return $this->loadOne(id: $templateId, schemaSlug: $this->getCampaignTemplateSchemaSlug());
	}                    

	   
                                          
   
                                          
                                          
   
                                                      
    
	private function loadOne(string $id, string $schemaSlug): ?array {
		$register = $this->getRegisterSlug();
		if ($register === '' || $schemaSlug === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $register,
				schema: $schemaSlug,
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'BlastService.loadOne: not found',
				['id' => $id, 'schema' => $schemaSlug, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}               

	   
                                             
   
                                              
   
                                                           
    
	private function loadAllDeliveriesForBlast(string $blastId): array {
		return $this->loadDeliveries(filters: ['blastId' => $blastId]);
	}                                 

	   
                                               
   
                                              
   
                                                         
    
	private function loadQueuedDeliveries(string $blastId): array {
		return $this->loadDeliveries(filters: ['blastId' => $blastId, 'status' => 'queued']);
	}                            

	   
                                                  
   
                                              
                                                  
   
                                                                         
    
	private function loadQueuedDeliveriesForContact(string $blastId, string $contactId): array {
		return $this->loadDeliveries(
			filters: ['blastId' => $blastId, 'contactId' => $contactId, 'status' => 'queued'],
		);
	}                                      

	   
                                                               
   
                                                    
                                                           
                                                         
   
                                                           
    
	private function loadDeliveries(array $filters, ?int $limit = null, ?int $offset = null): array {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return [];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$config = [
			'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
		];
		if ($limit !== null) {
			$config['limit'] = $limit;
		}

		if ($offset !== null) {
			$config['offset'] = $offset;
		}

		try {
			$rows = $objectService->findAll(config: $config);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.loadDeliveries: findAll failed',
				['filters' => $filters, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}                      

	   
                                                                         
   
                                                    
   
                                                                
   
                                                                          
    
	private function countDeliveries(array $filters): int {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return 0;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		try {
			return $objectService->count(
				config: [
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.countDeliveries: count failed',
				['filters' => $filters, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}                       

	   
                                              
   
                                              
                                        
   
                
    
	private function updateBlastStatus(string $blastId, string $newStatus): void {
		$blast = $this->loadBlast(blastId: $blastId);
		if ($blast === null) {
			return;
		}

		$payload = $blast;
		$payload['status'] = $newStatus;
		if ($newStatus === 'sending' && empty($blast['sentAt']) === true) {
			$payload['sentAt'] = $this->nowIso();
		}

		$this->saveObject(
			payload: $payload,
			schemaSlug: $this->getBlastSchemaSlug(),
			id: $this->extractId(payload: $blast),
		);
	}                         

	   
                                                  
   
                                                     
   
                                             
    
	private function extractAbSplitPercent(array $blast): ?int {
		$raw = ($blast['abSplitPercent'] ?? null);
		if ($raw === null || is_numeric($raw) === false) {
			return null;
		}

		$value = (int)$raw;
		if ($value < 0 || $value > 100) {
			return null;
		}

		return $value;
	}                             

	   
                                                               
   
                                          
    
	private function emptyTotals(): array {
		return [
			'queued' => 0,
			'sent' => 0,
			'delivered' => 0,
			'bounced' => 0,
			'opened' => 0,
			'clicked' => 0,
			'unsubscribed' => 0,
			'complained' => 0,
		];
	}                   

	   
                                
   
                                       
   
                                         
    
	private function emptySummary(string $status): array {
		return [
			'queued' => 0,
			'skippedNoConsent' => 0,
			'skippedSuppressed' => 0,
			'variantA' => 0,
			'variantB' => 0,
			'variantBlastId' => null,
			'status' => $status,
		];
	}                    

	   
                                               
   
                                                 
   
                                                  
   
                                                                                    
                                                         
    
	private function extractId(array $payload): string {
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

	   
                                                  
   
                        
    
	private function getBlastSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blast_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_SCHEMA_SLUG;
	}                          

	   
                                                          
   
                        
    
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}                                  

	   
                                                             
   
                        
    
	private function getCampaignTemplateSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'campaignTemplate_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_CAMPAIGN_TEMPLATE_SCHEMA_SLUG;
	}                                     

	   
                                              
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                                  
   
                                                                     
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'BlastService.getObjectService: OpenRegister unavailable',
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

	   
                                       
   
                             
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
