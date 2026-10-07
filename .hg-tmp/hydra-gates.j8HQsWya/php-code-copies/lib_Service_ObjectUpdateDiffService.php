<?php

   
                                    
  
                                                                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                                       
  
                                                
                                                                                     
                                                
   
class ObjectUpdateDiffService {
	   
                                                         
   
                                              
                                              
                                          
                                          
                                                 
                                                                  
   
                
   
                                                                                       
    
	public function dispatchAssigneeChangeIfNeeded(
		array $oldData,
		string $entityType,
		string $title,
		string $objectId,
		string $assignee,
		ObjectEventDispatcher $dispatcher,
	): void {
		$oldAssignee = $oldData['assignee'] ?? '';
		if ($assignee === '' || $assignee === $oldAssignee) {
			return;
		}

		$dispatcher->dispatchAssigneeChange(
			entityType: $entityType,
			title: $title,
			objectId: $objectId,
			assignee: $assignee
		);
	}                                      

	   
                                                      
   
                                              
                                              
                                          
                                          
                                                 
                                                                  
   
                
   
                                                                                       
    
	public function dispatchStageChangeIfNeeded(
		array $newData,
		array $oldData,
		string $title,
		string $objectId,
		string $assignee,
		ObjectEventDispatcher $dispatcher,
	): void {
		$newStage = $newData['stage'] ?? '';
		$oldStage = $oldData['stage'] ?? '';
		if ($newStage === '' || $newStage === $oldStage) {
			return;
		}

		                                               
		$wonNames = ['won', 'gewonnen', 'closed won'];
		$lostNames = ['lost', 'verloren', 'closed lost'];
		$stageLower = strtolower($newStage);

		if (in_array($stageLower, $wonNames, true) === true) {
			$dispatcher->dispatchDealWon(
				title: $title,
				value: (string)($newData['value'] ?? '0'),
				objectId: $objectId,
				assignee: $assignee
			);
			return;
		}

		if (in_array($stageLower, $lostNames, true) === true) {
			$dispatcher->dispatchDealLost(
				title: $title,
				objectId: $objectId,
				assignee: $assignee
			);
			return;
		}

		$dispatcher->dispatchStageChange(
			title: $title,
			objectId: $objectId,
			newStage: $newStage,
			assignee: $assignee
		);
	}                                   

	   
                                                  
   
                                                                        
                                                                            
                                                   
   
                                              
                                              
   
                                                                  
   
                                                                                       
    
	public function hasValueChanged(array $newData, array $oldData): bool {
		$newValue = (float)($newData['value'] ?? 0);
		$oldValue = (float)($oldData['value'] ?? 0);

		                                                                    
		                                                                 
		return abs($newValue - $oldValue) >= 0.0001;
	}                       

	   
                                                       
   
                                              
                                              
                                          
                                          
                                                 
                                                                  
   
                
   
                                                                                       
    
	public function dispatchStatusChangeIfNeeded(
		array $newData,
		array $oldData,
		string $title,
		string $objectId,
		string $assignee,
		ObjectEventDispatcher $dispatcher,
	): void {
		$newStatus = $newData['status'] ?? '';
		$oldStatus = $oldData['status'] ?? '';
		if ($newStatus === '' || $newStatus === $oldStatus) {
			return;
		}

		$dispatcher->dispatchStatusChange(
			title: $title,
			objectId: $objectId,
			newStatus: $newStatus,
			assignee: $assignee
		);
	}                                    
}           
