<?php

   
                                      
  
                                                                        
                                                                               
              
  
                                                                               
                                                                              
                                                                             
                         
  
                                                                           
                                                                                
                                                                              
        
  
                                                                               
                                                                        
  
                                                                    
                                                                                
                                                                      
                                     
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                            
  
                                                                                                                                                           
   
class ProgrammePortfolioService {
	   
                                                                    
   
               
    
	public const DEFAULT_MODE_KEY = 'programme_progress_mode';

	   
                                                   
   
                           
    
	public const MODES = ['manual', 'fromTasks', 'fromEffort'];

	   
                                              
   
            
    
	private const LIMIT = 500;

	   
                
   
                                                                                             
                                                                               
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                         
   
                                    
    
	private function defaultMode(): string {
		$configured = trim(
			$this->appConfig->getValueString(Application::APP_ID, self::DEFAULT_MODE_KEY, 'fromTasks')
		);

		if (in_array($configured, self::MODES, true) === true) {
			return $configured;
		}

		return 'fromTasks';
	}                   

	   
                                               
   
                                                         
   
                                    
   
                                                                                                                                                            
    
	public function modeFor(array $programme): string {
		$override = trim((string)($programme['progressMode'] ?? ''));

		if (in_array($override, self::MODES, true) === true) {
			return $override;
		}

		return $this->defaultMode();
	}               

	   
                                                     
   
                                                                 
                                                                
                                                                   
                                                                                           
   
                                                            
   
                                                                                                                                                                     
    
	public function mayLink(
		string $domainObjectType,
		string $domainObjectRef,
		string $programmeId,
		array $existingLinks,
	): array {
		$domainObjectType = trim($domainObjectType);
		$domainObjectRef = trim($domainObjectRef);

		if ($domainObjectType === '' || $domainObjectRef === '' || trim($programmeId) === '') {
			return ['allowed' => false, 'reason' => 'A programme, an object type and an object are required.'];
		}

		foreach ($existingLinks as $link) {
			if ((string)($link['domainObjectType'] ?? '') !== $domainObjectType) {
				continue;
			}

			if ((string)($link['domainObjectRef'] ?? '') !== $domainObjectRef) {
				continue;
			}

			$holder = (string)($link['programme'] ?? '');
			if ($holder === trim($programmeId)) {
				return ['allowed' => false, 'reason' => 'This work is already in this programme.'];
			}

			                                                                  
			                     
			return ['allowed' => false, 'reason' => "This work is already in programme {$holder}."];
		}

		return ['allowed' => true, 'reason' => ''];
	}               

	   
                                         
   
                                                               
                                                                                                
                                                                        
   
                                                                        
                                               
   
                                                                                                                                                                     
    
	public function presentWorkItem(array $workItem, array $resolvedTitles = [], array $installedApps = []): array {
		$type = (string)($workItem['domainObjectType'] ?? '');
		$ref = (string)($workItem['domainObjectRef'] ?? '');
		$app = trim(explode(':', $type)[0]);

		$title = ($resolvedTitles[$ref] ?? '');
		$resolved = ($title !== '');

		if ($resolved === false && $installedApps !== [] && in_array($app, $installedApps, true) === false) {
			                                                                  
			                                                                 
			                                     
			$title = trim((string)($workItem['title'] ?? ''));
		}

		                                                                    
		                                                         
		$label = $ref;
		if ($title !== '') {
			$label = $title;
		}

		return [
			'id' => (string)($workItem['id'] ?? $workItem['uuid'] ?? ''),
			'programme' => (string)($workItem['programme'] ?? ''),
			'domainObjectType' => $type,
			'domainObjectRef' => $ref,
			'app' => $app,
			'title' => $label,
			'resolved' => $resolved,
		];
	}                       

	   
                                                                 
   
                                                         
                                                                              
                                                                            
                                                            
   
                                                                                     
                                         
   
                                                                                                                                                            
    
	public function progressFor(array $programme, array $tasks = [], ?array $effort = null): array {
		$mode = $this->modeFor(programme: $programme);

		if ($mode === 'manual') {
			$typed = ($programme['manualProgress'] ?? null);
			$entered = is_numeric($typed);

			$progress = null;
			$reason = 'No progress has been entered.';
			if ($entered === true) {
				$progress = (int)$typed;
				$reason = '';
			}

			return [
				'mode' => 'manual',
				'progress' => $progress,
				'computable' => $entered,
				'reason' => $reason,
			];
		}

		if ($mode === 'fromTasks') {
			$total = count($tasks);
			if ($total === 0) {
				return [
					'mode' => 'fromTasks',
					'progress' => null,
					'computable' => false,
					'reason' => 'This programme has no tasks to derive progress from.',
				];
			}

			$closed = count(
				array_filter($tasks, static fn (array $task): bool => ($task['status'] ?? '') === 'closed')
			);

			return [
				'mode' => 'fromTasks',
				'progress' => (int)round((($closed / $total) * 100)),
				'computable' => true,
				'reason' => '',
			];
		}

		if ($effort === null) {
			                                                              
			                                                           
			return [
				'mode' => 'fromEffort',
				'progress' => null,
				'computable' => false,
				'reason' => 'Hours cannot be read on this instance, so progress cannot be computed.',
			];
		}

		$estimated = (float)($effort['estimated'] ?? 0);
		if ($estimated <= 0.0) {
			return [
				'mode' => 'fromEffort',
				'progress' => null,
				'computable' => false,
				'reason' => 'Nothing has been estimated, so progress cannot be computed.',
			];
		}

		$booked = (float)($effort['booked'] ?? 0);

		return [
			'mode' => 'fromEffort',
			'progress' => (int)round((($booked / $estimated) * 100)),
			'computable' => true,
			'reason' => '',
		];
	}                   

	   
                                    
   
                                                    
   
                                                            
    
	public function workItemsOf(string $programmeId): array {
		return $this->read(
			schemaKey: 'programmeWorkItem_schema',
			filters: ['programme' => trim($programmeId)],
		);
	}                   

	   
                                                                       
   
                                                                 
                                                     
   
                                                            
    
	private function workItemsReferencing(string $domainObjectType, string $domainObjectRef): array {
		return $this->read(
			schemaKey: 'programmeWorkItem_schema',
			filters: [
				'domainObjectType' => trim($domainObjectType),
				'domainObjectRef' => trim($domainObjectRef),
			],
		);
	}                            

	   
                               
   
                                                    
   
                                                       
    
	public function tasksOf(string $programmeId): array {
		return $this->read(
			schemaKey: 'programmeTask_schema',
			filters: ['programme' => trim($programmeId)],
		);
	}               

	   
                                        
   
                                             
                                                                 
                                                     
                                                            
   
                                                                            
   
                                                                                                                                                                     
    
	public function linkWork(
		string $programmeId,
		string $domainObjectType,
		string $domainObjectRef,
		string $title = '',
	): array {
		$answer = $this->mayLink(
			domainObjectType: $domainObjectType,
			domainObjectRef: $domainObjectRef,
			programmeId: $programmeId,
			existingLinks: $this->workItemsReferencing(
				domainObjectType: $domainObjectType,
				domainObjectRef: $domainObjectRef,
			),
		);

		if ($answer['allowed'] === false) {
			return ['status' => 409, 'error' => $answer['reason']];
		}

		$workItem = [
			'programme' => trim($programmeId),
			'domainObjectType' => trim($domainObjectType),
			'domainObjectRef' => trim($domainObjectRef),
			'title' => trim($title),
		];

		if ($this->write(schemaKey: 'programmeWorkItem_schema', object: $workItem) === false) {
			return ['status' => 500, 'error' => 'The work item could not be saved.'];
		}

		return ['status' => 201, 'workItem' => $workItem];
	}                

	   
                                            
   
                                                                      
                                                       
   
                                                      
    
	public function read(string $schemaKey, array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			$this->logger->debug(
				'ProgrammePortfolioService: the programme surface is not configured',
				['schemaKey' => $schemaKey]
			);

			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ProgrammePortfolioService: the read failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}            

	   
                                          
   
                                                                      
                                                   
                                                     
   
                                            
    
	public function write(string $schemaKey, array $object, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$this->objectService->saveObject(
				object: $object,
				register: $register,
				schema: $schema,
				uuid: $uuid,
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'ProgrammePortfolioService: the write failed',
				['schemaKey' => $schemaKey, 'exception' => $e->getMessage()]
			);

			return false;
		}
	}             
}           
