<?php

   
                             
  
                                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                                                   
                                              
  
                                                             
   
class SystemTagService {
	   
                
   
                                                                     
                                              
    
	public function __construct(
		private SystemTagCrudService $tagCrudService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                         
   
                                                              
   
                                                         
                                                                       
    
	public function getTags(string $objectType): array {
		$tagIds = $this->tagCrudService->getTagIdsForType(objectType: $objectType);

		if (empty($tagIds) === true) {
			return [];
		}

		$result = $this->tagCrudService->resolveTagData(tagIds: $tagIds);

		usort($result, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

		return $result;
	}               

	   
                                           
   
                                              
                                     
   
                                                         
   
                                                                      
                                                                       
    
	public function addTag(string $objectType, string $name): array {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('Tag name cannot be empty');
		}

		$this->validateTagNameUnique(
			objectType: $objectType,
			name: $name
		);

		$tag = $this->tagCrudService->createOrReuseSystemTag(name: $name);

		$this->tagCrudService->assignTag(
			objectType: $objectType,
			tagId: (int)$tag->getId()
		);

		$this->logger->info("Pipelinq: Added {$objectType} tag: {$name}");

		return [
			'id' => (int)$tag->getId(),
			'name' => $tag->getName(),
		];
	}              

	   
                                            
   
                                              
                                           
   
                
                                                                        
    
	public function removeTag(string $objectType, int $tagId): void {
		$this->tagCrudService->unassignAndCleanup(
			objectType: $objectType,
			tagId: $tagId
		);

		$this->logger->info("Pipelinq: Removed {$objectType} tag ID: {$tagId}");
	}                 

	   
                 
   
                                              
                                           
                                            
   
                                                         
   
                                                                      
                                                                        
    
	public function renameTag(string $objectType, int $tagId, string $newName): array {
		$newName = trim($newName);
		if ($newName === '') {
			throw new InvalidArgumentException('Tag name cannot be empty');
		}

		$this->validateTagNameUnique(
			objectType: $objectType,
			name: $newName,
			excludeId: $tagId
		);

		$this->tagCrudService->renameSystemTag(
			tagId: $tagId,
			newName: $newName
		);

		$this->logger->info("Pipelinq: Renamed {$objectType} tag ID {$tagId} to: {$newName}");

		return [
			'id' => $tagId,
			'name' => $newName,
		];
	}                 

	   
                                                              
   
                                              
                                                        
   
                
                                                                       
    
	public function ensureDefaults(string $objectType, array $defaults): void {
		$existing = $this->getTags(objectType: $objectType);
		$existingNames = array_map(fn ($t) => strtolower($t['name']), $existing);

		foreach ($defaults as $name) {
			if (in_array(strtolower($name), $existingNames, true) === false) {
				try {
					$this->addTag(
						objectType: $objectType,
						name: $name
					);
				} catch (\Exception $e) {
					$this->logger->warning(
						"Pipelinq: Failed to create default {$objectType} tag '{$name}': " . $e->getMessage()
					);
				}
			}
		}
	}                      

	   
                                                                    
   
                                              
                                              
                                                                     
   
                
   
                                                            
    
	private function validateTagNameUnique(string $objectType, string $name, ?int $excludeId = null): void {
		$existing = $this->getTags(objectType: $objectType);
		foreach ($existing as $tag) {
			if ($excludeId !== null && $tag['id'] === $excludeId) {
				continue;
			}

			if (strcasecmp($tag['name'], $name) === 0) {
				throw new RuntimeException('This tag already exists');
			}
		}
	}                             
}           
