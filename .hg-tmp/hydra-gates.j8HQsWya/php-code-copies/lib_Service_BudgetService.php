<?php

   
                          
  
                                                                 
                                                                      
                                                                  
                                                            
            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                             
  
                       
                                                              
                                                             
                           
                                                                  
                                                                  
                                     
                                                                  
                                               
  
                                                                   
  
                                                                                                                                
   
class BudgetService {
	   
                                          
    
	private const DEFAULT_SCHEMA_SLUG = 'messageSendBudget';

	   
                                   
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                
   
                                                      
                                            
                                                                        
                                          
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                  
   
                                      
                                            
                                                                    
   
                                                             
                                             
   
                                                                    
    
	public function canSend(string $tenantId, string $providerId, float $estimatedCostEur = 0.0): bool {
		$budget = $this->loadBudget(tenantId: $tenantId, providerId: $providerId);
		if ($budget === null) {
			return true;
		}

		$hardStop = (bool)($budget['hardStop'] ?? false);
		if ($hardStop === false) {
			return true;
		}

		$maxMessages = (int)($budget['maxMessages'] ?? 0);
		$currentMsgs = (int)($budget['currentPeriodMessages'] ?? 0);
		$maxCostEur = (float)($budget['maxCostEur'] ?? 0);
		$currentEur = (float)($budget['currentPeriodCostEur'] ?? 0);

		if ($maxMessages > 0 && ($currentMsgs + 1) > $maxMessages) {
			return false;
		}

		if ($maxCostEur > 0 && ($currentEur + $estimatedCostEur) > $maxCostEur) {
			return false;
		}

		return true;
	}               

	   
                                                
   
                                                                
                                                     
                                                      
   
                                      
                                            
                                                          
   
                
   
                                                                    
    
	public function recordSend(string $tenantId, string $providerId, float $costEur = 0.0): void {
		$budget = $this->loadBudget(tenantId: $tenantId, providerId: $providerId);
		if ($budget === null) {
			return;
		}

		$newMessages = ((int)($budget['currentPeriodMessages'] ?? 0)) + 1;
		$newCost = ((float)($budget['currentPeriodCostEur'] ?? 0)) + $costEur;
		$maxMessages = (int)($budget['maxMessages'] ?? 0);
		$maxCost = (float)($budget['maxCostEur'] ?? 0);
		$thresholdPct = (float)($budget['alertThresholdPct'] ?? 0.8);
		$alertedAt = (string)($budget['alertedAtPeriodStart'] ?? '');

		$payload = $budget;
		$payload['currentPeriodMessages'] = $newMessages;
		$payload['currentPeriodCostEur'] = $newCost;

		                                                             
		                                       
		$shouldAlert = false;
		if ($alertedAt === '') {
			if ($maxMessages > 0 && $newMessages >= (int)ceil($maxMessages * $thresholdPct)) {
				$shouldAlert = true;
			}

			if ($maxCost > 0 && $newCost >= ($maxCost * $thresholdPct)) {
				$shouldAlert = true;
			}
		}

		if ($shouldAlert === true) {
			$payload['alertedAtPeriodStart'] = $this->nowIso();
			$this->fireAlert(tenantId: $tenantId, providerId: $providerId, budget: $payload);
		}

		$this->saveObject(payload: $payload, id: $this->extractId(payload: $budget));
	}                  

	   
                                                  
   
                                                                 
                                                                 
               
   
                                     
   
                                                                    
    
	public function resetPeriods(): int {
		$rows = $this->loadAllBudgets();
		if ($rows === []) {
			return 0;
		}

		$reset = 0;
		$now = gmdate('Y-m-d\TH:i:s\Z');
		foreach ($rows as $row) {
			$resetAt = (string)($row['periodResetAt'] ?? '');
			if ($resetAt === '' || strcmp($resetAt, $now) > 0) {
				continue;
			}

			$payload = $row;
			$payload['currentPeriodMessages'] = 0;
			$payload['currentPeriodCostEur'] = 0;
			$payload['alertedAtPeriodStart'] = '';
			$payload['periodResetAt'] = $this->advancePeriod(
				from: $resetAt,
				period: (string)($row['period'] ?? 'monthly'),
			);

			$this->saveObject(payload: $payload, id: $this->extractId(payload: $row));
			$reset++;
		}

		return $reset;
	}                    

	   
                                                            
   
                                      
                                            
   
                                                         
    
	private function loadBudget(string $tenantId, string $providerId): ?array {
		if ($tenantId === '' || $providerId === '') {
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
						'tenantId' => $tenantId,
						'providerId' => $providerId,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.loadBudget: findAll failed',
				['tenantId' => $tenantId, 'providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($rows === null || $rows === []) {
			return null;
		}

		$first = $rows[0];
		return $this->toArray(value: $first);
	}                  

	   
                          
   
                                                         
    
	private function loadAllBudgets(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.loadAllBudgets: findAll failed',
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

	   
                             
   
                                                                          
                                                                           
   
                                                 
                                           
   
                                                
   
                                                                                
                                                                                      
    
	private function saveObject(array $payload, string $id = ''): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saveUuid = null;
			if ($id !== '') {
				$saveUuid = $id;
			}

			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
				uuid: $saveUuid,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.saveObject: save failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}                  

	   
                                                   
   
                                      
                                            
                                                        
   
                
    
	private function fireAlert(string $tenantId, string $providerId, array $budget): void {
		try {
			$this->notificationService->sendNotification(
				userId: 'admin',
				subject: 'messaging_budget_threshold',
				parameters: [
					'tenantId' => $tenantId,
					'providerId' => $providerId,
					'period' => (string)($budget['period'] ?? 'monthly'),
					'messages' => (int)($budget['currentPeriodMessages'] ?? 0),
					'costEur' => (float)($budget['currentPeriodCostEur'] ?? 0),
				],
				objectType: 'messageSendBudget',
				objectId: $this->extractId(payload: $budget),
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.fireAlert: notification failed',
				['tenantId' => $tenantId, 'exception' => $e->getMessage()]
			);
		}
	}                 

	   
                                              
   
                                              
                                                         
   
                                      
    
	private function advancePeriod(string $from, string $period): string {
		$modifier = match ($period) {
			'daily' => '+1 day',
			'weekly' => '+1 week',
			default => '+1 month',
		};

		try {
			$dateTime = new DateTimeImmutable($from, new DateTimeZone('UTC'));
			return $dateTime->modify($modifier)->format('Y-m-d\TH:i:s\Z');
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.advancePeriod: parse failed',
				['from' => $from, 'period' => $period, 'exception' => $e->getMessage()]
			);
			return gmdate('Y-m-d\TH:i:s\Z', (time() + (30 * 86400)));
		}
	}                     

	   
                                       
   
                                        
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'BudgetService.getObjectService: OpenRegister unavailable',
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

	   
                                           
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                         
   
                        
    
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'messageSendBudget_schema',
			''
		);

		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}                     

	   
                                   
   
                             
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
