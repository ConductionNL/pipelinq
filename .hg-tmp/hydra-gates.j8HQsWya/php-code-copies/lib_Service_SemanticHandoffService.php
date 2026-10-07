<?php

   
                                   
  
                                                                       
                                                                                
                                                                            
                                                                            
                                                                                
                                                                          
                                                                           
                                                                                
                                                                                
                                                                            
                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                       
  
                                                                                            
  
                                                                             
                                                         
   
class SemanticHandoffService {
	   
                                                                      
   
               
    
	private const RESOLVER_CLASS = 'OCA\\OpenRegister\\Service\\SemanticTypeResolver';

	   
                                                              
   
               
    
	private const ENGINE_CLASS = 'OCA\\OpenRegister\\Service\\Handoff\\HandoffService';

	   
                
   
                                                                    
                                          
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                
   
                                                                         
                                                                           
                                                            
   
                                                                                      
   
                                                                                     
   
                                                                                             
    
	public function hasImplementer(string $kindUri): bool {
		if ($kindUri === '') {
			return false;
		}

		$resolver = $this->resolveService(fqcn: self::RESOLVER_CLASS, method: 'resolveSchemaByImplements');
		if ($resolver === null) {
			return false;
		}

		try {
			$schema = $resolver->resolveSchemaByImplements($kindUri);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SemanticHandoffService.hasImplementer: resolver threw',
				['kind' => $kindUri, 'error' => $e->getMessage()]
			);
			return false;
		}

		return $schema !== null;
	}                      

	   
                                                         
   
                                                                     
                                                                       
                                                                             
                                                                               
   
                                                 
                                             
                                         
                                                                            
   
                                                                                               
   
                                                                                             
    
	public function handoff(string $register, string $schema, string $id, string $handoffId): array {
		$engine = $this->resolveService(fqcn: self::ENGINE_CLASS, method: 'execute');
		if ($engine === null) {
			return $this->outcome(succeeded:false, reason: 'engine-unavailable');
		}

		try {
			$result = $engine->execute(register: $register, schema: $schema, id: $id, handoffId: $handoffId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SemanticHandoffService.handoff: engine execute failed',
				['handoffId' => $handoffId, 'error' => $e->getMessage()]
			);
			return $this->outcome(succeeded:false, reason: 'handoff-failed');
		}

		if (is_array($result) === false) {
			return $this->outcome(succeeded:false, reason: 'handoff-failed');
		}

		$status = (string)($result['status'] ?? '');
		$correlationId = (string)($result['correlationId'] ?? '');

		if ($status === 'executed') {
			$target = ($result['target'] ?? []);
			$uuid = '';
			if (is_array($target) === true) {
				$uuid = (string)($target['uuid'] ?? '');
			}

			return $this->outcome(succeeded:true, targetUuid: $uuid, correlationId: $correlationId);
		}

		if ($status === 'parked') {
			return $this->outcome(succeeded:true, reason: 'queued', correlationId: $correlationId);
		}

		$reason = $status;
		if ($reason === '') {
			$reason = 'handoff-failed';
		}

		return $this->outcome(succeeded:false, reason: $reason);
	}               

	   
                                        
   
                                                                        
                                                                         
                                                       
                                                  
   
                                                                                      
    
	private function outcome(bool $succeeded, string $targetUuid = '', string $correlationId = '', string $reason = ''): array {
		return [
			'ok' => $succeeded,
			'targetUuid' => $targetUuid,
			'correlationId' => $correlationId,
			'reason' => $reason,
		];
	}               

	   
                                                                           
   
                                                                        
                                                                             
                                                          
   
                                     
                                                                         
   
                                                              
    
	private function resolveService(string $fqcn, string $method): ?object {
		try {
			$service = $this->container->get($fqcn);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false || method_exists($service, $method) === false) {
			return null;
		}

		return $service;
	}                      
}           
