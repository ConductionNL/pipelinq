<?php

   
                                    
  
                                                                   
                                                                      
                                                                           
                                                
  
                                                                             
                                                                        
                                                                       
                                                     
  
                                                                           
                                                                            
                                                                         
                              
                                                                           
                                                
                                                         
                                                               
                                                   
  
                                                                     
                                                                           
                                                                         
                                                                           
                                                                             
                                                                          
                                                                             
                                                                      
                                                                       
                                                                         
                                                          
  
                                                                       
                                                                  
                                                         
  
                    
                                     
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Psr\Log\LoggerInterface;

   
                                                        
  
                                                         
   
class ZgwCoexistenceValidator {
	   
                                                                  
    
	public const STUF_ENDPOINT_SCHEMA = 'stufEndpoint';

	   
                
   
                                                        
                                                
    
	public function __construct(
		private ZgwRegisterAccess $registers,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                  
   
                                                              
   
                
   
                                                                            
   
                                                                                                 
                                                                          
                                                                           
                 
                                                                                                     
    
	public function validateWritePath(string $municipalityCode): void {
		if ($municipalityCode === '') {
			return;
		}

		$zgwWriters = $this->activeZgwWriters(municipalityCode: $municipalityCode);
		$stufWriters = $this->activeStufWriters(municipalityCode: $municipalityCode);

		if ($zgwWriters !== [] && $stufWriters !== []) {
			$conflicting = array_values(array_unique(array_merge($zgwWriters, $stufWriters)));
			$msg = sprintf(
				'ZGW: dubbele schrijfpad-conflict voor gemeente "%s" — schakel een van de volgende endpoints uit: %s',
				$municipalityCode,
				implode(', ', $conflicting)
			);
			$this->logger->warning(
				'ZGW: double-write conflict detected',
				[
					'gemeente' => $municipalityCode,
					'conflicting' => $conflicting,
				]
			);
			throw new DoubleWritePathException($msg, $conflicting);
		}
	}                         

	   
                                                         
   
                                             
   
                              
    
	private function activeZgwWriters(string $municipalityCode): array {
		$rows = $this->registers->findAll(
			ZgwRegisterAccess::SCHEMA_ENDPOINT,
			['municipalityCode' => $municipalityCode]
		);
		$ids = [];
		foreach ($rows as $row) {
			$actief = (bool)($row['active'] ?? false);
			$readOnly = (bool)($row['readOnly'] ?? false);
			if ($actief === false || $readOnly === true) {
				continue;
			}

			$id = (string)($row['id'] ?? ($row['@self']['slug'] ?? ''));
			if ($id !== '') {
				$ids[] = 'zgw:' . $id;
			}
		}

		return $ids;
	}                        

	   
                                                          
   
                                             
   
                              
    
	private function activeStufWriters(string $municipalityCode): array {
		$rows = $this->registers->findAll(
			self::STUF_ENDPOINT_SCHEMA,
			['municipalityCode' => $municipalityCode]
		);
		$ids = [];
		foreach ($rows as $row) {
			$write = (string)($row['write'] ?? ($row['richting'] ?? ''));
			if ($write !== 'on' && $write !== 'true' && $write !== '1') {
				continue;
			}

			$id = (string)($row['id'] ?? ($row['@self']['slug'] ?? ''));
			if ($id !== '') {
				$ids[] = 'stuf:' . $id;
			}
		}

		return $ids;
	}                         
}           
