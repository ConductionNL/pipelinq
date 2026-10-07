<?php

   
                                   
  
                                                                 
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
  
                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                               
  
                             
                              
                                    
                                         
                                
                                   
  
                                                                                                   
   
class ProspectScoringService {
	   
                                                 
   
                                             
                                            
   
                                                                    
   
                                                                                    
    
	public function score(array $prospect, array $criteria): array {
		$breakdown = [
			'sbiMatch' => 0,
			'employeeMatch' => 0,
			'locationMatch' => 0,
			'legalFormMatch' => 0,
			'activeMatch' => 0,
		];

		                              
		$breakdown['sbiMatch'] = $this->scoreSbi(
			sbiCode: $prospect['sbiCode'] ?? '',
			targetCodes: $criteria['sbiCodes'] ?? []
		);

		                                    
		$breakdown['employeeMatch'] = $this->scoreEmployeeCount(
			count: $prospect['employeeCount'] ?? null,
			min: $criteria['employeeCountMin'] ?? 0,
			max: $criteria['employeeCountMax'] ?? 0
		);

		                              
		$breakdown['locationMatch'] = $this->scoreLocation(
			province: $prospect['address']['province'] ?? '',
			targetProvinces: $criteria['provinces'] ?? []
		);

		                                
		$breakdown['legalFormMatch'] = $this->scoreLegalForm(
			legalForm: $prospect['legalForm'] ?? '',
			targetForms: $criteria['legalForms'] ?? []
		);

		                                   
		$isActive = ($prospect['isActive'] ?? false) === true;

		$breakdown['activeMatch'] = 0;
		if ($isActive === true) {
			$breakdown['activeMatch'] = 10;
		}

		$prospect['fitScore'] = array_sum(array: $breakdown);
		$prospect['fitBreakdown'] = $breakdown;

		return $prospect;
	}             

	   
                                                              
   
                                                   
                                            
   
                                                  
   
                                                                                    
    
	public function scoreAll(array $prospects, array $criteria): array {
		$scored = array_map(
			callback: fn (array $prospect): array => $this->score(
				prospect: $prospect,
				criteria: $criteria
			),
			array: $prospects
		);

		usort(
			array: $scored,
			callback: fn (array $a, array $b): int => ($b['fitScore'] ?? 0) <=> ($a['fitScore'] ?? 0)
		);

		return $scored;
	}                

	   
                         
   
                                                   
                                                   
   
                                        
    
	private function scoreSbi(string $sbiCode, array $targetCodes): int {
		if (count($targetCodes) === 0 || $sbiCode === '') {
			return 0;
		}

		foreach ($targetCodes as $target) {
			if (str_starts_with(haystack: $sbiCode, needle: (string)$target) === true) {
				return 30;
			}
		}

		return 0;
	}                

	   
                               
   
                                                         
                                              
                                              
   
                                             
    
	private function scoreEmployeeCount(?int $count, int $min, int $max): int {
		if ($count === null) {
			return 0;
		}

		if ($min === 0 && $max === 0) {
			return 0;
		}

		if ($max > 0 && $count >= $min && $count <= $max) {
			return 25;
		}

		if ($max === 0 && $count >= $min) {
			return 25;
		}

		return 0;
	}                          

	   
                         
   
                                                    
                                                       
   
                                             
    
	private function scoreLocation(string $province, array $targetProvinces): int {
		if (count($targetProvinces) === 0 || $province === '') {
			return 0;
		}

		$normalised = strtolower(string: trim(string: $province));
		foreach ($targetProvinces as $target) {
			if (strtolower(string: trim(string: (string)$target)) === $normalised) {
				return 20;
			}
		}

		return 0;
	}                     

	   
                           
   
                                                       
                                                     
   
                                               
    
	private function scoreLegalForm(string $legalForm, array $targetForms): int {
		if (count($targetForms) === 0 || $legalForm === '') {
			return 0;
		}

		$normalised = strtolower(string: trim(string: $legalForm));
		foreach ($targetForms as $target) {
			if (strtolower(string: trim(string: (string)$target)) === $normalised) {
				return 15;
			}
		}

		return 0;
	}                      
}           
