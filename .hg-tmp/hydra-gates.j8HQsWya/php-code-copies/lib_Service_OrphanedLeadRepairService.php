<?php

   
                                      
  
                                                                         
                                                                           
                                                                                  
                                                                         
                                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                             
  
                                                                                                                                                     
   
class OrphanedLeadRepairService {
	   
                                                          
    
	private const SCAN_LIMIT = 10000;

	   
                
   
                                                                         
                                                                                         
                                                                                                        
                                                                                
                                                            
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly SystemServiceAccount $systemAccount,
		private readonly OrphanedLeadPlacer $placer,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                       
   
                                                                                                 
   
                                                                                                                                                      
    
	public function repair(): array {
		return $this->systemAccount->runAsCurrentOrSystem(fn (): array => $this->repairAsCaller());
	}              

	   
                                                
   
                                                      
    
	private function repairAsCaller(): array {
		$counts = ['moved' => 0, 'failed' => 0];

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		$pipelineSchema = $this->appConfig->getValueString(Application::APP_ID, 'pipeline_schema', '');
		if ($register === '' || $leadSchema === '' || $pipelineSchema === '') {
			return $counts;
		}

		$pipelines = $this->readAll(register: $register, schema: $pipelineSchema);
		                                                                   
		                                                  
		if ($pipelines === []) {
			return $counts;
		}

		foreach ($this->readAll(register: $register, schema: $leadSchema) as $lead) {
			$patch = $this->placer->forOrphanedLead(lead: $lead, pipelines: $pipelines);
			$uuid = (string)($lead['id'] ?? '');
			if ($patch === [] || $uuid === '') {
				continue;
			}

			$payload = $lead;
			unset($payload['@self']);
			try {
				$this->objectService->saveObject(
					object: array_merge($payload, $patch),
					extend: [],
					register: $register,
					schema: $leadSchema,
					uuid: $uuid,
				);
				$counts['moved']++;
			} catch (Throwable $e) {
				$counts['failed']++;
				$this->logger->error(
					'Pipelinq: could not move a lead off a deleted pipeline',
					['uuid' => $uuid, 'exception' => $e->getMessage()]
				);
			}
		}             

		return $counts;
	}                      

	   
                                                  
   
                                            
                                          
   
                                                         
    
	private function readAll(string $register, string $schema): array {
		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => ['register' => $register, 'schema' => $schema],
					'limit' => self::SCAN_LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: could not read objects for the orphaned lead repair',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if ($row instanceof \JsonSerializable) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}               
}           
