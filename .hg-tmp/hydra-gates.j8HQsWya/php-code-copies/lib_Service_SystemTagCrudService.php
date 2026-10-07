<?php

   
                                 
  
                                                                               
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagAlreadyExistsException;

   
                                                       
  
                                                             
   
class SystemTagCrudService {
	   
                                                          
   
                 
    
	private const PIPELINQ_OBJECT_TYPES = [
		'pipelinq_lead_source',
		'pipelinq_request_channel',
	];

	   
                
   
                                                                
                                                                          
    
	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagMapper,
	) {
	}                   

	   
                                                
   
                                              
   
                              
                                                                       
    
	public function getTagIdsForType(string $objectType): array {
		$tagIds = $this->tagMapper->getTagIdsForObjects(
			objIds: [$objectType],
			objectType: $objectType
		);

		if (empty($tagIds[$objectType]) === true) {
			return [];
		}

		return $tagIds[$objectType];
	}                        

	   
                                       
   
                                                
   
                                        
                                                                       
    
	public function resolveTagData(array $tagIds): array {
		$tags = $this->tagManager->getTagsByIds($tagIds);

		$result = [];
		foreach ($tags as $tag) {
			$result[] = [
				'id' => (int)$tag->getId(),
				'name' => $tag->getName(),
			];
		}

		return $result;
	}                      

	   
                                                                        
   
                                     
   
                                  
                                                                       
    
	public function createOrReuseSystemTag(string $name): object {
		try {
			return $this->tagManager->createTag(
				tagName: $name,
				userVisible: true,
				userAssignable: false
			);
		} catch (TagAlreadyExistsException $e) {
			return $this->tagManager->getTag(
				tagName: $name,
				userVisible: true,
				userAssignable: false
			);
		}
	}                              

	   
                                   
   
                                              
                                           
   
                
                                                                       
    
	public function assignTag(string $objectType, int $tagId): void {
		                                                                       
		                                                                      
		                                              
		$this->tagMapper->assignTags(
			objId: $objectType,
			objectType: $objectType,
			tagIds: [(string)$tagId]
		);
	}                 

	   
                                                                            
   
                                              
                                           
   
                
                                                                       
    
	public function unassignAndCleanup(string $objectType, int $tagId): void {
		                                                     
		$this->tagMapper->unassignTags(
			objId: $objectType,
			objectType: $objectType,
			tagIds: [(string)$tagId]
		);

		$stillUsed = $this->isTagUsedByOtherTypes(
			tagId: $tagId,
			excludeType: $objectType
		);

		if ($stillUsed === false) {
			$this->tagManager->deleteTags([(string)$tagId]);
		}
	}                          

	   
                                                                      
   
                                                                       
                                                                           
                                               
   
                                 
                                        
   
                
                                                                       
    
	public function renameSystemTag(int $tagId, string $newName): void {
		                                                                     
		$existingTags = $this->tagManager->getTagsByIds([(string)$tagId]);
		$userAssignable = false;
		if (empty($existingTags) === false) {
			$existingTag = reset($existingTags);
			$userAssignable = $existingTag->isUserAssignable();
		}

		$this->tagManager->updateTag(
			tagId: (string)$tagId,
			newName: $newName,
			userVisible: true,
			userAssignable: $userAssignable,
			color: null
		);
	}                       

	   
                                                                
   
                                          
                                                                         
   
                                                               
    
	private function isTagUsedByOtherTypes(int $tagId, string $excludeType): bool {
		foreach (self::PIPELINQ_OBJECT_TYPES as $otherType) {
			if ($otherType === $excludeType) {
				continue;
			}

			$otherTagIds = $this->tagMapper->getTagIdsForObjects(
				objIds: [$otherType],
				objectType: $otherType
			);

			if (empty($otherTagIds[$otherType]) === false
				&& in_array($tagId, $otherTagIds[$otherType], true) === true
			) {
				return true;
			}
		}

		return false;
	}                             
}           
