<?php

   
                
  
                                                                          
                                                                             
                                                                            
                                                                          
                                                       
                                                
  
                                                                       
                                                                         
                                                                            
                                                                           
                                   
  
                                                                          
            
                                                             
                                                                             
                                                                             
                                                                      
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                              
  
                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

   
              
  
                                                    
                                                                     
                                                    
                                                                
   
class LeadService {

	   
                                                                                  
   
               
    
	private const OR_OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	   
                                                                
                                                                   
   
            
    
	private const AGGREGATION_FETCH_CAP = 500;

	   
                
   
                                                                                 
                                                                         
                                                                           
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly McpAnswer $mcp,
	) {
	}                   

	                                                                      
	                                                                          
	                                                                        
	                                                                       
	                                                                 
	#[McpTool(
		name: 'createLead',
		subject: 'lead',
		action: 'create',
		description: 'Create a new sales lead. Only "title" is required; client, value, source and assignee are optional.',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'create'
	)]
	   
                                                                     
                                     
   
                                                       
                                                                          
                                                                          
                                                                         
                                                                 
                                                                
                    
   
                                                                
                                                                           
                                                           
                                                                                               
                                                                              
   
                                
   
                                                                                                                   
    
	public function createLead(
		string $title,
		?string $client = null,
		?float $value = null,
		?string $source = null,
		?string $assignee = null,
	): array {
		$title = trim($title);
		if ($title === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument title is missing.');
		}

		$config = $this->resolveLeadContext();
		if (isset($config['error']) === true) {
			return $config;
		}

		$payload = ['title' => $title];

		if ($client !== null && $client !== '') {
			$payload['client'] = $client;
		}

		if ($value !== null) {
			$payload['value'] = $value;
		}

		if ($source !== null && $source !== '') {
			$payload['source'] = $source;
		}

		if ($assignee !== null && $assignee !== '') {
			$payload['assignee'] = $assignee;
		}

		try {
			$objectService = $this->getObjectService();

			$saved = $objectService->saveObject(
				object: $payload,
				register: $config['register'],
				schema: $config['schema'],
				uuid: null,
			);
		} catch (\Exception $e) {
			return $this->mcp->fromException(operation: 'create lead', exception: $e);
		}         

		return ['lead' => $this->mcp->toArray(item: $saved)];
	}                  

	                                                                      
	                                                                          
	                                                                        
	                                                                       
	                                                                 
	#[McpTool(
		name: 'pipelineForecast',
		                                                                  
		                                                                  
		                                                           
		subject: 'pipeline',
		action: 'forecast',
		description: 'Per-stage totals over open leads: lead count, summed value, weighted value, plus a grand total.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read'
	)]
	   
                                                                        
                              
   
                                                                      
                                                                    
                                                                        
                                                                       
                                                                 
   
                                
   
                                                                                                              
    
	public function pipelineForecast(): array {
		$config = $this->resolveLeadContext();
		if (isset($config['error']) === true) {
			return $config;
		}

		try {
			$objectService = $this->getObjectService();

			$rawLeads = $objectService->findAll(
				[
					'filters' => [
						'register' => $config['register'],
						'schema' => $config['schema'],
						'status' => 'open',
					],
					'limit' => self::AGGREGATION_FETCH_CAP,
				]
			);
		} catch (\Exception $e) {
			return $this->mcp->fromException(operation: 'compute pipeline forecast', exception: $e);
		}         

		return $this->buildForecastFromLeads(rawLeads: $rawLeads);
	}                        

	                                                                            
	                  
	                                                                            

	   
                                                                          
                
   
                                                            
   
                                
    
	private function buildForecastFromLeads(iterable $rawLeads): array {
		$stages = [];
		foreach ($rawLeads as $raw) {
			$lead = $this->mcp->toArray(item: $raw);
			$stage = (string)($lead['stage'] ?? 'unspecified');
			$order = (int)($lead['stageOrder'] ?? PHP_INT_MAX);

			if (isset($stages[$stage]) === false) {
				$stages[$stage] = [
					'stage' => $stage,
					'order' => $order,
					'leadCount' => 0,
					'value' => 0.0,
					'weightedValue' => 0.0,
				];
			}

			$stages[$stage]['leadCount']++;
			$stages[$stage]['value'] += (float)($lead['value'] ?? 0);
			$stages[$stage]['weightedValue'] += (float)($lead['weightedValue'] ?? 0);
			$stages[$stage]['order'] = min($stages[$stage]['order'], $order);
		}             

		usort(
			$stages,
			static function (array $left, array $right): int {
				return $left['order'] <=> $right['order'];
			}
		);

		$rows = [];
		$totalCount = 0;
		$totalValue = 0.0;
		$totalWeight = 0.0;
		foreach ($stages as $row) {
			unset($row['order']);
			$rows[] = $row;
			$totalCount += $row['leadCount'];
			$totalValue += $row['value'];
			$totalWeight += $row['weightedValue'];
		}

		return [
			'stages' => $rows,
			'total' => [
				'leadCount' => $totalCount,
				'value' => $totalValue,
				'weightedValue' => $totalWeight,
			],
		];

	}                              

	   
                                                               
   
                                                                                                  
    
	private function resolveLeadContext(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

		if ($register === '' || $schema === '') {
			return $this->mcp->error(
				code: 'not_configured',
				message: 'Pipelinq is not fully configured: the OpenRegister register or lead schema is missing.'
			);
		}

		return ['register' => $register, 'schema' => $schema];
	}                          

	   
                                                                
   
                                                           
   
                                                              
    
	private function getObjectService(): object {
		try {
			return $this->container->get(self::OR_OBJECT_SERVICE);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister service is not available.');
		}

	}                        

}           
