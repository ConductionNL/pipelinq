<?php

   
                             
  
                                                                        
                                                                      
                                                                  
                                                                    
           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                         
                                                         
                                                         
                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                          
  
                                                                       
                                                                        
                                                                      
  
                                                                
  
                                                  
                                                    
                                              
                                                                                                                                                    
                                                                                                                                   
                                                                                                                                        
   
class SlaEngineService {
	use EntityAccessorTrait;

	public const STATUS_ON_TRACK = 'on-track';
	public const STATUS_AT_RISK = 'at-risk';
	public const STATUS_BREACHED = 'breached';
	public const STATUS_MET = 'met';

	   
                
   
                                                                     
                                                                              
                                                                         
                                            
                                                                    
                                              
    
	public function __construct(
		private HolidayCalendarService $holidays,
		private BusinessHoursCalculator $businessHours,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                   
   
                                                                
                                                                 
                                                                          
                                   
   
                                                    
   
                                                          
    
	public function getHolidayService(): HolidayCalendarService {
		return $this->holidays;
	}                         

	   
                                                          
   
                                                              
                                                              
                                                                
                    
   
                                                                  
                                 
   
                                                  
                                                                     
                                                                                                       
   
                                                                    
   
                                                          
   
                                                  
    
	public function resolvePolicyForObject(string $objectType, string $objectId, array $metadata): ?array {
		unset($objectId);
		try {
			$policies = $this->loadActivePolicies();
		} catch (Throwable $e) {
			$this->logger->error(
				'SlaEngineService: failed to load active policies',
				['error' => $e->getMessage()]
			);
			return null;
		}

		$tier = (string)($metadata['tier'] ?? '');
		$organisationId = (string)($metadata['organisationId'] ?? '');
		$contractId = (string)($metadata['contractId'] ?? '');
		$now = $this->now();

		$candidates = [];
		foreach ($policies as $policy) {
			$matches = $this->policyApplies(
				policy: $policy,
				objectType: $objectType,
				tier: $tier,
				organisationId: $organisationId,
				contractId: $contractId,
				now: $now
			);
			if ($matches === false) {
				continue;
			}

			$candidates[] = $policy;
		}

		if ($candidates === []) {
			return null;
		}

		                                                                                  
		                                                 
		usort(
			$candidates,
			function (array $a, array $b) use ($contractId, $organisationId): int {
				$scopeA = $this->scopeSpecificity(policy: $a, contractId: $contractId, organisationId: $organisationId);
				$scopeB = $this->scopeSpecificity(policy: $b, contractId: $contractId, organisationId: $organisationId);
				if ($scopeA !== $scopeB) {
					return $scopeB <=> $scopeA;
				}

				$priorityA = (int)($a['priority'] ?? 100);
				$priorityB = (int)($b['priority'] ?? 100);
				if ($priorityA !== $priorityB) {
					return $priorityA <=> $priorityB;
				}

				$vfA = (string)($a['validFrom'] ?? '');
				$vfB = (string)($b['validFrom'] ?? '');
				return strcmp($vfB, $vfA);
			}
		);

		$chosen = $candidates[0];
		$this->logger->debug(
			'SlaEngineService: policy resolved',
			[
				'policyId' => $chosen['id'] ?? $chosen['@self']['slug'] ?? null,
				'objectType' => $objectType,
				'tier' => $tier,
				'candidates' => count($candidates),
			]
		);
		return $chosen;
	}                              

	   
                                                           
   
                                                     
                                                      
                                                                                    
   
                                                                        
                                                                                        
   
                                                          
   
                                                                                                                                          
    
	public function computeDeadlines(
		array $policy,
		DateTimeInterface $startTime,
		?array $pausedState = null,
	): array {
		$calendar = (string)($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');
		$results = [];

		foreach (($policy['targets'] ?? []) as $target) {
			$duration = $this->parseDuration(duration: (string)($target['duration'] ?? 'PT0S'));
			$calendarType = (string)($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);

			$effectiveStart = DateTimeImmutable::createFromInterface($startTime);
			if ($pausedState !== null && isset($pausedState['totalPausedMs']) === true) {
				$pausedMin = (int)floor(((int)$pausedState['totalPausedMs']) / 60000);
				if ($pausedMin > 0) {
					$effectiveStart = $effectiveStart->modify('-' . $pausedMin . ' minutes');
				}
			}

			$dueAt = $this->businessHours->addDuration(
				calendarType: $calendarType,
				startTime: $effectiveStart,
				duration: $duration,
				holidayCalendar: $calendar,
			);

			$results[] = [
				'kind' => (string)($target['kind'] ?? 'resolution'),
				'duration' => (string)($target['duration'] ?? ''),
				'calendar' => $calendarType,
				'dueAt' => $dueAt->format(DateTimeInterface::ATOM),
				'startedAt' => $effectiveStart->format(DateTimeInterface::ATOM),
				'status' => self::STATUS_ON_TRACK,
				'consumedPercentage' => 0.0,
				'breachEventIds' => [],
			];
		}             

		return $results;
	}                        

	   
                                                                       
   
                                                               
                                                                    
                                                                   
                             
   
                                                                             
                                                        
                                                     
   
                                                                   
   
                                                          
    
	public function evaluateTargets(array $currentTargets, array $policy, DateTimeInterface $now): array {
		$calendar = (string)($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');

		foreach ($currentTargets as $idx => $target) {
			if (($target['status'] ?? '') === self::STATUS_MET) {
				continue;
			}

			$started = $this->parseIsoOrNull(value: ($target['startedAt'] ?? null));
			$dueAt = $this->parseIsoOrNull(value: ($target['dueAt'] ?? null));
			if ($started === null || $dueAt === null) {
				continue;
			}

			$calendarType = (string)($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);

			$totalMin = $this->businessHours->elapsedBusinessMinutes($started, $dueAt, $calendar, $calendarType);
			$elapsedMin = $this->businessHours->elapsedBusinessMinutes($started, $now, $calendar, $calendarType);
			$consumed = 0.0;
			if ($totalMin > 0) {
				$consumed = ($elapsedMin / $totalMin);
			}

			$currentTargets[$idx]['consumedPercentage'] = round($consumed, 4);

			$status = self::STATUS_ON_TRACK;
			if ($consumed >= 0.8) {
				$status = self::STATUS_AT_RISK;
			}

			if ($consumed >= 1.0) {
				$status = self::STATUS_BREACHED;
			}

			$currentTargets[$idx]['status'] = $status;
		}             

		return $currentTargets;
	}                       

	   
                                                                
   
                                                            
                                                                 
                                                                  
   
                                               
                                                  
                                                
                                                                                    
                                                                                    
                                                          
   
                                                                                                
   
                                                          
   
                                                                                                                                              
    
	public function executeEscalations(
		array $policy,
		string $objectType,
		string $objectId,
		array $newTargets,
		array $priorTargets,
		int $alreadyFired,
	): array {
		unset($priorTargets);
		$chain = $policy['escalationChain'] ?? [];
		if ($chain === []) {
			return ['level' => $alreadyFired, 'eventIds' => []];
		}

		                                                             
		                                                               
		$maxConsumed = 0.0;
		$hottestKind = '';
		foreach ($newTargets as $target) {
			$consumed = (float)($target['consumedPercentage'] ?? 0.0);
			if ($consumed > $maxConsumed) {
				$maxConsumed = $consumed;
				$hottestKind = (string)($target['kind'] ?? '');
			}
		}

		$newLevel = $alreadyFired;
		$eventIds = [];

		foreach ($chain as $idx => $step) {
			$level = ($idx + 1);
			if ($level <= $alreadyFired) {
				continue;
			}

			$triggerAt = (float)($step['triggerAt'] ?? 1.0);
			if ($maxConsumed + 1e-9 < $triggerAt) {
				continue;
			}

			$eventId = $this->fireEscalation(
				policy: $policy,
				objectType: $objectType,
				objectId: $objectId,
				step: $step,
				level: $level,
				consumed: $maxConsumed,
				hottestKind: $hottestKind,
			);

			if ($eventId !== null) {
				$eventIds[] = $eventId;
			}

			$newLevel = $level;
		}             

		return ['level' => $newLevel, 'eventIds' => $eventIds];
	}                          

	   
                             
   
                                                             
                                                
   
                                                   
   
                                                          
    
	public function pauseTimer(array $slaStatus, DateTimeInterface $now): array {
		                                                                     
		                            
		if (isset($slaStatus['pausedAt']) === true) {
			return $slaStatus;
		}

		$slaStatus['pausedAt'] = $now->format(DateTimeInterface::ATOM);
		$slaStatus['lastEvaluatedAt'] = $slaStatus['pausedAt'];
		return $slaStatus;
	}                  

	   
                                                                
             
   
                                                             
                                                                     
                                                 
   
                                                   
   
                                                          
    
	public function resumeTimer(array $slaStatus, array $policy, DateTimeInterface $now): array {
		$pausedAtRaw = $slaStatus['pausedAt'] ?? null;
		if ($pausedAtRaw === null || $pausedAtRaw === '') {
			return $slaStatus;
		}

		$pausedAt = $this->parseIsoOrNull(value: $pausedAtRaw);
		if ($pausedAt === null) {
			$slaStatus['pausedAt'] = null;
			return $slaStatus;
		}

		$calendar = (string)($policy['holidayCalendar'] ?? 'nl-feestdagen-rijksoverheid');

		$newTargets = [];
		foreach (($slaStatus['targets'] ?? []) as $target) {
			$calendarType = (string)($target['calendar'] ?? BusinessHoursCalculator::CALENDAR_BUSINESS);
			                                                             
			                                                               
			                                
			$pausedMin = $this->businessHours->elapsedBusinessMinutes(
				$pausedAt,
				$now,
				$calendar,
				$calendarType,
			);

			if ($pausedMin > 0 && isset($target['dueAt']) === true) {
				$due = $this->parseIsoOrNull(value: $target['dueAt']);
				if ($due !== null) {
					                                                                        
					$extended = $this->businessHours->addDuration(
						calendarType: $calendarType,
						startTime: $due,
						duration: new DateInterval('PT' . $pausedMin . 'M'),
						holidayCalendar: $calendar,
					);
					$target['dueAt'] = $extended->format(DateTimeInterface::ATOM);
				}
			}

			$newTargets[] = $target;
		}             

		$slaStatus['targets'] = $newTargets;
		$slaStatus['totalPausedMs'] = ((int)($slaStatus['totalPausedMs'] ?? 0)) + ($this->wallClockMs(a: $pausedAt, b: $now));
		$slaStatus['pausedAt'] = null;
		$slaStatus['lastEvaluatedAt'] = $now->format(DateTimeInterface::ATOM);
		return $slaStatus;
	}                   

	   
                                                                      
             
   
                                               
                                                      
   
                                                       
   
                                                          
    
	public function initialiseStatus(array $policy, DateTimeInterface $startTime): array {
		return [
			'policyId' => $this->policyIdentity(policy: $policy),
			'startedAt' => $startTime->format(DateTimeInterface::ATOM),
			'pausedAt' => null,
			'totalPausedMs' => 0,
			'targets' => $this->computeDeadlines(policy: $policy, startTime: $startTime),
			'currentEscalationLevel' => 0,
			'lastEvaluatedAt' => $startTime->format(DateTimeInterface::ATOM),
		];
	}                        

	   
                                                   
   
                                                             
                                            
                                               
   
                                                   
   
                                                          
    
	public function markTargetMet(array $slaStatus, string $kind, DateTimeInterface $when): array {
		$targets = $slaStatus['targets'] ?? [];
		foreach ($targets as $idx => $target) {
			if (($target['kind'] ?? '') !== $kind) {
				continue;
			}

			if (($target['status'] ?? '') === self::STATUS_BREACHED) {
				continue;
			}

			$targets[$idx]['status'] = self::STATUS_MET;
			$targets[$idx]['withAt'] = $when->format(DateTimeInterface::ATOM);
		}

		$slaStatus['targets'] = $targets;
		$slaStatus['lastEvaluatedAt'] = $when->format(DateTimeInterface::ATOM);
		return $slaStatus;
	}                     

	   
                                                              
   
                                                                     
                                                        
   
                                                             
   
                                                          
    
	public function loadActivePolicies(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_policy_schema', '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: ObjectService not available',
				['error' => $e->getMessage()]
			);
			return [];
		}

		try {
			                                                                  
			                                                              
			                                                                  
			                                                       
			                                                                   
			                                                                    
			                 
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 500,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: ObjectService::findAll failed',
				['error' => $e->getMessage()]
			);
			return [];
		}

		$policies = [];
		foreach ((array)$rows as $row) {
			$array = $this->normaliseObject(row: $row);
			if (($array['status'] ?? 'published') === 'archived') {
				continue;
			}

			if (($array['active'] ?? true) === false) {
				continue;
			}

			$policies[] = $array;
		}

		return $policies;
	}                          

	   
                                                                 
   
                                               
                                          
                                      
                                                             
                                                     
                                                     
   
                                                      
    
	private function policyApplies(
		array $policy,
		string $objectType,
		string $tier,
		string $organisationId,
		string $contractId,
		DateTimeInterface $now,
	): bool {
		if ($this->matchesAppliesTo(policy: $policy, objectType: $objectType) === false) {
			return false;
		}

		if ($this->matchesTier(policy: $policy, tier: $tier) === false) {
			return false;
		}

		if ($this->matchesScope(policy: $policy, organisationId: $organisationId, contractId: $contractId) === false) {
			return false;
		}

		return $this->isValidAt(policy: $policy, now: $now);
	}                     

	   
                                                             
   
                                               
                                          
   
                
    
	private function matchesAppliesTo(array $policy, string $objectType): bool {
		$applies = (string)($policy['appliesTo'] ?? '*');
		return ($applies === '*' || $applies === $objectType);
	}                        

	   
                                                               
                                                 
   
                                               
                                      
   
                
    
	private function matchesTier(array $policy, string $tier): bool {
		$policyTier = (string)($policy['customerTier'] ?? '*');
		$effectiveTier = 'bronze';
		if ($tier !== '') {
			$effectiveTier = $tier;
		}

		return ($policyTier === '*' || $policyTier === $effectiveTier);
	}                   

	   
                                                               
                                                            
   
                                               
                                                            
                                                    
   
                
    
	private function matchesScope(array $policy, string $organisationId, string $contractId): bool {
		$scope = $policy['customerScope'] ?? null;
		if (is_array($scope) === false) {
			return true;
		}

		$orgs = (array)($scope['organisationIds'] ?? []);
		$contracts = (array)($scope['contractIds'] ?? []);
		if ($orgs !== [] && in_array($organisationId, $orgs, true) === false) {
			return false;
		}

		if ($contracts !== [] && in_array($contractId, $contracts, true) === false) {
			return false;
		}

		return true;
	}                    

	   
                                                                         
   
                                               
                                                     
   
                
    
	private function isValidAt(array $policy, DateTimeInterface $now): bool {
		$validFrom = $this->parseIsoOrNull(value: ($policy['validFrom'] ?? null));
		$validUntil = $this->parseIsoOrNull(value: ($policy['validUntil'] ?? null));
		if ($validFrom !== null && $now < $validFrom) {
			return false;
		}

		if ($validUntil !== null && $now > $validUntil) {
			return false;
		}

		return true;
	}                 

	   
                                                                
   
                                               
                                                    
                                                            
   
                                                     
    
	private function scopeSpecificity(array $policy, string $contractId, string $organisationId): int {
		$scope = $policy['customerScope'] ?? null;
		if (is_array($scope) === false) {
			                       
			if (($policy['customerTier'] ?? '*') === '*') {
				return 0;
			}

			return 1;
		}

		$contracts = (array)($scope['contractIds'] ?? []);
		$orgs = (array)($scope['organisationIds'] ?? []);
		if ($contracts !== [] && $contractId !== '' && in_array($contractId, $contracts, true) === true) {
			return 4;
		}

		if ($orgs !== [] && $organisationId !== '' && in_array($organisationId, $orgs, true) === true) {
			return 3;
		}

		return 2;
	}                        

	   
                                                                   
   
                                               
                                                  
                                                
                                                      
                                                 
                                                         
                                                                        
   
                                                          
    
	private function fireEscalation(
		array $policy,
		string $objectType,
		string $objectId,
		array $step,
		int $level,
		float $consumed,
		string $hottestKind,
	): ?string {
		$channel = (string)($step['channel'] ?? 'nextcloud-notification');
		$notify = (string)($step['notify'] ?? 'assignee');

		try {
			$notifiedActors = $this->dispatchNotification(
				channel: $channel,
				notify: $notify,
				policy: $policy,
				objectType: $objectType,
				objectId: $objectId,
				level: $level,
				step: $step
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: notification dispatch failed',
				['error' => $e->getMessage(), 'channel' => $channel]
			);
			$notifiedActors = ['failed:' . $channel];
		}

		$kind = $hottestKind;
		if ($hottestKind === '') {
			$kind = 'resolution';
		}

		return $this->writeBreachEvent(
			policy: $policy,
			objectType: $objectType,
			objectId: $objectId,
			kind: $kind,
			level: $level,
			consumed: $consumed,
			channel: $channel,
			actors: $notifiedActors,
		);
	}                      

	   
                                                             
   
                                                                     
                                                                     
                                                                      
                                                                         
                                                                         
                                                                           
                                                                           
                                                
   
                                        
                                     
                                               
                                          
                                        
                                       
                                                                                 
   
                                                                    
   
                                                                                                 
    
	private function dispatchNotification(
		string $channel,
		string $notify,
		array $policy,
		string $objectType,
		string $objectId,
		int $level,
		array $step = [],
	): array {
		if ($channel === 'nextcloud-notification') {
			return $this->dispatchNextcloudNotification(
				notify: $notify,
				policy: $policy,
				objectType: $objectType,
				objectId: $objectId,
				level: $level
			);
		}

		if ($channel === 'sms' || $channel === 'whatsapp') {
			return $this->dispatchChannelAdapter(
				channel: $channel,
				notify: $notify,
				policy: $policy,
				objectType: $objectType,
				objectId: $objectId,
				level: $level,
				step: $step
			);
		}

		                                                                  
		                                             
		return ['deferred:' . $channel . ':' . $notify];
	}                            

	   
                                                                   
   
                                     
                                               
                                          
                                        
                                       
   
                                                                    
    
	private function dispatchNextcloudNotification(
		string $notify,
		array $policy,
		string $objectType,
		string $objectId,
		int $level,
	): array {
		$actor = $this->resolveActor(role: $notify, policy: $policy);
		if ($actor === null) {
			return ['unresolved:' . $notify];
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($actor)
				->setDateTime(new DateTime())
				->setObject($objectType, $objectId)
				->setSubject('sla_breach', ['level' => $level, 'policy' => (string)($policy['name'] ?? '')])
				->setMessage('sla_breach_message', ['level' => $level]);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: nextcloud-notification dispatch failed',
				['error' => $e->getMessage(), 'actor' => $actor]
			);
			return ['failed:nextcloud-notification:' . $actor];
		}

		return [$actor];
	}                                     

	   
                                                                          
   
                                                                      
                                                                        
                                                        
   
                                               
                                     
                                               
                                          
                                        
                                       
                                                                                 
   
                                                                    
   
                                                                                                 
    
	private function dispatchChannelAdapter(
		string $channel,
		string $notify,
		array $policy,
		string $objectType,
		string $objectId,
		int $level,
		array $step,
	): array {
		if ($notify !== 'customer') {
			return ['unsupported:' . $channel . ':' . $notify];
		}

		$contact = $this->resolveCustomerContact(objectType: $objectType, objectId: $objectId);
		if ($contact === null) {
			return ['unresolved:' . $notify];
		}

		$contactId = (string)($contact['uuid'] ?? ($contact['id'] ?? ''));
		$context = [
			'clientId' => (string)($contact['client'] ?? ''),
			'agent' => 'system:sla-engine',
		];

		if ($channel === 'sms') {
			return $this->dispatchSmsEscalation(
				contact: $contact,
				contactId: $contactId,
				policy: $policy,
				level: $level,
				context: $context
			);
		}

		return $this->dispatchWhatsAppEscalation(
			contact: $contact,
			contactId: $contactId,
			policy: $policy,
			level: $level,
			step: $step,
			context: $context
		);
	}                              

	   
                                                  
   
                                                                      
                                                           
                                               
                                       
                                                                        
   
                                                                    
    
	private function dispatchSmsEscalation(
		array $contact,
		string $contactId,
		array $policy,
		int $level,
		array $context,
	): array {
		$adapter = $this->resolveMessagingAdapter(class: 'OCA\\Pipelinq\\Service\\SmsAdapter');
		if ($adapter === null) {
			return ['failed:sms'];
		}

		try {
			$outcome = $adapter->send(
				contact: $contact,
				body: $this->renderBreachMessage(policy: $policy, level: $level),
				providerHint: null,
				context: $context
			);
		} catch (Throwable $e) {
			$this->logger->warning('SlaEngineService: SMS escalation dispatch threw', ['error' => $e->getMessage()]);
			return ['failed:sms'];
		}

		return $this->markerFromOutcome(channel: 'sms', contactId: $contactId, outcome: $outcome);
	}                             

	   
                                                           
   
                                                                           
                                                                          
                                                                
   
                                                                      
                                            
                                               
                                       
                                                                                 
                                                                        
   
                                                                    
    
	private function dispatchWhatsAppEscalation(
		array $contact,
		string $contactId,
		array $policy,
		int $level,
		array $step,
		array $context,
	): array {
		$adapter = $this->resolveMessagingAdapter(class: 'OCA\\Pipelinq\\Service\\WhatsAppAdapter');
		if ($adapter === null) {
			return ['failed:whatsapp'];
		}

		$templateId = trim((string)($step['templateId'] ?? ''));
		$body = '';
		$template = $templateId;
		if ($templateId === '') {
			$withinWindow = false;
			                                                                              
			                                                                            
			                                                                      
			if ($adapter instanceof WhatsAppAdapter) {
				$withinWindow = (bool)$adapter->isWithinSessionWindow(contactId: $contactId);
			}

			if ($withinWindow === false) {
				return ['template-missing:whatsapp'];
			}

			$body = $this->renderBreachMessage(policy: $policy, level: $level);
			$template = '';
		}

		$sendTemplateId = null;
		if ($template !== '') {
			$sendTemplateId = $template;
		}

		try {
			$outcome = $adapter->send(
				contact: $contact,
				body: $body,
				templateId: $sendTemplateId,
				parameters: [],
				context: $context
			);
		} catch (Throwable $e) {
			$this->logger->warning('SlaEngineService: WhatsApp escalation dispatch threw', ['error' => $e->getMessage()]);
			return ['failed:whatsapp'];
		}

		return $this->markerFromOutcome(channel: 'whatsapp', contactId: $contactId, outcome: $outcome);
	}                                  

	   
                                                                              
   
                                               
                                                               
                                                                  
   
                                                                    
    
	private function markerFromOutcome(string $channel, string $contactId, array $outcome): array {
		$status = (string)($outcome['status'] ?? '');
		if ($status === 'sent') {
			$actor = $contactId;
			if ($actor === '') {
				$actor = 'customer';
			}

			return [$actor];
		}

		if ($status === 'consentMissing') {
			return ['consent-missing:' . $channel];
		}

		if ($status === 'sessionWindowExpired') {
			return ['template-missing:' . $channel];
		}

		return ['failed:' . $channel . ':' . $status];
	}                         

	   
                                                                      
   
                                      
   
                                                              
    
	private function resolveMessagingAdapter(string $class): ?object {
		try {
			$adapter = $this->container->get($class);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: messaging adapter unavailable',
				['class' => $class, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($adapter) === false || method_exists($adapter, 'send') === false) {
			return null;
		}

		return $adapter;
	}                               

	   
                                                                                 
   
                                                                
                                                
   
                                                                 
    
	private function resolveCustomerContact(string $objectType, string $objectId): ?array {
		$targetSchema = $this->appConfig->getValueString(Application::APP_ID, 'sla_target_schema_' . $objectType, '');
		if ($targetSchema === '') {
			$targetSchema = $objectType;
		}

		$target = $this->loadPipelinqObject(schema: $targetSchema, id: $objectId);
		if ($target === null) {
			return null;
		}

		$contactSchema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($contactSchema === '') {
			$contactSchema = 'contact';
		}

		$contactId = (string)($target['contact'] ?? '');
		if ($contactId !== '') {
			$contact = $this->loadPipelinqObject(schema: $contactSchema, id: $contactId);
			if ($contact !== null) {
				return $contact;
			}
		}

		$clientSchema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($clientSchema === '') {
			$clientSchema = 'client';
		}

		$clientId = (string)($target['client'] ?? '');
		if ($clientId !== '') {
			return $this->loadPipelinqObject(schema: $clientSchema, id: $clientId);
		}

		return null;
	}                              

	   
                                                                      
   
                                      
                                  
   
                                                                     
    
	private function loadPipelinqObject(string $schema, string $id): ?array {
		if ($schema === '' || $id === '') {
			return null;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: target object load failed',
				['schema' => $schema, 'id' => $id, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->normaliseObject(row: $entity);
	}                          

	   
                                                                             
   
                                               
                                       
   
                                             
    
	private function renderBreachMessage(array $policy, int $level): string {
		$name = (string)($policy['name'] ?? 'SLA');
		return sprintf(
			'Uw serviceverzoek nadert de afgesproken doorlooptijd (%s, escalatieniveau %d). Ons team pakt dit met prioriteit op.',
			$name,
			$level
		);
	}                           

	   
                                                                  
   
                                               
                                                                     
                                  
   
                                   
                                                                                          
   
                                                  
   
                                                  
    
	private function resolveActor(string $role, array $policy): ?string {
		unset($policy);
		$key = 'sla_actor_' . preg_replace('/[^a-z0-9_-]/i', '_', $role);
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($value !== '') {
			return $value;
		}

		$fallback = $this->appConfig->getValueString(Application::APP_ID, 'sla_actor_fallback', '');
		if ($fallback === '') {
			return null;
		}

		return $fallback;
	}                    

	   
                                          
   
                                               
                                          
                                        
                                    
                                       
                                               
                                        
                                                      
   
                                                          
    
	private function writeBreachEvent(
		array $policy,
		string $objectType,
		string $objectId,
		string $kind,
		int $level,
		float $consumed,
		string $channel,
		array $actors,
	): ?string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'sla_register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'sla_breach_event_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->debug('SlaEngineService: breach register/schema not configured; skipping audit row');
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: ObjectService unavailable for breach event write',
				['error' => $e->getMessage()]
			);
			return null;
		}

		$payload = [
			'policyId' => $this->policyIdentity(policy: $policy),
			'targetObjectType' => $objectType,
			'targetObjectId' => $objectId,
			'targetKind' => $kind,
			'breachedAt' => $this->now()->format(DateTimeInterface::ATOM),
			'consumedPercentage' => round($consumed, 4),
			'escalationLevel' => $level,
			'notifiedActors' => $actors,
			'channel' => $channel,
			'acknowledged' => false,
		];

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
			);

			$uuid = '';
			if (is_array($saved) === true) {
				$uuid = (string)($saved['uuid'] ?? $saved['id'] ?? '');
			} elseif (is_object($saved) === true) {
				                                                                    
				                                                                   
				                                                                   
				                              
				$uuid = $this->readEntityValue(entity: $saved, getter: 'getUuid');
			}

			if ($uuid === '') {
				return null;
			}

			return $uuid;
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaEngineService: failed to write breach event',
				['error' => $e->getMessage()]
			);
			return null;
		}         
	}                        

	   
                                                                        
   
                                               
   
                            
   
                                                          
    
	public function policyIdentity(array $policy): string {
		if (isset($policy['id']) === true && $policy['id'] !== '') {
			return (string)$policy['id'];
		}

		if (isset($policy['uuid']) === true && $policy['uuid'] !== '') {
			return (string)$policy['uuid'];
		}

		return (string)($policy['@self']['slug'] ?? ($policy['slug'] ?? ''));
	}                      

	   
                                                          
   
                                                                    
   
                                         
   
                                                          
    
	public function parseDuration(string $duration): DateInterval {
		if ($duration === '') {
			return new DateInterval('PT0S');
		}

		try {
			return new DateInterval($duration);
		} catch (Exception $e) {
			$this->logger->warning(
				'SlaEngineService: invalid ISO 8601 duration; falling back to PT0S',
				['duration' => $duration, 'error' => $e->getMessage()]
			);
			return new DateInterval('PT0S');
		}
	}                     

	   
                                                              
   
                                  
   
                                              
   
                                                          
    
	public function parseIsoOrNull($value): ?DateTimeImmutable {
		if ($value === null || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable((string)$value);
		} catch (Throwable $e) {
			return null;
		}
	}                      

	   
                                                        
   
                                                            
   
                                             
    
	private function normaliseObject($row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true) {
			if (method_exists($row, 'getObject') === true) {
				$object = $row->getObject();
				if (is_array($object) === true) {
					if (method_exists($row, 'getUuid') === true) {
						$object['id'] = $object['id'] ?? (string)$row->getUuid();
					}

					return $object;
				}
			}

			if (method_exists($row, 'jsonSerialize') === true) {
				$json = $row->jsonSerialize();
				if (is_array($json) === true) {
					return $json;
				}
			}
		}

		return [];
	}                       

	   
                                                 
   
                                                
                                              
   
                                   
    
	private function wallClockMs(DateTimeInterface $a, DateTimeInterface $b): int {
		$delta = $b->getTimestamp() - $a->getTimestamp();
		if ($delta > 0) {
			return ($delta * 1000);
		}

		return 0;
	}                   

	   
                           
   
                              
   
                                  
    
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}           
}           
