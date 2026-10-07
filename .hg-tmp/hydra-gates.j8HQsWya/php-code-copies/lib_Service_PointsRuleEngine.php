<?php

   
                             
  
                                                                         
                                                                               
                                                                            
                                                                              
                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                   
  
                                                                                                                                                
                                                                                      
                             
   
class PointsRuleEngine {
	   
                
   
                                                       
                                              
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                                     
   
                                                  
                                                                     
                                                                
   
                                                                                      
                                                               
    
	public function evaluateRules(string $programmeId, string $trigger, array $context): array {
		$rules = $this->loadRules(programmeId: $programmeId, trigger: $trigger);
		$matches = [];

		foreach ($rules as $rule) {
			if ($this->isWithinValidity(rule: $rule, context: $context) === false) {
				continue;
			}

			$condition = $rule['condition'] ?? [];
			if (is_array($condition) === false) {
				$condition = [];
			}

			if ($this->evaluateCondition(condition: $condition, context: $context) === true) {
				$matches[] = $rule;
			}
		}

		usort(
			$matches,
			static fn (array $a, array $b): int => (int)($b['priority'] ?? 1) <=> (int)($a['priority'] ?? 1)
		);

		return $matches;
	}                     

	   
                                                                    
   
                                                                           
   
                                     
                                                                                       
                              
    
	public function getHighestPriorityRule(array $rules): ?array {
		return $rules[0] ?? null;
	}                              

	   
                                                      
   
                                                                           
                                                                               
                              
   
                                                               
                                                                                          
   
                                                          
   
                                           
    
	public function evaluateCondition(array $condition, array $context): bool {
		if ($condition === []) {
			return true;
		}

		if ($this->passesExcludeCategory(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesCategory(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesSegment(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesChannel(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesDayOfWeek(condition: $condition, context: $context) === false) {
			return false;
		}

		if ($this->passesTimeRange(condition: $condition, context: $context) === false) {
			return false;
		}

		return true;
	}                         

	   
                                                                                   
   
                                                               
                                                            
   
                
    
	private function passesExcludeCategory(array $condition, array $context): bool {
		if (isset($condition['excludeCategory']) === false) {
			return true;
		}

		$excluded = (array)$condition['excludeCategory'];
		$contextCategory = (string)($context['category'] ?? '');
		if ($contextCategory !== '' && in_array($contextCategory, $excluded, true) === true) {
			return false;
		}

		return true;
	}                             

	   
                                                                             
   
                                                               
                                                            
   
                
    
	private function passesCategory(array $condition, array $context): bool {
		if (isset($condition['category']) === false) {
			return true;
		}

		$allowed = (array)$condition['category'];
		$contextCategory = (string)($context['category'] ?? '');
		if ($contextCategory === '' || in_array($contextCategory, $allowed, true) === false) {
			return false;
		}

		return true;
	}                      

	   
                                                                           
   
                                                               
                                                            
   
                
    
	private function passesSegment(array $condition, array $context): bool {
		if (isset($condition['segment']) === false) {
			return true;
		}

		$allowed = (array)$condition['segment'];
		$contextSegment = (string)($context['segment'] ?? '');
		if ($contextSegment === '' || in_array($contextSegment, $allowed, true) === false) {
			return false;
		}

		return true;
	}                     

	   
                                                                           
   
                                                               
                                                            
   
                
    
	private function passesChannel(array $condition, array $context): bool {
		if (isset($condition['channel']) === false) {
			return true;
		}

		$allowed = (array)$condition['channel'];
		$contextChannel = (string)($context['channel'] ?? '');
		if ($contextChannel === '' || in_array($contextChannel, $allowed, true) === false) {
			return false;
		}

		return true;
	}                     

	   
                                                                               
   
                                                               
                                                            
   
                
    
	private function passesDayOfWeek(array $condition, array $context): bool {
		if (isset($condition['dayOfWeek']) === false) {
			return true;
		}

		$allowed = array_map('strtolower', (array)$condition['dayOfWeek']);
		$day = $this->dayOfWeekFor(isoTimestamp: (string)($context['timestamp'] ?? ''));

		return in_array($day, $allowed, true);
	}                       

	   
                                                                               
   
                                                               
                                                            
   
                
    
	private function passesTimeRange(array $condition, array $context): bool {
		if (isset($condition['timeRange']) === false) {
			return true;
		}

		$timeRange = (string)$condition['timeRange'];

		return $this->isWithinTimeRange(timeRange: $timeRange, timestamp: (string)($context['timestamp'] ?? ''));
	}                       

	   
                                                               
   
                                                                       
                                                                            
   
                                                     
                                                   
                                                           
   
                                         
                                                               
    
	public function calculatePoints(array $formula, float $amount, float $multiplier = 1.0): int {
		$type = (string)($formula['type'] ?? '');

		$raw = 0.0;
		switch ($type) {
			case 'fixed':
				$raw = (float)($formula['value'] ?? 0);
				break;
			case 'percentage':
				$raw = $amount * (float)($formula['value'] ?? 0);
				break;
			case 'stepped':
				$brackets = $formula['brackets'] ?? [];
				if (is_array($brackets) === true) {
					foreach ($brackets as $bracket) {
						$bracketAmount = (float)($bracket['amount'] ?? 0);
						if ($amount >= $bracketAmount) {
							$raw = (float)($bracket['points'] ?? 0);
						}
					}
				}
				break;
			default:
				$raw = 0.0;
		}            

		$multiplied = $raw * max(0.0, $multiplier);

		return (int)floor($multiplied);
	}                       

	   
                                                             
   
                                                                                 
                                                     
   
                                                                                     
                                                             
                                                        
   
                                                            
   
                                           
    
	public function applyMaxPerCustomer(int $earnedInPeriod, int $pointsToAward, ?int $max): int {
		if ($max === null || $max <= 0) {
			return $pointsToAward;
		}

		$remaining = max(0, $max - $earnedInPeriod);
		return min($pointsToAward, $remaining);
	}                           

	   
                                                       
   
                                                  
                                            
   
                                            
    
	private function loadRules(string $programmeId, string $trigger): array {
		[$register, $schema] = $this->config();
		if ($register === '' || $schema === '' || $programmeId === '' || $trigger === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'trigger' => $trigger,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 200,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->debug('Pipelinq: rule findAll failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$ruleList = [];
		if (is_array($rows) === true) {
			$ruleList = array_values($rows);
		}

		return array_map([$this, 'toArray'], $ruleList);
	}                 

	   
                                                                           
   
                                                     
                                                            
   
                
    
	private function isWithinValidity(array $rule, array $context): bool {
		$effectiveTs = (string)($context['timestamp'] ?? '');
		if ($effectiveTs === '') {
			$effectiveTs = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		}

		$from = (string)($rule['validFrom'] ?? '');
		$to = (string)($rule['validTo'] ?? '');

		if ($from !== '' && substr($effectiveTs, 0, 10) < substr($from, 0, 10)) {
			return false;
		}

		if ($to !== '' && substr($effectiveTs, 0, 10) > substr($to, 0, 10)) {
			return false;
		}

		return true;
	}                        

	   
                                                                 
   
                                              
   
                                       
    
	private function dayOfWeekFor(string $isoTimestamp): string {
		$dateTime = new DateTimeImmutable('now');
		try {
			if ($isoTimestamp !== '') {
				$dateTime = new DateTimeImmutable($isoTimestamp);
			}
		} catch (\Throwable $e) {
			$dateTime = new DateTimeImmutable('now');
		}

		return strtolower($dateTime->format('l'));
	}                    

	   
                                                                
   
                                           
                                           
   
                
    
	private function isWithinTimeRange(string $timeRange, string $timestamp): bool {
		$parts = explode('-', $timeRange);
		if (count($parts) !== 2) {
			return true;
		}

		$dateTime = new DateTimeImmutable('now');
		try {
			if ($timestamp !== '') {
				$dateTime = new DateTimeImmutable($timestamp);
			}
		} catch (\Throwable $e) {
			$dateTime = new DateTimeImmutable('now');
		}

		$hhmm = $dateTime->format('H:i');
		return ($hhmm >= trim($parts[0])) && ($hhmm <= trim($parts[1]));
	}                         

	   
                                            
   
                                       
    
	private function config(): array {
		return [
			$this->appConfig->getValueString(Application::APP_ID, 'register', ''),
			$this->appConfig->getValueString(Application::APP_ID, 'pointsRule_schema', ''),
		];
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

		return [];
	}               

	   
                                       
   
                                      
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
