<?php

   
                            
  
                                                                               
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use Psr\Container\ContainerInterface;
use RuntimeException;

   
                        
  
                                                                           
                                                                      
  
                                                                 
   
class AdapterRegistry {

	   
                                                                     
   
                                                        
    
	private array $adapterClasses = [];

	   
                                                          
   
                                          
    
	private array $instances = [];

	   
                
   
                                                                                           
    
	public function __construct(
		private ContainerInterface $container,
	) {
		$this->register(platform: 'callvoip', adapterClass: CallVoipAdapter::class);
		$this->register(platform: 'ringcentral', adapterClass: RingCentralAdapter::class);
		$this->register(platform: 'asterisk', adapterClass: AsteriskAdapter::class);
	}                   

	   
                                                           
   
                                                             
                                                                              
   
                
                                                                                                                
    
	public function register(string $platform, string $adapterClass): void {
		$this->adapterClasses[$platform] = $adapterClass;
		unset($this->instances[$platform]);
	}                

	   
                                                    
   
                                                
   
                                                 
   
                                                                         
                                                                                                                
    
	public function get(string $platform): CtiAdapterInterface {
		$platform = strtolower(trim($platform));
		if (isset($this->instances[$platform]) === true) {
			return $this->instances[$platform];
		}

		if (isset($this->adapterClasses[$platform]) === false) {
			throw new RuntimeException(
				'CTI adapter not registered for platform: ' . $platform
			);
		}

		$adapter = $this->container->get($this->adapterClasses[$platform]);
		if (($adapter instanceof CtiAdapterInterface) === false) {
			throw new RuntimeException(
				'CTI adapter class did not implement CtiAdapterInterface: ' . $this->adapterClasses[$platform]
			);
		}

		$this->instances[$platform] = $adapter;
		return $adapter;
	}           

	   
                                             
   
                             
                                                                                                                
    
	public function listPlatforms(): array {
		return array_keys($this->adapterClasses);
	}                     
}           
