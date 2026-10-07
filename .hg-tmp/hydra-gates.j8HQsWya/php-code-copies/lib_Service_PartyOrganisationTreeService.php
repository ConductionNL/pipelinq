<?php

   
                                         
  
                                                                       
                                                                       
                                                                           
  
                                                                           
                            
  
                                                                              
                                               
                                                                           
                                                                           
                            
                                                                               
                                                           
  
                                                                             
                                                                              
                                                  
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                              
  
                                                                                                                                                                                                       
   
class PartyOrganisationTreeService {
	   
                                                                  
   
                                                                           
                                                              
   
            
    
	public const DEFAULT_MAX_DEPTH = 6;

	   
                                         
   
               
    
	public const PATH_SEPARATOR = '/';

	   
                                                        
   
            
    
	private const SUBTREE_LIMIT = 1000;

	   
                
   
                                                                          
                                                      
                                                                               
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                               
   
                                                                    
   
                                                                                                                                                                                                        
    
	public function maxDepth(): int {
		$configured = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'party_organisation_max_depth',
			(string)self::DEFAULT_MAX_DEPTH
		);

		if ($configured > 0) {
			return $configured;
		}

		return self::DEFAULT_MAX_DEPTH;
	}                

	   
                                                   
   
                                          
                                                                             
   
                                   
    
	public function pathUnder(string $nodeId, string $parentPath): string {
		$parentPath = trim($parentPath, self::PATH_SEPARATOR);

		if ($parentPath === '') {
			return self::PATH_SEPARATOR . $nodeId;
		}

		return self::PATH_SEPARATOR . $parentPath . self::PATH_SEPARATOR . $nodeId;
	}                 

	   
                                
   
                                            
   
                          
   
                                                                                                                                                                                                        
    
	public function depthOf(string $path): int {
		$trimmed = trim($path, self::PATH_SEPARATOR);

		if ($trimmed === '') {
			return 0;
		}

		return count(explode(self::PATH_SEPARATOR, $trimmed));
	}               

	   
                                                                 
   
                                          
                                            
   
                                                        
    
	public function isOnPath(string $nodeId, string $path): bool {
		$trimmed = trim($path, self::PATH_SEPARATOR);
		if ($trimmed === '') {
			return false;
		}

		return in_array($nodeId, explode(self::PATH_SEPARATOR, $trimmed), true);
	}                

	   
                                                              
   
                                                   
                                                                           
   
                                                                         
   
                                                                                                                                                                                                        
    
	public function setParent(string $nodeId, ?string $parentId): array {
		$nodeId = trim($nodeId);
		$parentId = trim((string)$parentId);

		if ($nodeId === '') {
			return ['status' => 400, 'error' => 'An organisation is required.'];
		}

		if ($nodeId === $parentId) {
			return ['status' => 409, 'error' => 'An organisation cannot be its own parent.'];
		}

		$node = $this->read(id: $nodeId);
		if ($node === null) {
			return ['status' => 404, 'error' => 'That organisation could not be read.'];
		}

		$resolved = $this->parentPathFor(nodeId: $nodeId, parentId: $parentId);
		if (array_key_exists('error', $resolved) === true) {
			return $resolved;
		}

		$newPath = $this->pathUnder(nodeId: $nodeId, parentPath: (string)$resolved['path']);
		$oldPath = (string)($node['organisationPath'] ?? $this->pathUnder(nodeId: $nodeId, parentPath: ''));

		$descendants = $this->descendantsOf(path: $oldPath);

		$deepest = $this->deepestAfter(newPath: $newPath, oldPath: $oldPath, descendants: $descendants);
		if ($deepest > $this->maxDepth()) {
			return [
				'status' => 409,
				'error' => "That move would nest the organisation {$deepest} levels deep, past the cap of {$this->maxDepth()}.",
			];
		}

		$writes = $this->movePlan(
			node: $node,
			nodeId: $nodeId,
			parentId: $parentId,
			newPath: $newPath,
			oldPath: $oldPath,
			descendants: $descendants,
		);

		foreach ($writes as $uuid => $object) {
			if ($this->write(uuid: (string)$uuid, object: $object) === false) {
				return ['status' => 500, 'error' => 'The move could not be saved in full.'];
			}
		}

		return ['status' => 200, 'moved' => count($writes), 'path' => $newPath];
	}                 

	   
                                                                   
   
                                                       
                                                                               
   
                                                                         
   
                                                                                                                                                                                                        
    
	private function parentPathFor(string $nodeId, string $parentId): array {
		if ($parentId === '') {
			return ['path' => ''];
		}

		$parent = $this->read(id: $parentId);
		if ($parent === null) {
			return ['status' => 404, 'error' => 'That parent organisation could not be read.'];
		}

		$parentPath = (string)($parent['organisationPath'] ?? $this->pathUnder(nodeId: $parentId, parentPath: ''));

		                                                                    
		                                                                     
		                                            
		if ($this->isOnPath(nodeId: $nodeId, path: $parentPath) === true) {
			return [
				'status' => 409,
				'error' => 'That parent already sits under this organisation, so the move would close a cycle.',
			];
		}

		return ['path' => $parentPath];
	}                     

	   
                                                              
   
                                                          
                                                     
                                                                     
   
                                                   
    
	private function deepestAfter(string $newPath, string $oldPath, array $descendants): int {
		$newDepth = $this->depthOf(path: $newPath);
		$oldDepth = $this->depthOf(path: $oldPath);
		$deepest = $newDepth;

		foreach ($descendants as $descendant) {
			$relative = ($this->depthOf(path: (string)($descendant['organisationPath'] ?? '')) - $oldDepth);
			$deepest = max($deepest, ($newDepth + $relative));
		}

		return $deepest;
	}                    

	   
                                              
   
                                                                         
                                                                    
                                                    
   
                                                                   
                                   
                                                                          
                                                          
                                                     
                                                                     
   
                                                                     
   
                                                                                                                                                                                                        
    
	private function movePlan(
		array $node,
		string $nodeId,
		string $parentId,
		string $newPath,
		string $oldPath,
		array $descendants,
	): array {
		$newParent = null;
		if ($parentId !== '') {
			$newParent = $parentId;
		}

		$writes = [$nodeId => array_merge($node, [
			'parentOrganisation' => $newParent,
			'organisationPath' => $newPath,
		])];

		foreach ($descendants as $descendant) {
			$descendantId = (string)($descendant['id'] ?? $descendant['uuid'] ?? '');
			if ($descendantId === '') {
				continue;
			}

			$writes[$descendantId] = array_merge($descendant, [
				'organisationPath' => $newPath . substr((string)($descendant['organisationPath'] ?? ''), strlen($oldPath)),
			]);
		}

		return $writes;
	}                

	   
                                                                     
   
                                                                  
   
                                                             
    
	public function descendantsOf(string $path): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '' || trim($path, self::PATH_SEPARATOR) === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::SUBTREE_LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PartyOrganisationTreeService: the subtree read failed',
				['path' => $path, 'exception' => $e->getMessage()]
			);

			return [];
		}

		$prefix = rtrim($path, self::PATH_SEPARATOR) . self::PATH_SEPARATOR;
		$descendants = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$rowPath = (string)($data['organisationPath'] ?? '');
			if ($rowPath !== '' && str_starts_with($rowPath, $prefix) === true) {
				$descendants[] = $data;
			}
		}

		return $descendants;
	}                     

	   
                                           
   
                                              
   
                                                       
    
	private function read(string $id): ?array {
		try {
			$entity = $this->objectService->find(id: $id);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PartyOrganisationTreeService: could not read the organisation',
				['uuid' => $id, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}            

	   
                          
   
                                                
                                                           
   
                                            
    
	private function write(string $uuid, array $object): bool {
		try {
			$this->objectService->saveObject(
				object: $object,
				register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
				schema: $this->appConfig->getValueString(Application::APP_ID, 'client_schema', ''),
				uuid: $uuid,
			);

			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'PartyOrganisationTreeService: could not save the organisation',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);

			return false;
		}
	}             

	   
                                                  
   
                              
   
                                                            
    
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (($row instanceof \JsonSerializable) === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}               
}           
