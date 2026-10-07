<?php

   
                                  
  
                                                        
                                                                 
                                                                       
                                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;

   
                                                                   
  
                                                                          
                                                                      
                                                                         
                                                                          
                                  
  
                                                                 
   
class PosStaffReportService {
	   
                
   
                                                
                                                                                    
                                              
                                                                                         
                                                                                   
    
	public function __construct(
		private IAppConfig $appConfig,
		private PosStaffService $posStaffService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AggregationRunner $aggregationRunner,
	) {
	}                   

	   
                                                 
   
                                                                                                                                               
   
                                                                  
    
	public function staffSalesReport(): array {
		try {
			$byStaff = $this->aggregateStaffTotals();
		} catch (\Throwable $e) {
			                                                                   
			                                                       
			$this->logger->debug(
				'Pipelinq: staff-sales aggregation failed; using PHP fallback',
				['exception' => $e->getMessage()]
			);
			$byStaff = $this->aggregateStaffTotalsPhp(rows: $this->fetchFinalTransactions());
		}

		                                                                       
		foreach ($byStaff as $staffId => $_row) {
			try {
				$staff = $this->posStaffService->getStaff(id: $staffId);
				$byStaff[$staffId]['displayName'] = (string)($staff['displayName'] ?? $staffId);
			} catch (\Throwable $e) {
				$byStaff[$staffId]['displayName'] = $staffId;
			}
		}

		                                                         
		foreach ($byStaff as $staffId => $_row) {
			$byStaff[$staffId]['total'] = round($byStaff[$staffId]['total'], 2);
			$byStaff[$staffId]['totalTax'] = round($byStaff[$staffId]['totalTax'], 2);
		}

		return array_values($byStaff);
	}                        

	   
                                                                          
                                                  
   
                                                                             
                                                                   
                                                                              
                                                              
   
                                                                                
                                                                                    
   
                                                                           
                                                                              
                                                                            
                                                                               
                                                                           
             
   
                                                                                                                                  
                                   
   
                                                               
    
	private function aggregateStaffTotals(): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');
		$runner = $this->getAggregationRunner();

		$finalStatuses = ['confirmed', 'settled', 'refunded'];
		$positive = ['confirmed', 'settled'];

		$counts = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'count',
			field: null,
			filter: ['status' => ['in' => $finalStatuses]]
		);
		$posTotal = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'total',
			filter: ['status' => ['in' => $positive]]
		);
		$refTotal = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'total',
			filter: ['status' => 'refunded']
		);
		$posTotalTax = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'totalTax',
			filter: ['status' => ['in' => $positive]]
		);
		$refTotalTax = $this->groupedAgg(
			runner: $runner,
			register: $register,
			schema: $schema,
			metric: 'sum',
			field: 'totalTax',
			filter: ['status' => 'refunded']
		);

		$staffIds = array_keys(
			($counts + $posTotal + $refTotal + $posTotalTax + $refTotalTax)
		);

		$byStaff = [];
		foreach ($staffIds as $staffId) {
			                                                                   
			                               
			if ((string)$staffId === '') {
				continue;
			}

			$byStaff[(string)$staffId] = [
				'staffMemberId' => (string)$staffId,
				'displayName' => '',
				'transactionCount' => (int)($counts[$staffId] ?? 0),
				'total' => ((float)($posTotal[$staffId] ?? 0.0) - (float)($refTotal[$staffId] ?? 0.0)),
				'totalTax' => ((float)($posTotalTax[$staffId] ?? 0.0) - (float)($refTotalTax[$staffId] ?? 0.0)),
			];
		}

		return $byStaff;
	}                            

	   
                                                             
                                 
   
                                                              
                                             
                                         
                                    
                                                                
                                                       
   
                                                                                  
   
                                                                       
                                                                        
                        
    
	private function groupedAgg(
		object $runner,
		string $register,
		string $schema,
		string $metric,
		?string $field,
		array $filter,
	): array {
		$query = AggregationQuery::create(
			metric: $metric,
			field: $field,
			filter: $filter,
			groupBy: ['field' => 'staffMemberId'],
		);
		$result = $runner->runAdhocByRef(registerRef: $register, schemaRef: $schema, query: $query);

		$map = [];
		foreach (($result['groups'] ?? []) as $group) {
			$key = $group['key'] ?? null;
			if ($key === null) {
				continue;
			}

			$map[(string)$key] = ($group['value'] ?? 0);
		}

		return $map;
	}                  

	   
                                                                              
                                                                           
                                                            
   
                                                                                
   
                                                                                                                                  
                                   
    
	private function aggregateStaffTotalsPhp(array $rows): array {
		$byStaff = [];
		foreach ($rows as $tx) {
			$staffId = (string)($tx['staffMemberId'] ?? '');
			if ($staffId === '') {
				continue;
			}

			if (isset($byStaff[$staffId]) === false) {
				$byStaff[$staffId] = [
					'staffMemberId' => $staffId,
					'displayName' => '',
					'transactionCount' => 0,
					'total' => 0.0,
					'totalTax' => 0.0,
				];
			}

			$sign = 1.0;
			if (($tx['status'] ?? '') === 'refunded') {
				$sign = -1.0;
			}

			$byStaff[$staffId]['transactionCount']++;
			$byStaff[$staffId]['total'] += ($sign * (float)($tx['total'] ?? 0));
			$byStaff[$staffId]['totalTax'] += ($sign * (float)($tx['totalTax'] ?? 0));
		}             

		return $byStaff;
	}                               

	   
                                                                              
   
                                                                             
    
	private function fetchFinalTransactions(): array {
		[$register, $schema] = $this->config(schemaKey: 'posTransaction_schema');

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 5000,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: staff-sales report failed to load transactions',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$allowed = ['confirmed', 'settled', 'refunded'];
		$out = [];
		foreach ($results as $result) {
			$transaction = $this->toArray(object: $result);
			if (in_array((string)($transaction['status'] ?? ''), $allowed, true) === false) {
				continue;
			}

			$out[] = $transaction;
		}

		return $out;
	}                              

	   
                                                             
   
                                                       
   
                                                                   
   
                                                                             
    
	private function config(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

		if ($register === '' || $schema === '') {
			throw new OCSNotFoundException('POS register of schema is niet geconfigureerd.');
		}

		return [$register, $schema];
	}              

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                  
   
                                                                              
                                                                               
                                                                               
                                                                             
                                                         
   
                                          
    
	private function getAggregationRunner(): object {
		return $this->aggregationRunner;
	}                            

	   
                                              
   
                                       
   
                                                        
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return (array)$object;
	}               
}           
