<?php

   
                              
  
                                                                             
                                
  
                    
                                      
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                                  
  
                                                                                                      
   
class DemoJourneySeeder {
	   
                
   
                                                                                                      
                                                                                             
                                                                       
                                                                       
                                          
    
	public function __construct(
		private readonly JourneyService $journeyService,
		private readonly ListObjectStore $store,
		private readonly ContainerInterface $container,
		private readonly DemoSeedValues $values,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                
   
                                                                             
                                                                             
                                                                             
                                                                              
                                                       
   
                                                                         
                                     
   
                                                                                            
                                                                                  
   
                                                                
   
                                                                                                       
    
	public function seed(array $definitions, array $uuids): array {
		$counts = ['created' => 0, 'runs' => 0, 'skipped' => 0];
		if ($definitions === []) {
			return $counts;
		}

		$existing = [];
		foreach ($this->journeyService->listJourneys() as $journey) {
			$existing[(string)($journey['name'] ?? '')] = true;
		}

		$runSchema = $this->store->schemaSlug('journeyRun_schema', JourneyService::RUN_SCHEMA);

		foreach ($definitions as $definition) {
			$data = ($definition['data'] ?? []);
			if (isset($existing[(string)($data['name'] ?? '')]) === true) {
				$counts['skipped']++;
				continue;
			}

			if (in_array(($data['status'] ?? ''), ['draft', 'paused'], true) === false) {
				$data['status'] = 'draft';
			}

			$saved = $this->journeyService->save(payload: $data, createdByUid: '');
			$journeyId = $this->store->idOf(payload: $saved);
			if ($journeyId === '') {
				$this->logger->warning('Pipelinq demo seed: journey not saved', ['name' => ($data['name'] ?? '')]);
				continue;
			}

			$counts['created']++;
			$counts['runs'] += $this->seedRuns(
				runs: ($definition['runs'] ?? []),
				journeyId: $journeyId,
				uuids: $uuids,
				runSchema: $runSchema,
			);
		}             

		return $counts;
	}            

	   
                                                                          
   
                                                                           
                                                                            
                                                                           
                                                                             
   
                                                                                            
   
                                                   
   
                                                                                                       
    
	public function remove(array $definitions): array {
		$counts = ['journeys' => 0, 'runs' => 0];

		$names = $this->demoNames(definitions: $definitions);
		if ($names === []) {
			return $counts;
		}

		$journeySchema = $this->store->schemaSlug('journey_schema', JourneyService::JOURNEY_SCHEMA);
		$runSchema = $this->store->schemaSlug('journeyRun_schema', JourneyService::RUN_SCHEMA);

		foreach ($this->journeyService->listJourneys() as $journey) {
			if (isset($names[(string)($journey['name'] ?? '')]) === false) {
				continue;
			}

			$removed = $this->removeJourney(journey: $journey, journeySchema: $journeySchema, runSchema: $runSchema);
			$counts['journeys'] += $removed['journeys'];
			$counts['runs'] += $removed['runs'];
		}

		return $counts;
	}              

	   
                                        
   
                                                                                  
                                                     
                                                                                  
                                                    
   
                                         
    
	private function seedRuns(array $runs, string $journeyId, array $uuids, string $runSchema): int {
		$saved = 0;

		foreach ($runs as $run) {
			$payload = $this->values->resolvePlaceholders(data: ($run['data'] ?? []));
			$payload = $this->values->linkReference(
				data: $payload,
				definition: $run,
				uuids: $uuids,
				keyName: 'contactKey',
				section: 'contacts',
				field: 'contactId',
			);
			$payload = $this->values->linkReference(
				data: $payload,
				definition: $run,
				uuids: $uuids,
				keyName: 'clientKey',
				section: 'clients',
				field: 'clientId',
			);
			$payload['journeyId'] = $journeyId;

			if ($this->store->save(schemaSlug: $runSchema, payload: $payload) !== null) {
				$saved++;
			}
		}

		return $saved;
	}                

	   
                                                                     
   
                                                   
   
                                                                                            
   
                                             
    
	private function demoNames(array $definitions): array {
		$names = [];
		foreach ($definitions as $definition) {
			$name = (string)($definition['data']['name'] ?? '');
			if (str_starts_with($name, DemoSeedValues::DEMO_PREFIX) === true) {
				$names[$name] = true;
			}
		}

		return $names;
	}                 

	   
                                                                         
   
                                                         
                                                    
                                                    
   
                                                   
    
	private function removeJourney(array $journey, string $journeySchema, string $runSchema): array {
		$counts = ['journeys' => 0, 'runs' => 0];
		$journeyId = $this->store->idOf(payload: $journey);
		if ($journeyId === '') {
			return $counts;
		}

		$this->deleteFlow(flowUuid: trim((string)($journey['flowUuid'] ?? '')));

		foreach ($this->journeyService->runsFor(journeyId: $journeyId) as $run) {
			if ($this->store->delete(schemaSlug: $runSchema, id: $this->store->idOf(payload: $run)) === true) {
				$counts['runs']++;
			}
		}

		if ($this->store->delete(schemaSlug: $journeySchema, id: $journeyId) === true) {
			$counts['journeys']++;
		}

		return $counts;
	}                     

	   
                                                                              
   
                                                                           
   
                
    
	private function deleteFlow(string $flowUuid): void {
		if ($flowUuid === '') {
			return;
		}

		try {
			$this->container->get(JourneyService::FLOW_SERVICE)->delete($flowUuid);
		} catch (\Throwable $e) {
			$this->logger->info(
				'Pipelinq demo seed: left a demo journey flow in place',
				['flowUuid' => $flowUuid, 'exception' => $e->getMessage()]
			);
		}
	}                  
}           
