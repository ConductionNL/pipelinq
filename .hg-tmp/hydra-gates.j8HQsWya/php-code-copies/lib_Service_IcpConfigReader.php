<?php

   
                            
  
                                                                 
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

   
                                                 
  
                                                                                                   
   
class IcpConfigReader {
	   
                
   
                                                        
    
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}                   

	   
                                       
   
                                      
                                             
   
                                    
                                                                           
    
	public function getString(string $key, string $default = ''): string {
		return $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: $key,
			default: $default
		);
	}                 

	   
                                     
   
                                      
                                            
   
                
                                                                           
    
	public function setString(string $key, string $value): void {
		$this->appConfig->setValueString(
			app: Application::APP_ID,
			key: $key,
			value: $value
		);
	}                 

	   
                                                            
   
                                                                     
                                                                   
   
                                      
                                                   
   
                
                                                                           
    
	public function setSensitiveString(string $key, string $value): void {
		$this->appConfig->setValueString(
			app: Application::APP_ID,
			key: $key,
			value: $value,
			sensitive: true,
			lazy: true
		);
	}                          

	   
                                     
   
                                      
   
                                    
                                                                           
    
	public function getJsonArray(string $key): array {
		$value = $this->getString(key: $key, default: '[]');
		$decoded = json_decode(json: $value, associative: true);

		if (is_array(value: $decoded) === true) {
			return $decoded;
		}

		return [];
	}                    

	   
                                   
   
                                      
                                                      
   
                
                                                                           
    
	public function setJsonArray(string $key, mixed $value): void {
		$arrayValue = [];
		if (is_array(value: $value) === true) {
			$arrayValue = $value;
		}

		$this->setString(key: $key, value: json_encode(value: $arrayValue));
	}                    

	   
                                        
   
                                      
                                                                  
   
                                   
                                                                                                    
    
	public function isBoolTrue(string $key, string $default = 'true'): bool {
		return $this->getString(key: $key, default: $default) === 'true';
	}                  

	   
                                      
   
                                      
                                          
   
                
                                                                           
    
	public function setBool(string $key, mixed $value): void {
		$stringValue = 'false';
		if ($value === true) {
			$stringValue = 'true';
		}

		$this->setString(key: $key, value: $stringValue);
	}               

	   
                                         
   
                                      
   
                                  
                                                                                                    
    
	public function getInt(string $key): int {
		return (int)$this->getString(key: $key, default: '0');
	}              

	   
                                       
   
                                      
                                          
   
                
                                                                                                    
    
	public function setInt(string $key, mixed $value): void {
		$this->setString(key: $key, value: (string)(int)$value);
	}              
}           
