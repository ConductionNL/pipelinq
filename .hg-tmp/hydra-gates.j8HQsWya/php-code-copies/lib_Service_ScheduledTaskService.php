<?php

   
                                 
  
                                                             
                                                           
                                                               
                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Service\LanguageService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                 
  
                                                                           
                                                                      
                                                                             
  
                                                                           
                                                                             
                                                                              
                                                                        
                                                                              
                                                                       
                                                                              
  
                                                              
   
class ScheduledTaskService {
	   
                     
   
                      
    
	public const VALID_TYPES = [
		'callbackRequest',
		'followUpTask',
		'informationRequest',
	];

	   
                                                       
   
                                                                     
                                                           
                                                                         
                                                                     
   
                      
    
	public const MUTABLE_FIELDS = [
		'type',
		'subject',
		'description',
		'priority',
		'deadline',
		'clientId',
		'requestId',
		'contactMomentSummary',
		'callbackPhoneNumber',
		'preferredTimeSlot',
		'resultText',
	];

	   
                                                                  
   
            
    
	private const MAX_WINDOW_MINUTES = 1440;

	   
                                                                      
   
            
    
	private const EXPIRY_THRESHOLD_MINUTES = 240;

	   
                
   
                                                                      
                                                                         
                                                                            
                                                                          
                                          
                                                                           
                                                                                         
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly NotificationService $notificationService,
		private readonly LoggerInterface $logger,
		private readonly LanguageService $languageService,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                              
   
                                                                     
                                                                                
   
                                                                         
   
                                                                                             
   
                                                               
    
	public function getScheduledTasks(array $params): array {
		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return [
				'items' => [],
				'total' => 0,
				'page' => 1,
				'pages' => 0,
			];
		}

		$page = max(1, (int)($params['_page'] ?? 1));
		$limit = min(100, max(1, (int)($params['_limit'] ?? 20)));

		$filters = $this->buildTaskFilters(registerId: $registerId, schemaId: $schemaId, params: $params);

		$items = [];
		$total = 0;

		try {
			                                                           
			                                                             
			$allItems = $this->getObjectService()->findAll(
				['filters' => $filters]
			);
			$total = count($allItems);

			                            
			$items = $this->getObjectService()->findAll(
				[
					'filters' => $filters,
					'limit' => $limit,
					'offset' => (($page - 1) * $limit),
					'order' => ['deadline' => 'ASC'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: findAll failed',
				['exception' => $e]
			);
		}         

		$pages = 0;
		if ($total > 0) {
			$pages = (int)ceil($total / $limit);
		}

		return [
			'items' => $items,
			'total' => $total,
			'page' => $page,
			'pages' => $pages,
		];
	}                         

	   
                                                                        
   
                                                                            
                                                    
   
                                                         
                                               
                                                                 
   
                                                           
    
	private function buildTaskFilters(string $registerId, string $schemaId, array $params): array {
		$filters = [
			'register' => $registerId,
			'schema' => $schemaId,
		];

		foreach (['status', 'assigneeUserId', 'assigneeGroupId'] as $key) {
			if (isset($params[$key]) === true && $params[$key] !== '') {
				$filters[$key] = $params[$key];
			}
		}

		return $this->applyDeadlineWindow(filters: $filters, params: $params);
	}                        

	   
                                                                             
   
                                                                 
                                                                     
   
                                                                             
    
	private function applyDeadlineWindow(array $filters, array $params): array {
		if (isset($params['from']) === true && $params['from'] !== '') {
			$filters['deadline'] = ['>=' => $params['from']];
		}

		if (isset($params['to']) === true && $params['to'] !== '') {
			if (isset($filters['deadline']) === false || is_array($filters['deadline']) === false) {
				$filters['deadline'] = [];
			}

			$filters['deadline']['<='] = $params['to'];
		}

		return $filters;
	}                           

	   
                                        
   
                                    
   
                                                 
   
                                                      
   
                                                               
    
	public function getScheduledTask(string $id): array {
		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Task not found');
		}

		try {
			$object = $this->getObjectService()->find(
				$id,
				[],
				false,
				$registerId,
				$schemaId
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: find failed',
				['exception' => $e, 'id' => $id]
			);
			throw new RuntimeException('Task not found');
		}

		if ($object === null) {
			throw new RuntimeException('Task not found');
		}

		return $this->normalizeToArray(object: $object);
	}                        

	   
                                
   
                                                                            
                                                                           
                        
   
                                                    
   
                                                       
   
                                                           
   
                                                               
    
	public function createScheduledTask(array $data): array {
		$this->validateTaskInput(data: $data);

		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Register or task schema not configured');
		}

		$user = $this->userSession->getUser();
		$data['createdBy'] = 'system';
		if ($user !== null) {
			$data['createdBy'] = $user->getUID();
		}

		if (isset($data['status']) === false || $data['status'] === '') {
			$data['status'] = 'open';
		}

		$saved = $this->getObjectService()->saveObject(
			$data,
			[],
			$registerId,
			$schemaId,
			null
		);

		return $this->normalizeToArray(object: $saved);
	}                           

	   
                                                                    
   
                                                            
   
                
   
                                                                                       
    
	private function validateTaskInput(array $data): void {
		if (isset($data['type']) === false
			|| in_array($data['type'], self::VALID_TYPES, true) === false
		) {
			throw new InvalidArgumentException('Invalid input');
		}

		if (isset($data['subject']) === false || trim((string)$data['subject']) === '') {
			throw new InvalidArgumentException('Invalid input');
		}

		if (isset($data['deadline']) === false || trim((string)$data['deadline']) === '') {
			throw new InvalidArgumentException('Invalid input');
		}

		                                                                   
		                                                                      
		                                                                      
		if ($this->isValidIso8601Deadline(deadline: (string)$data['deadline']) === false) {
			throw new InvalidArgumentException('Deadline must be a valid ISO-8601 date (e.g. 2025-12-31 or 2025-12-31T14:00:00Z)');
		}
	}                         

	   
                                      
   
                                                                          
                                                                       
   
                                    
                                                          
   
                                                         
   
                                                               
    
	public function updateScheduledTask(string $id, array $data): array {
		$existing = $this->getScheduledTask(id: $id);

		                                                                     
		                                                                        
		                                                                        
		$allowedKeys = array_merge(self::MUTABLE_FIELDS, ['status', 'assigneeUserId', 'assigneeGroupId', 'createdAt', 'completedAt', 'attempts']);
		$data = array_intersect_key($data, array_flip($allowedKeys));

		$merged = array_merge($existing, $data);
		$merged['id'] = $id;

		[$registerId, $schemaId] = $this->getRegisterAndSchema();

		                                                                
		                                                                     
		                                                                        
		                                                                      
		                                                                     
		                                                          
		                                                                   
		                                                                      
		if ($registerId === '' || $schemaId === '') {
			throw new RuntimeException('Task register or schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			$merged,
			[],
			$registerId,
			$schemaId,
			$id
		);

		return $this->normalizeToArray(object: $saved);
	}                           

	   
                            
   
                                    
   
                
   
                                                               
    
	public function deleteScheduledTask(string $id): void {
		$this->getObjectService()->deleteObject($id);
	}                           

	   
                                                            
   
                                                                       
                                                                           
   
                                                               
   
                                                               
    
	public function getOverdueTasks(): array {
		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		$now = new DateTimeImmutable('now');

		try {
			$items = $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
						'status' => 'open',
						'deadline' => [
							'<' => $now->format(DateTimeInterface::ATOM),
						],
					],
					'limit' => 100,
					'order' => ['deadline' => 'ASC'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: getOverdueTasks failed',
				['exception' => $e]
			);
			return [];
		}         

		$normalised = [];
		foreach ($items as $item) {
			$normalised[] = $this->normalizeToArray(object: $item);
		}

		return $normalised;
	}                       

	   
                                                                     
   
                                                    
   
                                                               
   
                                                                
   
                                                               
    
	public function getPendingTasks(int $windowMinutes = 60): array {
		if ($windowMinutes > self::MAX_WINDOW_MINUTES) {
			$windowMinutes = self::MAX_WINDOW_MINUTES;
		}

		if ($windowMinutes < 1) {
			$windowMinutes = 1;
		}

		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		$now = new DateTimeImmutable('now');
		$cutoff = $now->modify(sprintf('+%d minutes', $windowMinutes));

		try {
			$items = $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $schemaId,
						'status' => 'open',
						'deadline' => [
							'>=' => $now->format(DateTimeInterface::ATOM),
							'<=' => $cutoff->format(DateTimeInterface::ATOM),
						],
					],
					'limit' => 100,
					'order' => ['deadline' => 'ASC'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: getPendingTasks failed',
				['exception' => $e]
			);
			return [];
		}         

		$normalised = [];
		foreach ($items as $item) {
			$normalised[] = $this->normalizeToArray(object: $item);
		}

		return $normalised;
	}                       

	   
                                    
   
                                                                     
                                                                                          
                                                                          
   
                                                                          
   
                
   
                                                               
    
	public function processScheduledTasks(): void {
		                                                                               
		$dueSoon = $this->getPendingTasks(windowMinutes: self::EXPIRY_THRESHOLD_MINUTES);
		                                                           
		$overdue = $this->getOverdueTasks();
		$candidates = array_merge($overdue, $dueSoon);

		$now = new DateTimeImmutable('now');
		$expiryCut = $now->modify('-4 hours');

		[$registerId, $schemaId] = $this->getRegisterAndSchema();
		if ($registerId === '' || $schemaId === '') {
			return;
		}

		foreach ($candidates as $task) {
			$this->processSingleTask(
				task: $task,
				now: $now,
				expiryCut: $expiryCut,
				registerId: $registerId,
				schemaId: $schemaId
			);
		}             
	}                             

	   
                                                                    
   
                                                                             
                                                                           
                                                               
   
                                                        
                                                      
                                                                       
                                                         
                                               
   
                
    
	private function processSingleTask(array $task, DateTimeImmutable $now, DateTimeImmutable $expiryCut, string $registerId, string $schemaId): void {
		if (($task['status'] ?? '') !== 'open') {
			return;
		}

		$deadlineRaw = $task['deadline'] ?? '';
		if ($deadlineRaw === '') {
			return;
		}

		try {
			$deadline = new DateTimeImmutable((string)$deadlineRaw);
		} catch (Throwable $e) {
			return;
		}

		if ($deadline > $now) {
			               
			return;
		}

		$task = $this->applyDeadlineTransition(
			task: $task,
			deadline: $deadline,
			expiryCut: $expiryCut,
			timestamp: $now->format(DateTimeInterface::ATOM)
		);

		$this->persistProcessedTask(task: $task, registerId: $registerId, schemaId: $schemaId);
	}                         

	   
                                                             
   
                                                                             
                                                                            
                          
   
                                                  
                                                           
                                                             
                                                                
   
                                                                        
    
	private function applyDeadlineTransition(array $task, DateTimeImmutable $deadline, DateTimeImmutable $expiryCut, string $timestamp): array {
		$attempts = $task['attempts'] ?? [];
		if (is_array($attempts) === false) {
			$attempts = [];
		}

		if ($deadline < $expiryCut) {
			$task['status'] = 'expired';
			$attempts[] = [
				'timestamp' => $timestamp,
				'result' => 'expired',
			];

			$this->notifyTaskExpired(task: $task);
		}

		if ($deadline >= $expiryCut) {
			$task['status'] = 'in_progress';
			$attempts[] = [
				'timestamp' => $timestamp,
				'result' => 'notified',
			];

			$this->notifyTaskAssignee(task: $task);
		}

		$task['attempts'] = $attempts;

		return $task;
	}                               

	   
                                                                   
   
                                                      
                                                         
                                               
   
                
    
	private function persistProcessedTask(array $task, string $registerId, string $schemaId): void {
		try {
			$this->getObjectService()->saveObject(
				$task,
				[],
				$registerId,
				$schemaId,
				(string)($task['id'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: task update failed',
				['exception' => $e, 'id' => $task['id'] ?? null]
			);
		}
	}                            

	   
                                                               
   
                                                                             
                                                 
   
                                                                 
   
                
    
	private function notifyTaskAssignee(array $task): void {
		$assignee = $task['assigneeUserId'] ?? '';
		if ($assignee === '') {
			return;
		}

		try {
			$this->notificationService->notifyAssignment(
				entityType: 'task',
				title: (string)($task['subject'] ?? 'Scheduled task'),
				assigneeUserId: (string)$assignee,
				objectId: (string)($task['id'] ?? ''),
				author: 'system'
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: notification dispatch failed',
				['exception' => $e]
			);
		}
	}                          

	   
                                             
   
                                                                            
                                                                    
                                                                          
              
   
                                                            
   
                
   
                                                                                                   
    
	private function notifyTaskExpired(array $task): void {
		$assignee = $task['assigneeUserId'] ?? '';
		if ($assignee === '') {
			return;
		}

		try {
			$this->notificationService->notifyTaskExpired(
				title: (string)($task['subject'] ?? 'Scheduled task'),
				userId: (string)$assignee,
				objectId: (string)($task['id'] ?? ''),
				deadline: (string)($task['deadline'] ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ScheduledTaskService: expiry notification dispatch failed',
				['exception' => $e]
			);
		}
	}                         

	   
                                   
   
                                                                           
                                                                
   
                                                      
                                             
   
                
   
                                                                  
   
                                                               
    
	public function authorizeTaskMutation(array $task, string $userId): void {
		if ($userId === '') {
			throw new OCSForbiddenException('Not authorized');
		}

		$assigneeUserId = (string)($task['assigneeUserId'] ?? '');
		if ($assigneeUserId !== '' && $assigneeUserId === $userId) {
			return;
		}

		$assigneeGroupId = (string)($task['assigneeGroupId'] ?? '');
		if ($assigneeGroupId !== ''
			&& $this->groupManager->isInGroup($userId, $assigneeGroupId) === true
		) {
			return;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return;
		}

		throw new OCSForbiddenException('Not authorized');
	}                             

	   
                                                                    
   
                                                                                 
                                                                                  
                                                         
   
                                                    
   
                                                                       
    
	private function isValidIso8601Deadline(string $deadline): bool {
		                          
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1) {
			return true;
		}

		                                                                    
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})?$/', $deadline) === 1) {
			return true;
		}

		return false;
	}                              

	   
                                                 
   
                                                                        
                                                                            
                                                                             
                                                                             
                                                                           
                                                                      
   
                                                                         
                                                          
    
	private function getRegisterAndSchema(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/task_schema not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return [$registerId, $schemaId];
	}                            

	   
                                                                         
   
                                                                        
                                                                        
                                                                       
                                  
   
                                                                        
   
                                                                       
   
                
   
                                                                          
    
	public function applyAcceptLanguage(string $acceptLanguage): void {
		if ($acceptLanguage === '') {
			return;
		}

		try {
			$lang = $this->parsePreferredLanguage(headerValue: $acceptLanguage);
			$this->languageService->setPreferredLanguage(language: $lang);
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: 'applyAcceptLanguage: OR LanguageService unavailable.',
				context: ['exception' => $e->getMessage()]
			);
		}
	}                           

	   
                                                                           
   
                                                           
    
	private function getObjectService(): object {
		return $this->objectService;
	}                        

	   
                                                                           
   
                                               
   
                                                                    
   
                                                                     
    
	private function parsePreferredLanguage(string $headerValue): string {
		$parts = explode(separator: ',', string: $headerValue);
		$first = trim(string: explode(separator: ';', string: $parts[0])[0]);

		if ($first !== '') {
			return $first;
		}

		return 'nl';
	}                              

	   
                                                        
   
                                                           
   
                                                           
    
	private function normalizeToArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$array = $object->toArray();
				if (is_array($array) === true) {
					return $array;
				}
			}
		}

		return [];
	}                        
}           
