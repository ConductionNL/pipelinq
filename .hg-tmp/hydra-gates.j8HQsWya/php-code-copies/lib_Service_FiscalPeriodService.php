<?php

   
                                
  
                                                                              
                                                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;

   
                                         
  
                                                                            
                                                                               
  
                                                                                     
                                                
   
class FiscalPeriodService {
	   
                                                                  
   
                                                      
   
                                                 
   
                                                                                  
    
	public function periodIdFor(DateTimeInterface $date): string {
		$month = (int)$date->format('n');
		$quarter = (int)ceil($month / 3);
		$year = (int)$date->format('Y');

		return sprintf('Q%d-%d', $quarter, $year);
	}                   

	   
                                                   
   
                                                                            
   
                                         
                                                                                      
                                                 
    
	public function currentPeriodId(?DateTimeInterface $now = null): string {
		return $this->periodIdFor(date: $now ?? new DateTimeImmutable());
	}                       

	   
                                      
   
                                                        
   
                                                                               
   
                                                                
    
	public function periodEnd(string $periodId): ?DateTimeImmutable {
		if (preg_match('/^Q([1-4])-(\d{4})$/', $periodId, $matches) !== 1) {
			return null;
		}

		$quarter = (int)$matches[1];
		$year = (int)$matches[2];
		$endMonth = ($quarter * 3);
		$firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth));

		return $firstOfMonth->modify('last day of this month')->setTime(23, 59, 59);
	}                 

	   
                                                                      
   
                                          
                                                                            
   
                                                                   
   
                                                                                  
    
	public function daysRemaining(string $periodId, ?DateTimeInterface $now = null): int {
		$end = $this->periodEnd(periodId: $periodId);
		if ($end === null) {
			return 0;
		}

		$reference = $now ?? new DateTimeImmutable();
		$seconds = $end->getTimestamp() - $reference->getTimestamp();
		if ($seconds <= 0) {
			return 0;
		}

		return (int)floor($seconds / 86400);
	}                     

	   
                                                       
   
                                          
                                                                            
   
                                                         
   
                                                                                  
    
	public function isClosed(string $periodId, ?DateTimeInterface $now = null): bool {
		$end = $this->periodEnd(periodId: $periodId);
		if ($end === null) {
			return false;
		}

		$reference = $now ?? new DateTimeImmutable();
		return $reference->getTimestamp() > $end->getTimestamp();
	}                
}           
