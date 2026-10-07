<?php

   
                           
  
                                                                        
                                       
  
                                                                           
                                                                        
                                                                           
                                                                            
                                                                         
                                                                        
                     
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                             
  
                                                                                                                                                                                   
   
class JourneyService {

	   
                            
   
               
    
	public const JOURNEY_SCHEMA = 'journey';

	   
                                
   
               
    
	public const RUN_SCHEMA = 'journeyRun';

	   
                                                                               
   
               
    
	public const FLOW_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowService';

	   
                                                                     
   
               
    
	public const VERSION_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowVersionService';

	   
                
   
                                                                  
                                                                  
                                                                                      
                                    
                                          
   
                                                                                                                                                                                    
    
	public function __construct(
		private ListObjectStore $store,
		private JourneyFlowCompiler $compiler,
		private ContainerInterface $container,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                              
   
                                                  
   
                                                           
   
                                                                                                                                                                                    
    
	public function find(string $journeyId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $journeyId);
	}            

	   
                  
   
                                                          
   
                                                                                                                                                                                    
    
	public function listJourneys(): array {
		return $this->store->findAll(schemaSlug: $this->schema());
	}                    

	   
                                     
   
                                                            
                                           
                                                                           
   
                                                                                        
   
                                                                                                                                                                                    
    
	public function save(array $payload, string $createdByUid, ?string $journeyId = null): ?array {
		$now = date('c', $this->time->getTime());
		$payload['updatedAt'] = $now;
		if ($journeyId === null) {
			$payload['createdAt'] = $now;
			$payload['createdBy'] = $createdByUid;
		}

		$stored = $this->store->save(schemaSlug: $this->schema(), payload: $payload, id: $journeyId);
		if ($stored === null) {
			return null;
		}

		return $this->compile(journeyId: $this->store->idOf($stored), runAs: $createdByUid);
	}            

	   
                                              
   
                                                  
                                                                  
   
                                                                                             
   
                                                                                                                                                                                    
    
	public function compile(string $journeyId, string $runAs = ''): ?array {
		$journey = $this->find(journeyId: $journeyId);
		if ($journey === null) {
			return null;
		}

		$flowService = $this->service(name: self::FLOW_SERVICE);
		if ($flowService === null) {
			return $this->record(journey: $journey, journeyId: $journeyId, status: 'engine_missing', error: '', flowUuid: '');
		}

		$document = $this->compiler->documentFor(journey: $journey, journeyId: $journeyId, runAs: $runAs);
		$existing = trim((string)($journey['flowUuid'] ?? ''));
		$target = null;
		if ($existing !== '') {
			$target = $existing;
		}

		try {
			$flow = $flowService->save($document, $target);
		} catch (Throwable $e) {
			$this->logger->warning(
				'JourneyService.compile: the flow engine refused the document',
				['journeyId' => $journeyId, 'exception' => $e->getMessage()]
			);
			return $this->record(journey: $journey, journeyId: $journeyId, status: 'refused', error: $e->getMessage(), flowUuid: $existing);
		}

		$flowUuid = $this->uuidOf(flow: $flow);
		$this->publish(flow: $flow, journeyId: $journeyId);

		return $this->record(journey: $journey, journeyId: $journeyId, status: 'compiled', error: '', flowUuid: $flowUuid);
	}               

	   
                                        
   
                                                    
   
                                                     
   
                                                                                                                                                                                 
    
	public function recordRun(array $run): ?array {
		$run['occurredAt'] = date('c', $this->time->getTime());
		return $this->store->save(
			schemaSlug: $this->store->schemaSlug('journeyRun_schema', self::RUN_SCHEMA),
			payload: $run
		);
	}                 

	   
                                      
   
                                                  
   
                                                      
   
                                                                                                                                                                                 
    
	public function runsFor(string $journeyId): array {
		$runs = [];
		$schema = $this->store->schemaSlug('journeyRun_schema', self::RUN_SCHEMA);
		foreach ($this->store->findAll(schemaSlug: $schema, filters: ['journeyId' => $journeyId]) as $run) {
			if ((string)($run['journeyId'] ?? '') === $journeyId) {
				$runs[] = $run;
			}
		}

		return $runs;
	}               

	   
                                                 
   
                                                             
                                            
                                                       
                                                          
                                                    
   
                                                         
    
	private function record(array $journey, string $journeyId, string $status, string $error, string $flowUuid): array {
		$journey['flowStatus'] = $status;
		$journey['flowError'] = $error;
		$journey['flowUuid'] = $flowUuid;

		$stored = $this->store->save(schemaSlug: $this->schema(), payload: $journey, id: $journeyId);
		return ($stored ?? $journey);
	}              

	   
                                                     
   
                                                                         
                                                                          
                                    
   
                                                           
                                                           
   
                
    
	private function publish(mixed $flow, string $journeyId): void {
		$versions = $this->service(name: self::VERSION_SERVICE);
		if ($versions === null || is_object($flow) === false) {
			return;
		}

		try {
			$versions->publish(flow: $flow, publishedBy: null);
		} catch (Throwable $e) {
			$this->logger->info(
				'JourneyService.publish: the flow was saved but not published',
				['journeyId' => $journeyId, 'exception' => $e->getMessage()]
			);
		}
	}               

	   
                                                
   
                                       
   
                                                        
    
	private function uuidOf(mixed $flow): string {
		                                                                       
		                                   
		if (is_object($flow) === true && is_callable([$flow, 'getUuid']) === true) {
			return (string)$flow->getUuid();
		}

		if (is_array($flow) === true) {
			return (string)($flow['uuid'] ?? '');
		}

		return '';
	}              

	   
                                                                  
   
                                                       
   
                                    
    
	private function service(string $name): ?object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			$this->logger->info(
				'JourneyService: the OpenRegister flow engine is not available',
				['service' => $name, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}               

	   
                            
   
                            
    
	private function schema(): string {
		return $this->store->schemaSlug('journey_schema', self::JOURNEY_SCHEMA);
	}              
}           
