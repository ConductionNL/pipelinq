<?php

   
                                       
  
                                                                   
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                            
  
                                                                                                   
   
class OpenCorporatesResultMapper {
	   
                                                        
   
                                               
   
                                                 
                                                                            
    
	public function mapResult(array $company): ?array {
		$companyNumber = $company['company_number'] ?? null;
		if ($companyNumber === null) {
			return null;
		}

		return [
			'kvkNumber' => (string)$companyNumber,
			'tradeName' => $company['name'] ?? '',
			'legalForm' => $company['company_type'] ?? '',
			'sbiCode' => '',
			'sbiDescription' => $this->extractSbiDescription(company: $company),
			'employeeCount' => null,
			'address' => $this->mapAddress(address: $company['registered_address'] ?? []),
			'website' => null,
			'registrationDate' => $company['incorporation_date'] ?? null,
			'isActive' => $this->isCompanyActive(company: $company),
			'source' => 'opencorporates',
		];
	}                 

	   
                                                          
   
                                               
   
                                                       
    
	private function extractSbiDescription(array $company): string {
		return $company['industry_codes'][0]['description'] ?? '';
	}                             

	   
                                 
   
                                               
   
                                           
    
	private function isCompanyActive(array $company): bool {
		return ($company['current_status'] ?? 'Active') === 'Active';
	}                       

	   
                                                
   
                                               
   
                                     
    
	private function mapAddress(array $address): array {
		return [
			'street' => $address['street_address'] ?? '',
			'city' => $address['locality'] ?? '',
			'province' => $address['region'] ?? '',
			'postalCode' => $address['postal_code'] ?? '',
		];
	}                  
}           
