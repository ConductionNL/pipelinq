<?php

   
                                   
  
                                                                                
                                                                               
                                                                            
                          
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

   
                                                          
  
                                                                                
   
class SchemaEvolutionService {
	   
                                                                       
   
                                                                         
                                                                           
   
                                                        
                                                                                                       
   
                                                                                    
                                                                                    
                                                                                    
    
	public function compareColumns(array $current, array $previous): array {
		$changes = [];

		foreach ($current as $column => $type) {
			if (array_key_exists($column, $previous) === false) {
				$changes[] = "added: {$column}";
				continue;
			}

			if ((string)$previous[$column] !== (string)$type) {
				$changes[] = "changed: {$column} ({$previous[$column]} -> {$type})";
			}
		}

		foreach ($previous as $column => $type) {
			if (array_key_exists($column, $current) === false) {
				$changes[] = "removed: {$column}";
			}
		}

		return $changes;
	}                      

	   
                                                                            
   
                                                                         
                                                                                          
   
                                                                                      
   
                                                                                 
    
	public function compareToSnapshot(array $current, ?array $previous): array {
		if ($previous === null) {
			return [];
		}

		$previousColumns = $previous['columnDefinitionsJson'] ?? [];
		if (is_array($previousColumns) === false) {
			return [];
		}

		$normalized = [];
		foreach ($previousColumns as $column => $type) {
			$normalized[(string)$column] = (string)$type;
		}

		return $this->compareColumns(current: $current, previous: $normalized);
	}                         

	   
                                                                  
   
                                                                           
                                                   
   
                                                           
   
                                                         
   
                                                                        
                                                                   
   
                                                                                                                                   
    
	public function deriveColumnDefinitions(array $rows): array {
		$columns = [];
		foreach ($rows as $row) {
			foreach ($row as $column => $value) {
				$type = $this->typeOf(value: $value);
				$key = (string)$column;
				if (isset($columns[$key]) === false) {
					$columns[$key] = $type;
					continue;
				}

				if ($columns[$key] !== $type && $type !== 'null') {
					if ($columns[$key] === 'null') {
						$columns[$key] = $type;
					} elseif (str_contains($columns[$key], '|null') === false && $type === 'null') {
						$columns[$key] = $columns[$key] . '|null';
					} elseif ($columns[$key] !== $type) {
						$columns[$key] = 'string';
					}
				}
			}
		}

		return $columns;
	}                               

	   
                                 
   
                                  
   
                                 
    
	private function typeOf(mixed $value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value) === true) {
			return 'boolean';
		}

		if (is_int($value) === true) {
			return 'integer';
		}

		if (is_float($value) === true) {
			return 'double';
		}

		if (is_array($value) === true) {
			return 'object';
		}

		return 'string';
	}              
}           
