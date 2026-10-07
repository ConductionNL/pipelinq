<?php

   
                            
  
                                                        
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                 
  
                                                                                                   
   
class KvkResultMapper {
	   
                                                
   
                                               
                                                     
   
                                                 
                                                                            
    
	public function mapResult(array $item, string $sbiCode): ?array {
		$kvkNumber = $item['kvkNummer'] ?? null;
		if ($kvkNumber === null) {
			return null;
		}

		return [
			'kvkNumber' => (string)$kvkNumber,
			'tradeName' => $this->extractTradeName(item: $item),
			'legalForm' => $item['rechtsvorm'] ?? '',
			'sbiCode' => $sbiCode,
			'sbiDescription' => $this->findSbiDescription(item: $item, sbiCode: $sbiCode),
			'employeeCount' => $item['totaalWerkzamePersonen'] ?? null,
			'address' => $this->extractAddress(item: $item),
			'website' => null,
			'registrationDate' => $item['registratieDatum'] ?? null,
			'isActive' => $this->isCompanyActive(item: $item),
			'source' => 'kvk',
		];
	}                 

	   
                                             
   
                                               
   
                                  
    
	private function extractTradeName(array $item): string {
		return $item['eersteHandelsnaam'] ?? ($item['name'] ?? '');
	}                        

	   
                                                  
   
                                               
   
                                     
    
	private function extractAddress(array $item): array {
		$address = $item['adres'] ?? ($item['vestingAdres'] ?? []);

		return [
			'street' => ($address['straatnaam'] ?? '') . ' ' . ($address['huisnummer'] ?? ''),
			'city' => $address['plaats'] ?? '',
			'province' => $address['provincie'] ?? '',
			'postalCode' => $address['postcode'] ?? '',
		];
	}                      

	   
                                 
   
                                               
   
                                           
    
	private function isCompanyActive(array $item): bool {
		return ($item['active'] ?? 'Ja') === 'Ja';
	}                       

	   
                                                     
   
                                               
                                                    
   
                                                       
    
	private function findSbiDescription(array $item, string $sbiCode): string {
		$sbiActivities = $item['spiActiviteiten'] ?? [];

		foreach ($sbiActivities as $activity) {
			$activityCode = (string)($activity['sbiCode'] ?? '');
			if (str_starts_with(haystack: $activityCode, needle: $sbiCode) === true) {
				return $activity['sbiOmschrijving'] ?? '';
			}
		}

		return '';
	}                          
}           
