<?php

   
                                       
  
                                                                             
                  
  
                                                                               
                                                                          
                                                     
  
                                                                               
                                                                             
  
                                                                         
                                                                              
                                                              
  
                                                                            
                                                                             
                                                                             
                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                  
  
                                                                                                                                                                          
   
class ProgrammeEstimationService {
	   
                
   
                                                                                       
                                                        
                                              
    
	public function __construct(
		private readonly ProgrammePortfolioService $portfolio,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                  
   
                                                                 
                                                                                         
   
                                                            
   
                                                                                                                                                                                      
    
	public function mayActivate(array $scale, array $existingScales): array {
		$scope = trim((string)($scale['scope'] ?? ''));
		if ($scope === '') {
			return ['allowed' => false, 'reason' => 'A scale needs a scope.'];
		}

		$id = trim((string)($scale['id'] ?? $scale['uuid'] ?? ''));

		foreach ($existingScales as $existing) {
			if (($existing['active'] ?? false) !== true) {
				continue;
			}

			if (trim((string)($existing['scope'] ?? '')) !== $scope) {
				continue;
			}

			$existingId = trim((string)($existing['id'] ?? $existing['uuid'] ?? ''));
			if ($existingId !== '' && $existingId === $id) {
				continue;
			}

			$name = (string)($existing['name'] ?? $existingId);

			return [
				'allowed' => false,
				'reason' => "Scope {$scope} already has an active scale: {$name}.",
			];
		}

		return ['allowed' => true, 'reason' => ''];
	}                   

	   
                                         
   
                                                                          
                                            
   
                                                                             
                                           
   
                                                                            
    
	public function weightOf(array $scale, string $point): ?float {
		foreach (($scale['points'] ?? []) as $declared) {
			if ((string)($declared['label'] ?? '') === trim($point)) {
				return (float)($declared['weight'] ?? 0);
			}
		}

		return null;
	}                

	   
                                                              
   
                                                            
   
                                                                            
                                                                               
   
                                                                                              
                  
   
                                                                                                                                                                           
    
	public function totalFor(array $estimates, array $scales): array {
		$perRole = [];
		$unresolved = [];

		foreach ($estimates as $estimate) {
			$role = trim((string)($estimate['role'] ?? ''));
			if ($role === '') {
				continue;
			}

			$scale = ($scales[trim((string)($estimate['scale'] ?? ''))] ?? []);
			$weight = $this->weightOf(scale: $scale, point: (string)($estimate['point'] ?? ''));

			if ($weight === null) {
				                                                          
				                                                     
				$unresolved[] = $role;
				continue;
			}

			                                                                 
			                                                                 
			$perRole[$role] = $weight;
		}

		return [
			'perRole' => $perRole,
			'total' => array_sum($perRole),
			'unresolved' => $unresolved,
		];
	}                

	   
                                                              
   
                                                 
                                    
                                         
                                                               
   
                                              
   
                                                                                                                                                                 
    
	public function snapshotFor(array $cycle, int $done, int $total, ?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$existing = ($cycle['snapshots'] ?? []);
		if (is_array($existing) === false) {
			$existing = [];
		}

		return [
			'version' => (count($existing) + 1),
			'closedAt' => $now->format(DateTimeInterface::ATOM),
			'closedBy' => ($this->userSession->getUser()?->getUID() ?? ''),
			'done' => $done,
			'total' => $total,
		];
	}                   

	   
                                        
   
                                                 
                                                                   
   
                                                                         
   
                                                                                                                                                                 
    
	public function closeCycle(array $cycle, array $tasks): array {
		$done = count(
			array_filter($tasks, static fn (array $task): bool => ($task['status'] ?? '') === 'closed')
		);

		$snapshot = $this->snapshotFor(cycle: $cycle, done: $done, total: count($tasks));

		$snapshots = ($cycle['snapshots'] ?? []);
		if (is_array($snapshots) === false) {
			$snapshots = [];
		}

		                                                                  
		                                                          
		$snapshots[] = $snapshot;

		$cycle['snapshots'] = $snapshots;
		$cycle['status'] = 'closed';

		$saved = $this->portfolio->write(
			schemaKey: 'programmeCycle_schema',
			object: $cycle,
			uuid: (string)($cycle['id'] ?? $cycle['uuid'] ?? ''),
		);

		if ($saved === false) {
			return ['status' => 500, 'error' => 'The cycle could not be closed.'];
		}

		return ['status' => 200, 'cycle' => $cycle];
	}                  

	   
                                                          
   
                                                                        
                                                                       
   
                                                 
                                                                        
   
                                                                                        
                  
   
                                                                                                                                                                 
    
	public function chartFor(array $cycle, array $tasks): array {
		$live = [
			'done' => count(
				array_filter($tasks, static fn (array $task): bool => ($task['status'] ?? '') === 'closed')
			),
			'total' => count($tasks),
		];

		$snapshots = ($cycle['snapshots'] ?? []);
		if (is_array($snapshots) === false) {
			$snapshots = [];
		}

		if ((string)($cycle['status'] ?? '') !== 'closed' || $snapshots === []) {
			return ['chart' => $live, 'live' => $live, 'source' => 'live'];
		}

		return [
			'chart' => end($snapshots),
			'live' => $live,
			'source' => 'snapshot',
		];
	}                

	   
                                                                     
   
                                                               
                                                                            
   
                                                                                
   
                                                                                                                                                                 
    
	public function carryOver(string $targetCycleId, array $tasks): array {
		$targetCycleId = trim($targetCycleId);
		if ($targetCycleId === '') {
			return ['status' => 400, 'error' => 'A target cycle is required.'];
		}

		$moved = [];
		foreach ($tasks as $task) {
			if (($task['status'] ?? '') === 'closed') {
				                                                           
				                                                 
				continue;
			}

			$sourceCycle = (string)($task['cycle'] ?? '');
			$task['cycle'] = $targetCycleId;

			$written = $this->portfolio->write(
				schemaKey: 'programmeTask_schema',
				object: $task,
				uuid: (string)($task['id'] ?? $task['uuid'] ?? ''),
			);

			if ($written === false) {
				$this->logger->error(
					'ProgrammeEstimationService: a task could not be carried over',
					['task' => ($task['id'] ?? '')]
				);
				continue;
			}

			$moved[] = [
				'task' => (string)($task['id'] ?? $task['uuid'] ?? ''),
				'from' => $sourceCycle,
				'to' => $targetCycleId,
			];
		}

		return ['status' => 200, 'moved' => count($moved), 'trail' => $moved];
	}                 
}           
