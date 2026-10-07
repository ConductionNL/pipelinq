<?php

   
                                      
  
                                                                        
                                                                          
                          
  
                                                                             
                                                                          
                                                                          
                                                                           
                                                                           
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

   
                                                        
  
                                                                                                                                                                                              
   
class ShillinqInvoiceProjection {

	   
                                                      
   
               
    
	public const DRAFT_STATE = 'draft';

	   
                                                               
   
                                                                         
                                                                         
                               
   
                                                             
   
                                                                           
                                            
   
                                                                                                                                                                                               
    
	public function whole(array $row): ?array {
		$state = strtolower(trim((string)($row['lifecycleState'] ?? '')));
		if ($state === '' || $state === self::DRAFT_STATE) {
			return null;
		}

		$id = $this->identify(row: $row);
		if ($id === '') {
			return null;
		}

		return [
			'id' => $id,
			'amount' => (float)($row['grossAmount'] ?? 0),
			'currency' => (string)($row['currency'] ?? 'EUR'),
			'invoiceDate' => substr((string)($row['invoiceDate'] ?? ''), 0, 10),
			'dueDate' => substr((string)($row['dueDate'] ?? ''), 0, 10),
			'lifecycleState' => $state,
			'lines' => $this->lines(value: ($row['invoiceLines'] ?? null)),
		];
	}             

	   
                                         
   
                                             
   
                                                           
   
                                                                                                                                                                                               
    
	public function identify(array $row): string {
		foreach ([$row, (array)($row['@self'] ?? [])] as $source) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($source[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                

	   
                                                                      
   
                                                                       
                                        
   
                                                     
   
                                                                      
   
                                                                                                                                                                                               
    
	public function lines(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$lines = [];
		foreach ($value as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$itemName = trim((string)($line['itemName'] ?? ''));
			if ($itemName === '') {
				continue;
			}

			$lines[] = [
				'itemName' => $itemName,
				'netAmount' => (float)($line['netAmount'] ?? 0),
				'quantity' => (float)($line['quantity'] ?? 0),
				'unitCode' => (string)($line['unitCode'] ?? ''),
			];
		}

		return $lines;
	}             
}           
