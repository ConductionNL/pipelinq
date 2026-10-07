<?php

   
                            
  
                                                                         
                                                                        
                                                                     
                                                     
  
                                                                       
                                                               
                                                          
                                                                      
                                                                     
                                                          
                                                                          
                                                                            
                                                                
  
                                                                             
                                                                                   
                                                                      
                                                            
                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                                    
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                          
  
                                                                             
                                                                
                                                                             
                                                                             
                                                          
  
                                                                    
  
                                                                                
                                                                                 
   
class WorklistService {
	   
                                                                  
   
                           
    
	public const PRIORITY_ORDER = [
		'urgent' => 0,
		'high' => 1,
		'normal' => 2,
		'low' => 3,
	];

	   
                                                        
                                                    
   
            
    
	private const DEFAULT_PRIORITY_RANK = 2;

	   
                                                                  
                                          
   
                           
    
	public const TERMINAL_REQUEST_STATUSES = ['completed', 'rejected', 'converted'];

	   
                                                                    
                                                             
   
                           
    
	public const TERMINAL_TICKET_STATUSES = ['resolved', 'completed', 'rejected', 'converted', 'closed'];

	   
                                                                      
   
                           
    
	private const WORK_TICKET_TYPES = [
		TicketService::TYPE_REQUEST,
		TicketService::TYPE_COMPLAINT,
		TicketService::TYPE_CONTACTMOMENT,
	];

	   
                                                                           
                                                              
                         
   
            
    
	private const REQUEST_OVERDUE_AGE_SECONDS = 2592000;

	   
                                                                
                                                                    
                                                   
   
            
    
	private const LEAD_FETCH_LIMIT = 200;

	   
                                                                     
   
            
    
	private const REQUEST_FETCH_LIMIT = 200;

	   
                                                              
   
            
    
	private const PIPELINE_FETCH_LIMIT = 100;

	   
                                                                   
                                                                     
                                                         
   
                              
    
	private const STATUS_LABELS = [
		'new' => 'New',
		'in_progress' => 'In progress',
		'completed' => 'Completed',
		'rejected' => 'Rejected',
		'converted' => 'Converted to case',
	];

	   
                
   
                                                                         
                                                            
                                              
                                                                               
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private readonly TicketService $ticketService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                    
   
            
                                                                         
                                                                       
                                                     
                                                                   
                                             
                                                
   
                                                                                  
                                                                                     
   
                  
                                              
                 
                     
                       
     
   
                                                              
   
                                                                     
                                                                              
    
	public function getMine(string $userId, ?int $limit = null): array {
		$leads = $this->findObjects(
			schemaKey: 'lead_schema',
			filters: ['assignee' => $userId],
			limit: self::LEAD_FETCH_LIMIT
		);
		                                                                
		                                                                         
		$requests = [];
		foreach (self::WORK_TICKET_TYPES as $ticketType) {
			$requests = array_merge(
				$requests,
				$this->findTickets(
					ticketType: $ticketType,
					filters: ['assignee' => $userId],
					limit: self::REQUEST_FETCH_LIMIT
				)
			);
		}

		$pipelines = $this->findObjects(
			schemaKey: 'pipeline_schema',
			filters: [],
			limit: self::PIPELINE_FETCH_LIMIT
		);

		$closedStages = $this->getClosedStageNames(pipelines: $pipelines);
		$nowTs = (new DateTimeImmutable())->getTimestamp();

		$items = array_merge(
			$this->buildLeadRows(leads: $leads, closedStages: $closedStages, nowTs: $nowTs),
			$this->buildRequestRows(requests: $requests, nowTs: $nowTs)
		);

		                                                                     
		                                          
		usort($items, $this->compareRows(...));

		$total = count($items);
		$leadCount = 0;
		$requestCount = 0;
		foreach ($items as $item) {
			if ($item['entityType'] === 'lead') {
				$leadCount++;
				continue;
			}

			$requestCount++;
		}

		if ($limit !== null) {
			$items = array_slice($items, 0, $limit);
		}

		$rows = [];
		foreach ($items as $item) {
			                                                         
			unset($item['dueTimestamp']);
			$rows[] = $item;
		}

		return [
			'items' => $rows,
			'total' => $total,
			'leadCount' => $leadCount,
			'requestCount' => $requestCount,
		];
	}               

	   
                                                  
   
                                                                      
                                                                    
                                                               
   
                                                                               
                                                               
                                             
   
                                            
    
	private function buildLeadRows(array $leads, array $closedStages, int $nowTs): array {
		$rows = [];
		foreach ($leads as $lead) {
			$stage = (string)($lead['stage'] ?? '');
			if (in_array($stage, $closedStages, true) === true) {
				                                                                            
				continue;
			}

			$dueDate = $this->normalizeDate(value: ($lead['expectedCloseDate'] ?? null));
			$dueTs = $this->toTimestamp(value: $dueDate);

			$stageOrStatus = '-';
			if ($stage !== '') {
				$stageOrStatus = $stage;
			}

			$priority = (string)($lead['priority'] ?? '');
			if ($priority === '') {
				$priority = 'normal';
			}

			$isOverdue = false;
			if ($dueTs !== null && $dueTs < $nowTs) {
				                                                                              
				$isOverdue = true;
			}

			$rows[] = [
				'entityType' => 'lead',
				'id' => (string)($lead['id'] ?? ''),
				'title' => (string)($lead['title'] ?? ''),
				'stageOrStatus' => $stageOrStatus,
				'priority' => $priority,
				'dueDate' => $dueDate,
				'isOverdue' => $isOverdue,
				'routeName' => 'LeadDetail',
				'dueTimestamp' => $dueTs,
			];
		}             

		return $rows;
	}                     

	   
                                                     
   
                                                               
                                                       
                                                                          
                                                                            
   
                                                                                            
                                             
   
                                            
    
	private function buildRequestRows(array $requests, int $nowTs): array {
		$rows = [];
		foreach ($requests as $request) {
			$status = (string)($request['status'] ?? '');
			if (in_array($status, self::TERMINAL_TICKET_STATUSES, true) === true) {
				                                                               
				continue;
			}

			$dueDate = $this->normalizeDate(value: ($request['occurredAt'] ?? null));
			$dueTs = $this->toTimestamp(value: $dueDate);

			$priority = (string)($request['priority'] ?? '');
			if ($priority === '') {
				$priority = 'normal';
			}

			$isOverdue = false;
			if ($dueTs !== null && ($nowTs - $dueTs) > self::REQUEST_OVERDUE_AGE_SECONDS) {
				                                                                         
				$isOverdue = true;
			}

			$rows[] = [
				'entityType' => 'request',
				'ticketType' => (string)($request['ticketType'] ?? TicketService::TYPE_REQUEST),
				'id' => (string)($request['id'] ?? ''),
				'title' => (string)($request['title'] ?? ''),
				'stageOrStatus' => $this->statusLabel(status: $status),
				'priority' => $priority,
				'dueDate' => $dueDate,
				'isOverdue' => $isOverdue,
				                                                                 
				                                                               
				'routeName' => 'TicketDetail',
				'dueTimestamp' => $dueTs,
			];
		}             

		return $rows;
	}                        

	   
                                                                     
   
                                                                    
                                                                  
                                            
   
                                            
                                             
   
                                  
    
	private function compareRows(array $a, array $b): int {
		                                              
		if ($a['isOverdue'] !== $b['isOverdue']) {
			if ($a['isOverdue'] === true) {
				return -1;
			}

			return 1;
		}

		                                            
		$rankA = (self::PRIORITY_ORDER[$a['priority']] ?? self::DEFAULT_PRIORITY_RANK);
		$rankB = (self::PRIORITY_ORDER[$b['priority']] ?? self::DEFAULT_PRIORITY_RANK);
		if ($rankA !== $rankB) {
			return ($rankA <=> $rankB);
		}

		                                                           
		                              
		return $this->compareDueDates(a: $a, b: $b);
	}                   

	   
                                                                       
                                                                   
                                         
   
                                            
                                             
   
                                  
    
	private function compareDueDates(array $a, array $b): int {
		$hasDueA = $this->hasDueDate(row: $a);
		$hasDueB = $this->hasDueDate(row: $b);
		if ($hasDueA === true && $hasDueB === true) {
			if ($a['dueTimestamp'] === null || $b['dueTimestamp'] === null) {
				                                                             
				                                                       
				return 0;
			}

			return ($a['dueTimestamp'] <=> $b['dueTimestamp']);
		}

		if ($hasDueA === true) {
			return -1;
		}

		if ($hasDueB === true) {
			return 1;
		}

		return 0;
	}                       

	   
                                                              
                                                                        
   
                                                      
   
                
    
	private function hasDueDate(array $row): bool {
		return ($row['dueDate'] ?? null) !== null && $row['dueDate'] !== '';
	}                  

	   
                                                      
   
                                                                              
   
                                                                     
   
                                                         
    
	private function getClosedStageNames(array $pipelines): array {
		$names = [];
		foreach ($pipelines as $pipeline) {
			$stages = ($pipeline['stages'] ?? null);
			if (is_array($stages) === false) {
				continue;
			}

			foreach ($stages as $stage) {
				if (is_array($stage) === false) {
					continue;
				}

				if (empty($stage['isClosed']) === true) {
					                                                       
					continue;
				}

				$names[] = (string)($stage['name'] ?? '');
			}
		}

		return array_values(array_unique($names));
	}                           

	   
                                                                  
                                                                  
                        
   
                                             
   
                  
    
	private function statusLabel(string $status): string {
		if (isset(self::STATUS_LABELS[$status]) === true) {
			return $this->l10n->t(self::STATUS_LABELS[$status]);
		}

		return $status;
	}                   

	   
                                                                 
   
                                                          
   
                       
    
	private function normalizeDate(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}                     

	   
                                                                   
   
                                                                  
   
                    
    
	private function toTimestamp(?string $value): ?int {
		if ($value === null) {
			return null;
		}

		$timestamp = strtotime($value);
		if ($timestamp === false) {
			return null;
		}

		return $timestamp;
	}                   

	   
                                                              
   
                                                                            
                                                                    
                                                                         
                                                                            
                                                                           
                                                                          
                                                
   
                                                                         
                                                                     
                                                       
   
                                            
   
                                                                                                                          
    
	private function findTickets(string $ticketType, array $filters, int $limit): array {
		$results = $this->ticketService->findByType(
			ticketType: $ticketType,
			extraFilters: $filters,
			limit: $limit
		);

		$objects = [];
		foreach ($results as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}                   

	   
                                                                        
   
                                                                  
                                                                   
                                                                 
                                                                  
                                         
   
                                                                      
                                                                     
                                                       
   
                                            
   
                                                                        
    
	private function findObjects(string $schemaKey, array $filters, int $limit): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->info(
				message: '[WorklistService] register or schema not configured',
				context: ['schemaKey' => $schemaKey]
			);
			return [];
		}

		$allFilters = array_merge(['register' => $register, 'schema' => $schema], $filters);

		try {
			$objectService = $this->getObjectService();
			$results = $objectService->findAll(
				config: [
					'filters' => $allFilters,
					'limit' => $limit,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[WorklistService] findAll failed',
				context: ['schemaKey' => $schemaKey, 'error' => $e->getMessage()]
			);
			throw new RuntimeException(message: 'Worklist query failed', code: 0, previous: $e);
		}

		$objects = [];
		foreach (($results ?? []) as $result) {
			$objects[] = $this->toArray(object: $result);
		}

		return $objects;
	}                   

	   
                                                                 
   
                                                          
   
                                
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}               

	   
                                                            
   
                                              
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
