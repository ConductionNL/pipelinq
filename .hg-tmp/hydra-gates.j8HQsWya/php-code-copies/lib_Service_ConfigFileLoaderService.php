<?php

   
                                    
  
                                                                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use RuntimeException;

   
                                                            
  
                                                                                  
                                                                               
                                                        
  
                                                         
   
class ConfigFileLoaderService {
	   
                                     
   
               
    
	private const REGISTER_FILE = '/lib/Settings/pipelinq_register.json';

	   
                                                                            
   
                                                                         
                                                                          
                                                                             
                                                                             
   
               
    
	private const REGISTER_FRAGMENT_DIR = '/lib/Settings/register.d';

	   
                
   
                                                   
    
	public function __construct(
		private IAppManager $appManager,
	) {
	}                   

	   
                                               
   
                                                
   
                                                                  
                                                                            
    
	public function loadConfigurationFile(): array {
		$appPath = $this->appManager->getAppPath(Application::APP_ID);
		$absoluteFilePath = $appPath . self::REGISTER_FILE;

		if (file_exists($absoluteFilePath) === false) {
			throw new RuntimeException("Configuration file not found: {$absoluteFilePath}");
		}

		$jsonContent = file_get_contents($absoluteFilePath);
		if ($jsonContent === false) {
			throw new RuntimeException("Failed to read configuration file: {$absoluteFilePath}");
		}

		$data = json_decode($jsonContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('Invalid JSON in configuration file: ' . json_last_error_msg());
		}

		$data = $this->mergeRegisterFragments(data: $data, appPath: $appPath);

		return $data;
	}                             

	   
                                                                          
   
                                                                           
                                                                              
                                                                        
                                                                    
                                                                       
   
                                                                        
                       
   
                                                              
                                                      
   
                                                
   
                                                                         
   
                                                                           
                                                                         
                                                                                 
                          
    
	private function mergeRegisterFragments(array $data, string $appPath): array {
		$fragmentDir = $appPath . self::REGISTER_FRAGMENT_DIR;
		if (is_dir($fragmentDir) === false) {
			return $data;
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		if ($fragmentFiles === false || empty($fragmentFiles) === true) {
			return $data;
		}

		sort($fragmentFiles);

		$fragmentBlob = '';
		foreach ($fragmentFiles as $fragmentFile) {
			$fragmentContent = file_get_contents($fragmentFile);
			if ($fragmentContent === false) {
				throw new RuntimeException("Failed to read register fragment: {$fragmentFile}");
			}

			$fragmentData = json_decode($fragmentContent, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new RuntimeException(
					"Invalid JSON in register fragment {$fragmentFile}: " . json_last_error_msg()
				);
			}

			if (is_array($fragmentData) === false) {
				continue;
			}

			$data = self::deepMergeConfig(base: $data, override: $fragmentData, path: '');
			$fragmentBlob .= $fragmentContent;
		}             

		if ($fragmentBlob !== '') {
			$baseVersion = ($data['info']['version'] ?? '0.0.0');
			$fragmentHash = substr(hash('sha256', $fragmentBlob), 0, 8);
			if (isset($data['info']) === false || is_array($data['info']) === false) {
				$data['info'] = [];
			}

			$data['info']['version'] = $baseVersion . '+frag.' . $fragmentHash;
		}

		return $data;
	}                              

	   
                                                                       
                                        
   
                                                                          
                                                                         
                                                                             
                                                                   
                                                                     
                                                                       
                                                                          
                                            
   
                           
    
	private const ADDITIVE_LIST_PATHS = [
		'components.objects',
		'components.registers.*.schemas',
	];

	   
                                                               
   
                                                                         
                                                                            
                                                                            
                                                                      
                                                                        
                                                                         
                                                                           
   
                                                    
                                                                    
                                                                      
   
                                         
   
                                                                              
                                                                                
                                         
                                                                                    
                                                                              
    
	private static function deepMergeConfig(array $base, array $override, string $path = ''): array {
		foreach ($override as $key => $value) {
			$childPath = $path . '.' . $key;
			if ($path === '') {
				$childPath = (string)$key;
			}

			                                                                   
			                                                                 
			                                                                
			                                                               
			                                                                    
			                                                                   
			                                                                    
			if (is_array($value) === true
				&& $value === []
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
				&& $base[$key] !== []
			) {
				continue;
			}

			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
				&& self::isList(value: $value) === true
				&& self::isList(value: $base[$key]) === true
				&& self::isAdditiveListPath(path: $childPath) === true
			) {
				$base[$key] = self::unionLists(base: $base[$key], override: $value);
				continue;
			}

			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
				&& self::isList(value: $value) === false
				&& self::isList(value: $base[$key]) === false
			) {
				$base[$key] = self::deepMergeConfig(base: $base[$key], override: $value, path: $childPath);
				continue;
			}

			$base[$key] = $value;
		}             

		return $base;
	}                       

	   
                                                                        
   
                                                                         
                                                                        
                                                         
   
                                                      
   
                                                                 
    
	private static function isAdditiveListPath(string $path): bool {
		$segments = explode('.', $path);
		foreach (self::ADDITIVE_LIST_PATHS as $pattern) {
			$patternSegments = explode('.', $pattern);
			if (count($patternSegments) !== count($segments)) {
				continue;
			}

			$match = true;
			foreach ($patternSegments as $index => $patternSegment) {
				if ($patternSegment !== '*' && $patternSegment !== $segments[$index]) {
					$match = false;
					break;
				}
			}

			if ($match === true) {
				return true;
			}
		}             

		return false;
	}                          

	   
                                                                          
                                                                           
                                   
   
                                     
                                                        
   
                                   
    
	private static function unionLists(array $base, array $override): array {
		$result = array_values($base);
		foreach ($override as $candidate) {
			$exists = false;
			foreach ($result as $existing) {
				if (self::valuesEqual(left: $existing, right: $candidate) === true) {
					$exists = true;
					break;
				}
			}

			if ($exists === false) {
				$result[] = $candidate;
			}
		}

		return $result;
	}                  

	   
                                                         
   
                                                                            
                                                                       
                                                  
   
                                       
                                         
   
                                                       
    
	private static function valuesEqual(mixed $left, mixed $right): bool {
		if (is_array($left) === true && is_array($right) === true) {
			$leftCopy = $left;
			$rightCopy = $right;
			self::ksortRecursive(value: $leftCopy);
			self::ksortRecursive(value: $rightCopy);
			return json_encode($leftCopy) === json_encode($rightCopy);
		}

		return $left === $right;
	}                   

	   
                                                                        
   
                                                        
   
                
    
	private static function ksortRecursive(array &$value): void {
		foreach ($value as &$child) {
			if (is_array($child) === true) {
				self::ksortRecursive(value: $child);
			}
		}

		unset($child);
		if (self::isList(value: $value) === false) {
			ksort($value);
		}
	}                      

	   
                                                                            
   
                                                                         
   
                                             
   
                                                          
    
	private static function isList(array $value): bool {
		if (function_exists('array_is_list') === true) {
			return array_is_list($value);
		}

		$expectedKey = 0;
		foreach (array_keys($value) as $key) {
			if ($key !== $expectedKey) {
				return false;
			}

			$expectedKey++;
		}

		return true;
	}              

	   
                                                                      
   
                                              
   
                                                   
                                                                           
    
	public function ensureSourceType(array $data): array {
		if (isset($data['x-openregister']) === false) {
			$data['x-openregister'] = [];
		}

		if (isset($data['x-openregister']['sourceType']) === false) {
			$data['x-openregister']['sourceType'] = 'local';
		}

		return $data;
	}                        
}           
