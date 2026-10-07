<?php

   
                                    
  
                                                                             
                                                                                
                                                                                
                                                                              
                                
  
                                                                         
                                                                             
                                                                              
                                                                            
                                                                             
                                                                               
                               
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Mdm;

use Psr\Log\LoggerInterface;

   
                                                                       
  
                                                                                                                 
   
class OpenRegisterSyncService {
	   
                                                                             
   
                                                                          
                                                                      
   
                              
    
	public const ENTITY_TYPE_SCHEMA = [
		'contact' => 'contact',
		'account' => 'client',
		'product' => 'product',
	];

	   
                
   
                                                                              
                                                                                
                                              
    
	public function __construct(
		private MdmObjectRepository $repository,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                            
   
                                                     
   
                                           
   
                                                                                                                  
    
	public function schemaForEntityType(string $entityType): ?string {
		return (self::ENTITY_TYPE_SCHEMA[$entityType] ?? null);
	}                           

	   
                                                                   
   
                                                                          
                                                                        
                                                         
   
                                                   
   
                                                                            
                                                                               
   
                                                                                                                  
    
	public function syncMasterToRegister(string $masterId): ?array {
		$entity = $this->repository->findMasterEntity($masterId);
		if ($entity === null) {
			return null;
		}

		$entityType = (string)($entity['entityType'] ?? '');
		$schemaSlug = $this->schemaForEntityType(entityType: $entityType);
		if ($schemaSlug === null) {
			$this->logger->debug(
				'Pipelinq MDM: no OR schema mapped for entity type; skipping sync',
				['master' => $masterId, 'entityType' => $entityType]
			);
			return null;
		}

		$golden = ($entity['goldenRecord'] ?? []);
		if (is_array($golden) === false) {
			$golden = [];
		}

		                                                                     
		$existing = $this->repository->findAll(
			$schemaSlug,
			['masterEntityRef' => $masterId, 'isMasterRecord' => true]
		);

		$target = ($existing[0] ?? null);

		$targetBase = [];
		if (is_array($target) === true) {
			$targetBase = $target;
		}

		$orObject = array_merge(
			$targetBase,
			$golden,
			['masterEntityRef' => $masterId, 'isMasterRecord' => true]
		);

		$uuid = null;
		if (is_array($target) === true) {
			$resolvedUuid = (string)($target['id'] ?? ($target['@self']['id'] ?? ''));
			if ($resolvedUuid !== '') {
				$uuid = $resolvedUuid;
			}
		}

		$saved = $this->repository->save($schemaSlug, $orObject, $uuid);

		$this->logger->info(
			'Pipelinq MDM: golden record synced to OpenRegister',
			['master' => $masterId, 'schema' => $schemaSlug, 'created' => ($uuid === null)]
		);

		return $saved;
	}                            

	   
                                                                         
   
                                                 
                                               
   
                
   
                                                                                                                  
    
	public function demoteRecord(string $schemaSlug, string $objectId): void {
		$object = $this->repository->find($schemaSlug, $objectId);
		if ($object === null) {
			return;
		}

		$object['isMasterRecord'] = false;
		$this->repository->save($schemaSlug, $object, $objectId);
	}                    
}           
