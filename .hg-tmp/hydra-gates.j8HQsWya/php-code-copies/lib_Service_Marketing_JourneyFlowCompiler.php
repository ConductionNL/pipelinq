<?php

   
                                
  
                                                                        
                                                                        
                                                         
  
                                                                            
                                                                            
                                                                             
                                                                                 
                                                                           
                                                                             
                                          
  
                                                                            
                                                                            
                                                            
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

   
                                                       
  
                                                                                                                                                                                   
   
class JourneyFlowCompiler {

	   
                                               
   
               
    
	public const REGISTER = 'pipelinq';

	   
                                                       
   
               
    
	public const ACTION_NODE = 'pipelinq.journey-action';

	   
                                                               
   
                                                                       
                                                                      
                                          
   
                              
    
	public const TRIGGER_SCHEMAS = [
		'leadStageChanged' => 'lead',
		'contractRenewalWindow' => 'salesContract',
		'listConfirmed' => 'subscription',
	];

	   
                                                        
   
               
    
	public const DEFAULT_CRON = '0 7 * * *';

	   
                                            
   
                                                             
                                                                               
                                                              
   
                                                                            
   
                                                                                                                                                                                    
    
	public function documentFor(array $journey, string $journeyId, string $runAs = ''): array {
		$trigger = (array)($journey['trigger'] ?? []);
		$kind = (string)($trigger['kind'] ?? '');
		$schema = (self::TRIGGER_SCHEMAS[$kind] ?? '');

		$nodes = [$this->triggerNode(kind: $kind, schema: $schema, trigger: $trigger, runAs: $runAs)];
		$order = ['start'];

		$waitFor = trim((string)($journey['waitFor'] ?? ''));
		if ($waitFor !== '') {
			$nodes[] = ['id' => 'hold', 'type' => 'openregister.wait', 'config' => ['for' => $waitFor]];
			$order[] = 'hold';
		}

		$condition = $this->conditionFor(condition: (array)($journey['condition'] ?? []));
		if ($condition !== null) {
			$nodes[] = [
				'id' => 'gate',
				'type' => 'openregister.switch',
				'config' => [],
				'exits' => [
					['id' => 'match', 'condition' => $condition],
					['id' => 'skip'],
				],
			];
			$order[] = 'gate';
		}

		$nodes[] = [
			'id' => 'act',
			'type' => self::ACTION_NODE,
			'config' => ['journey' => $journeyId],
		];
		$order[] = 'act';

		$nodes[] = ['id' => 'end', 'type' => 'openregister.end', 'config' => []];
		$order[] = 'end';

		$scheduled = ($schema === '');
		$flowTrigger = 'object.updated';
		$triggerRegister = self::REGISTER;
		$cron = '';
		if ($scheduled === true) {
			$flowTrigger = 'schedule';
			$triggerRegister = '';
			$cron = $this->cronFor(trigger: $trigger);
		}

		return [
			'name' => $this->flowName(journey: $journey, journeyId: $journeyId),
			'description' => (string)($journey['description'] ?? ''),
			'app' => self::REGISTER,
			'applicationSlug' => self::REGISTER,
			'trigger' => $flowTrigger,
			'triggerRegister' => $triggerRegister,
			'triggerSchema' => $schema,
			'cron' => $cron,
			'executionMode' => 'async',
			'enabled' => (strtolower((string)($journey['status'] ?? 'draft')) === 'active'),
			'limits' => ['maxTransitions' => 200],
			'nodes' => $nodes,
			'edges' => $this->edgesFor(order: $order, hasCondition: ($condition !== null)),
		];
	}                   

	   
                                        
   
                                                   
                                                                                     
                                                           
                                                              
   
                                          
    
	private function triggerNode(string $kind, string $schema, array $trigger, string $runAs): array {
		if ($schema === '') {
			                                                                 
			                                                           
			return [
				'id' => 'start',
				'type' => 'openregister.trigger-schedule',
				'config' => ['cron' => $this->cronFor(trigger: $trigger), 'runAs' => $runAs],
			];
		}

		                                                                    
		                                                                 
		                                     
		unset($kind);

		return [
			'id' => 'start',
			'type' => 'openregister.trigger-object',
			'config' => ['event' => 'object.updated', 'register' => self::REGISTER, 'schema' => $schema],
		];
	}                   

	   
                                                         
   
                                                                         
   
                                                                                     
    
	private function conditionFor(array $condition): ?array {
		$field = trim((string)($condition['field'] ?? ''));
		if ($field === '') {
			return null;
		}

		$path = ['var' => 'json.' . $field];
		$value = ($condition['value'] ?? '');
		$operator = (string)($condition['operator'] ?? 'equals');

		return match ($operator) {
			'notEquals' => ['!=' => [$path, $value]],
			'isNull' => ['!' => [$path]],
			'isNotNull' => ['!!' => [$path]],
			default => ['==' => [$path, $value]],
		};
	}                    

	   
                                            
   
                                                            
                                                             
   
                                                        
    
	private function edgesFor(array $order, bool $hasCondition): array {
		$edges = [];
		$count = count($order);
		for ($index = 0; $index < ($count - 1); $index++) {
			$from = $order[$index];
			$edge = ['id' => 'e' . ($index + 1), 'from' => $from, 'to' => $order[($index + 1)]];
			if ($from === 'gate' && $hasCondition === true) {
				$edge['fromExit'] = 'match';
			}

			$edges[] = $edge;
		}

		if ($hasCondition === true) {
			                                                                 
			                                                   
			$edges[] = ['id' => 'e-skip', 'from' => 'gate', 'fromExit' => 'skip', 'to' => 'end'];
		}

		return $edges;
	}                

	   
                                         
   
                                                           
   
                                                
    
	private function cronFor(array $trigger): string {
		$cron = trim((string)($trigger['cron'] ?? ''));
		if ($cron === '' || count(preg_split('/\s+/', $cron)) !== 5) {
			return self::DEFAULT_CRON;
		}

		return $cron;
	}               

	   
                                                               
   
                                                             
                                            
   
                            
    
	private function flowName(array $journey, string $journeyId): string {
		$name = trim((string)($journey['name'] ?? ''));
		if ($name === '') {
			return ('Journey ' . $journeyId);
		}

		return ('Journey: ' . $name);
	}                
}           
