<?php

   
                                      
  
                                                                                         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                           
                                                                           
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                    
  
                                                                         
                                                                           
                                                                               
  
                                                                           
   
class ObjectEventHandlerService {
	   
                
   
                                                                     
                                                                  
                                                                        
    
	public function __construct(
		private SchemaMapService $schemaMapService,
		private ObjectEventDispatcher $dispatcher,
		private ObjectUpdateDiffService $diffService,
	) {
	}                   

	   
                                  
   
                                                          
   
                
   
                                                                                            
    
	public function handleCreated(object $objectEntity): void {
		$entityType = $this->schemaMapService->resolveEntityType(schemaId: $objectEntity->getSchema());
		if ($this->isRelevantEntityType(entityType: $entityType) === false) {
			return;
		}

		$data = $objectEntity->getObject();
		$objectId = (string)$objectEntity->getId();

		$this->dispatcher->dispatchCreated(
			entityType: $entityType,
			title: $this->stringifyTitle(title: ($data['title'] ?? '')),
			objectId: $objectId,
			assignee: $this->stringifyScalar(value: ($data['assignee'] ?? ''))
		);
	}                     

	   
                             
   
                                                   
                                                            
   
                
   
                                                                                            
    
	public function handleUpdated(object $newObject, ?object $oldObject): void {
		$entityType = $this->schemaMapService->resolveEntityType(schemaId: $newObject->getSchema());
		if ($this->isRelevantEntityType(entityType: $entityType) === false) {
			return;
		}

		$newData = $newObject->getObject();
		$oldData = $this->extractOldData(oldObject: $oldObject);
		$title = $this->stringifyTitle(title: ($newData['title'] ?? ''));
		$objectId = (string)$newObject->getId();
		$assignee = $this->stringifyScalar(value: ($newData['assignee'] ?? ''));

		$this->diffService->dispatchAssigneeChangeIfNeeded(
			oldData: $oldData,
			entityType: $entityType,
			title: $title,
			objectId: $objectId,
			assignee: $assignee,
			dispatcher: $this->dispatcher
		);

		if ($entityType === 'lead') {
			$this->diffService->dispatchStageChangeIfNeeded(
				newData: $newData,
				oldData: $oldData,
				title: $title,
				objectId: $objectId,
				assignee: $assignee,
				dispatcher: $this->dispatcher
			);
		}

		if ($entityType === 'request') {
			$this->diffService->dispatchStatusChangeIfNeeded(
				newData: $newData,
				oldData: $oldData,
				title: $title,
				objectId: $objectId,
				assignee: $assignee,
				dispatcher: $this->dispatcher
			);
		}
	}                     

	   
                                                                            
   
                                                                        
                                                        
                                                                             
                                                         
                                                                     
                                                                         
                                                                      
                                                                         
   
                                                                                
   
                                                           
    
	private function stringifyTitle(mixed $title): string {
		if (is_array($title) === true) {
			                                                                   
			foreach (['en', 'en_GB', 'en_US', 'nl', 'nl_NL'] as $lang) {
				if (isset($title[$lang]) === true && is_scalar($title[$lang]) === true) {
					return (string)$title[$lang];
				}
			}

			foreach ($title as $value) {
				if (is_scalar($value) === true) {
					return (string)$value;
				}
			}

			                                                                   
			                                                               
			                        
			$encoded = json_encode($title);
			if ($encoded !== false) {
				return $encoded;
			}

			return '';
		}        

		return $this->stringifyScalar(value: $title);
	}                      

	   
                                                                           
   
                                                                       
                                                                
   
                                      
   
                                                             
    
	private function stringifyScalar(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}                       

	   
                                                            
   
                                                       
   
                                                     
    
	private function isRelevantEntityType(?string $entityType): bool {
		if ($entityType === null) {
			return false;
		}

		return in_array($entityType, ['lead', 'request', 'contact'], true);
	}                            

	   
                                               
   
                                                            
   
                                      
    
	private function extractOldData(?object $oldObject): array {
		if ($oldObject !== null) {
			return $oldObject->getObject();
		}

		return [];
	}                      
}           
