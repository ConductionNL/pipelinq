<?php

   
                                 
  
                                                                                  
                                                                              
                                                                               
                                                                                    
                                                              
  
                                                                                  
                                                                           
                                                                                 
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Lifecycle;

   
                                                                                  
  
                                                                                 
                                                                                   
                                                                                
  
                                                        
  
                                                                     
                                                                             
                                                                           
                    
   
final class SchemaLifecycleGraph {

	   
                                                    
   
               
    
	private string $mainRegisterPath;

	   
                                                               
   
               
    
	private string $fragmentDir;

	   
                
   
                                                                                    
                                                                                    
    
	public function __construct(?string $settingsDir = null) {
		$dir = $settingsDir;
		if ($dir === null) {
			$dir = dirname(__DIR__, 2) . '/Settings';
		}

		$this->mainRegisterPath = $dir . '/pipelinq_register.json';
		$this->fragmentDir = $dir . '/register.d';
	}                   

	   
                                                                       
   
                                                                              
                                                                                  
   
                                                                    
   
                                                                                              
                                                                         
    
	public function adjacencyFor(string $schemaSlug): array {
		$lifecycle = $this->lifecycleFor(schemaSlug: $schemaSlug);
		if ($lifecycle === null) {
			return [];
		}

		return $this->normaliseTransitions(lifecycle: $lifecycle);
	}                    

	   
                                                                        
   
                                                                              
                                                                                
                                                                               
                                                                           
                                                          
   
                                              
   
                                                                                          
                                                                         
    
	public function fullAdjacencyFor(string $schemaSlug): array {
		$lifecycle = $this->lifecycleFor(schemaSlug: $schemaSlug);
		if ($lifecycle === null) {
			return [];
		}

		$graph = $this->normaliseTransitions(lifecycle: $lifecycle);
		$field = (string)($lifecycle['field'] ?? ($lifecycle['property'] ?? ''));

		$states = $this->statesFor(schemaSlug: $schemaSlug, field: $field);
		foreach ($states as $state) {
			if (isset($graph[$state]) === false) {
				$graph[$state] = [];
			}
		}

		return $graph;
	}                        

	   
                                                                       
   
                                              
                                                  
   
                                                                         
    
	private function statesFor(string $schemaSlug, string $field): array {
		if ($field === '') {
			return [];
		}

		foreach ($this->resolveRegisterFiles() as $file) {
			$decoded = $this->decodeRegisterFile(file: $file);
			if ($decoded === null) {
				continue;
			}

			$schema = $this->findSchemaInDecoded(decoded: $decoded, schemaSlug: $schemaSlug);
			if ($schema === null) {
				continue;
			}

			return $this->enumFromSchema(schema: $schema, field: $field);
		}

		return [];
	}                 

	   
                                                                          
                                                                       
   
                              
    
	private function resolveRegisterFiles(): array {
		$files = [$this->mainRegisterPath];
		if (is_dir($this->fragmentDir) === true) {
			$glob = glob($this->fragmentDir . '/*.json');
			if (is_array($glob) === true) {
				sort($glob);
				$files = array_merge($files, $glob);
			}
		}

		return $files;
	}                            

	   
                                                                    
   
                                                              
   
                                                                                            
    
	private function decodeRegisterFile(string $file): ?array {
		if (is_file($file) === false || is_readable($file) === false) {
			return null;
		}

		$raw = file_get_contents($file);
		if ($raw === false) {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}                          

	   
                                                                         
   
                                                                       
                                                         
   
                                                                               
    
	private function findSchemaInDecoded(array $decoded, string $schemaSlug): ?array {
		$schemas = ($decoded['components']['schemas'] ?? []);
		if (is_array($schemas) === false) {
			return null;
		}

		foreach ($schemas as $key => $schema) {
			if (is_array($schema) === false) {
				continue;
			}

			$slug = (string)($schema['slug'] ?? $key);
			if ($slug === $schemaSlug) {
				return $schema;
			}
		}

		return null;
	}                           

	   
                                                              
   
                                                   
                                           
   
                                                                                      
    
	private function enumFromSchema(array $schema, string $field): array {
		$enum = ($schema['properties'][$field]['enum'] ?? []);
		if (is_array($enum) === false) {
			return [];
		}

		return array_map(static fn ($value): string => (string)$value, $enum);
	}                      

	   
                                                                            
   
                                                                            
                                                                            
                                                                          
                                                                   
                                                                             
                                                 
   
                                              
                                                                                    
   
                                                                             
   
                                           
    
	public function configurationFor(string $schemaSlug, string $key): ?array {
		foreach ($this->resolveRegisterFiles() as $file) {
			$decoded = $this->decodeRegisterFile(file: $file);
			if ($decoded === null) {
				continue;
			}

			$schemas = ($decoded['components']['schemas'] ?? []);
			if (is_array($schemas) === false) {
				continue;
			}

			$annotation = $this->configurationFromSchemas(schemas: $schemas, schemaSlug: $schemaSlug, key: $key);
			if ($annotation !== null) {
				return $annotation;
			}
		}

		return null;
	}                        

	   
                                                                                   
   
                                                                                
                                                                               
                                                                               
                      
   
                                                                              
                                                         
                                             
   
                                                                                              
    
	private function configurationFromSchemas(array $schemas, string $schemaSlug, string $key): ?array {
		foreach ($schemas as $schemaKey => $schema) {
			if (is_array($schema) === false) {
				continue;
			}

			$slug = (string)($schema['slug'] ?? $schemaKey);
			if ($slug !== $schemaSlug) {
				continue;
			}

			$config = ($schema['configuration'] ?? []);
			if (is_array($config) === false) {
				continue;
			}

			$annotation = ($config[$key] ?? null);
			if (is_array($annotation) === true) {
				return $annotation;
			}
		}

		return null;
	}                                

	   
                                                                            
   
                                              
   
                                                                             
   
                                           
    
	public function lifecycleFor(string $schemaSlug): ?array {
		foreach ($this->resolveRegisterFiles() as $file) {
			$lifecycle = $this->lifecycleFromFile(file: $file, schemaSlug: $schemaSlug);
			if ($lifecycle !== null) {
				return $lifecycle;
			}
		}

		return null;
	}                    

	   
                                                                              
   
                                                              
                                                         
   
                                                                          
    
	private function lifecycleFromFile(string $file, string $schemaSlug): ?array {
		$decoded = $this->decodeRegisterFile(file: $file);
		if ($decoded === null) {
			return null;
		}

		$schema = $this->findSchemaInDecoded(decoded: $decoded, schemaSlug: $schemaSlug);
		if ($schema === null) {
			return null;
		}

		$config = ($schema['configuration'] ?? []);
		if (is_array($config) === false) {
			return null;
		}

		$lifecycle = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === true) {
			return $lifecycle;
		}

		return null;
	}                         

	   
                                                                                   
   
                                                                                    
                                                                          
   
                                                                                     
   
                                                            
    
	private function normaliseTransitions(array $lifecycle): array {
		$transitions = ($lifecycle['transitions'] ?? []);
		if (is_array($transitions) === false) {
			return [];
		}

		$graph = [];
		foreach ($transitions as $spec) {
			$this->mergeTransitionSpec(graph: $graph, spec: $spec);
		}

		return $graph;
	}                            

	   
                                                                              
   
                                                                                                     
                                                               
   
                
    
	private function mergeTransitionSpec(array &$graph, mixed $spec): void {
		if (is_array($spec) === false) {
			return;
		}

		$to = ($spec['to'] ?? null);
		if (is_string($to) === false || $to === '') {
			return;
		}

		$from = ($spec['from'] ?? []);
		if (is_string($from) === true) {
			$from = [$from];
		}

		if (is_array($from) === false) {
			return;
		}

		foreach ($from as $fromState) {
			$state = (string)$fromState;
			if (isset($graph[$state]) === false) {
				$graph[$state] = [];
			}

			if (in_array($to, $graph[$state], true) === false) {
				$graph[$state][] = $to;
			}
		}
	}                           
}           
