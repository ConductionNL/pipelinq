<?php

   
                           
  
                                                                               
                                                             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                               
                                    
  
                         
  
                                                 
  
                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                             
                                                                      
  
                                                                         
  
                                                                  
                                                                               
                                                                           
                 
  
                                                  
  
                                                       
                                                                                                                               
   
class RoutingService {
	   
                                                    
   
                           
    
	private const TERMINAL_STATUSES = ['completed', 'cancelled', 'closed'];

	   
                                                                      
    
	private const DEFAULT_MAX_CONCURRENT = 10;

	   
                
   
                                                
                                                                    
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                      
   
                                                                
   
                                                         
                                            
   
                                                                             
   
                                                          
    
	public function getSuggestedAgents(string $entityType, string $entityId): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');

		$schemaId = $this->ticketService->getSchemaId();
		if ($entityType === 'lead') {
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		}

		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning('RoutingService: register or schema not configured for ' . $entityType);
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$objectService = $this->getObjectService();

		try {
			$entity = $objectService->find(
				$entityId,
				[]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RoutingService: failed to load entity',
				['exception' => $e->getMessage(), 'entityType' => $entityType, 'entityId' => $entityId]
			);
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		                                                                        
		                                                                         
		                                                                      
		                                                                  
		                                       
		if ($entity === null) {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$data = $entity->getObject();

		$category = (string)($data['category'] ?? '');
		if ($category === '') {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$candidates = $this->findMatchingAgents(category: $category);
		if ($candidates === []) {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$available = $this->filterByAvailability(profiles: $candidates);
		[$inCapacity, $atCapacityCount] = $this->filterByCapacity(profiles: $available);

		$suggestions = $this->buildSuggestions(inCapacity: $inCapacity);

		return [
			'suggestions' => $suggestions,
			'atCapacity' => $atCapacityCount,
			'noMatch' => $suggestions === [] && $atCapacityCount === 0,
		];
	}                          

	   
                                                                        
   
                                                                                     
   
                                                                                    
    
	private function buildSuggestions(array $inCapacity): array {
		$suggestions = [];
		foreach ($inCapacity as $profile) {
			$userId = (string)($profile['userId'] ?? '');
			$suggestions[] = [
				'userId' => $userId,
				'displayName' => (string)($profile['displayName'] ?? $userId),
				'workload' => $this->getAgentWorkload(userId: $userId),
				'maxConcurrent' => (int)($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT),
				'matchedSkill' => (string)($profile['matchedSkill'] ?? ''),
				'categories' => $profile['matchedCategories'] ?? [],
			];
		}

		usort($suggestions, static fn (array $a, array $b): int => $a['workload'] <=> $b['workload']);

		return $suggestions;
	}                        

	   
                                                                  
   
                                                 
   
                                    
   
                                                          
    
	public function getAgentWorkload(string $userId): int {
		if ($userId === '') {
			return 0;
		}

		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

		if ($registerId === '') {
			return 0;
		}

		$objectService = $this->getObjectService();
		$count = 0;

		                                                                      
		                                                                      
		                                                                       
		                                                                       
		                                                                        
		                                                                
		$requests = $this->ticketService->findByType(
			ticketType: TicketService::TYPE_REQUEST,
			extraFilters: ['assignee' => $userId],
			limit: 999,
		);

		foreach ($requests as $request) {
			$status = strtolower((string)($this->toArray(value: $request)['status'] ?? ''));
			if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
				$count++;
			}
		}

		                                                                   
		                                                          
		if ($leadSchemaId !== '') {
			try {
				$count += $objectService->count(
					[
						'filters' => [
							'register' => $registerId,
							'schema' => $leadSchemaId,
							'assignee' => $userId,
							'status' => 'open',
						],
					]
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'RoutingService: failed to count open leads',
					['exception' => $e->getMessage(), 'userId' => $userId]
				);
			}         
		}        

		return $count;
	}                        

	   
                                                              
   
                                                             
                                               
   
                                                      
   
                                                                           
   
                                                          
    
	public function findMatchingAgents(string $category): array {
		if ($category === '') {
			return [];
		}

		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$skillSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'skill_schema', '');
		$agentProfileSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'agentProfile_schema', '');

		if ($registerId === '' || $skillSchemaId === '' || $agentProfileSchemaId === '') {
			return [];
		}

		$skills = $this->loadActiveSkills(registerId: $registerId, skillSchemaId: $skillSchemaId, category: $category);
		$matchingSkillsById = $this->collectMatchingSkills(skills: $skills, category: $category);
		if ($matchingSkillsById === []) {
			return [];
		}

		$profiles = $this->loadAgentProfiles(registerId: $registerId, agentProfileSchemaId: $agentProfileSchemaId);

		return $this->matchProfilesToSkills(profiles: $profiles, matchingSkillsById: $matchingSkillsById);
	}                          

	   
                                                               
   
                                                         
                                                     
                                                                  
   
                                                                                                
    
	private function loadActiveSkills(string $registerId, string $skillSchemaId, string $category): array {
		try {
			$skills = $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $skillSchemaId,
						'isActive' => true,
					],
					'limit' => 999,
				]
			);
			return array_map(fn ($skill): array => $this->toArray(value: $skill), $skills);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RoutingService: failed to load skills',
				['exception' => $e->getMessage(), 'category' => $category]
			);
			return [];
		}
	}                        

	   
                                                             
   
                                                                         
                                                  
   
                                                                               
    
	private function collectMatchingSkills(array $skills, string $category): array {
		$matchingSkillsById = [];
		foreach ($skills as $skill) {
			$categories = $skill['categories'] ?? [];
			if (is_array($categories) === false || in_array($category, $categories, true) === false) {
				continue;
			}

			$skillId = (string)($skill['id'] ?? ($skill['@self']['id'] ?? ''));
			if ($skillId === '') {
				continue;
			}

			$matchingSkillsById[$skillId] = $skill;
		}

		return $matchingSkillsById;
	}                             

	   
                                                                
   
                                                         
                                                                    
   
                                                                                                 
    
	private function loadAgentProfiles(string $registerId, string $agentProfileSchemaId): array {
		try {
			$profiles = $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $agentProfileSchemaId,
					],
					'limit' => 999,
				]
			);
			return array_map(fn ($profile): array => $this->toArray(value: $profile), $profiles);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RoutingService: failed to load agent profiles',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}                         

	   
                                                                             
   
                                                                                   
                                                                             
   
                                                                             
    
	private function matchProfilesToSkills(array $profiles, array $matchingSkillsById): array {
		$matchingSkillIds = array_keys($matchingSkillsById);

		$matched = [];
		foreach ($profiles as $profile) {
			$profileSkills = $profile['skills'] ?? [];
			if (is_array($profileSkills) === false || $profileSkills === []) {
				continue;
			}

			$intersection = array_values(array_intersect($profileSkills, $matchingSkillIds));
			if ($intersection === []) {
				continue;
			}

			$firstMatchedSkill = $matchingSkillsById[$intersection[0]];
			$profile['matchedSkill'] = (string)($firstMatchedSkill['title'] ?? '');
			$profile['matchedCategories'] = $firstMatchedSkill['categories'] ?? [];
			$matched[] = $profile;
		}

		return $matched;
	}                             

	   
                                                    
   
                                                                             
   
                                                                
   
                                                          
    
	public function filterByAvailability(array $profiles): array {
		return array_values(
			array_filter(
				$profiles,
				static fn (array $profile): bool => ($profile['isAvailable'] ?? true) !== false
			)
		);
	}                            

	   
                                                                         
   
                                                                             
   
                                                                                                                  
   
                                                          
    
	public function filterByCapacity(array $profiles): array {
		$inCapacity = [];
		$atCapacityCount = 0;

		foreach ($profiles as $profile) {
			$workload = $this->getAgentWorkload(userId: (string)($profile['userId'] ?? ''));
			if ($this->isAgentAtCapacity(profile: $profile, workload: $workload) === true) {
				$atCapacityCount++;
				continue;
			}

			$inCapacity[] = $profile;
		}

		return [$inCapacity, $atCapacityCount];
	}                        

	   
                                                  
   
                                                           
                                                                   
   
                                                   
   
                                                          
    
	public function isAgentAtCapacity(array $profile, int $workload): bool {
		$max = (int)($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT);
		return $workload >= $max;
	}                         

	   
                                                      
   
                                                                                      
                 
   
                                        
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}               

	   
                                                
   
                                                                       
                                                                      
                                                                            
                                    
   
                                                      
    
	private function getObjectService(): ObjectServiceInterface {
		return $this->objectService;
	}                        
}           
