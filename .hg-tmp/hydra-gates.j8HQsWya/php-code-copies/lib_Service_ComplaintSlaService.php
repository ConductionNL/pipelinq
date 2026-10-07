<?php

   
                                
  
                                                                 
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

   
                                                                        
  
                                                                    
                                                         
  
                                                                                          
   
class ComplaintSlaService {
	   
                                                               
   
                      
    
	public const VALID_CATEGORIES = [
		'service',
		'product',
		'communication',
		'billing',
		'other',
	];

	   
                
   
                                                               
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                          
   
                                                             
                                                    
   
                                                   
   
                                                      
                                                                                   
    
	public function getSlaHoursForCategory(string $category): int {
		if (in_array($category, self::VALID_CATEGORIES, true) === false) {
			$this->logger->warning(
				'ComplaintSlaService: Unknown category "{category}"',
				['category' => $category],
			);
			return 0;
		}

		$key = 'complaint_sla_' . $category;
		$value = $this->appConfig->getValueString(
			Application::APP_ID,
			$key,
			'',
		);

		if ($value === '' || is_numeric($value) === false) {
			return 0;
		}

		return (int)$value;
	}                              

	   
                                                                     
   
                                                                
   
                                                   
                                                                             
   
                                                                              
                                                                                   
    
	public function calculateDeadline(
		string $category,
		?DateTimeInterface $from = null,
	): ?DateTimeImmutable {
		$hours = $this->getSlaHoursForCategory(category: $category);

		if ($hours <= 0) {
			return null;
		}

		$start = new DateTimeImmutable();
		if ($from !== null) {
			$start = new DateTimeImmutable($from->format('Y-m-d\TH:i:sP'));
		}

		                                                                              
		$result = $start->modify('+' . $hours . ' hours');
		assert($result !== false);

		return $result;
	}                         

	   
                                             
   
                                                                      
                                                    
   
                                                                      
                                                                            
   
                                                    
                                                                                   
    
	public function isOverdue(array $complaint, ?DateTimeInterface $now = null): bool {
		$deadline = $complaint['slaDeadline'] ?? null;

		if ($deadline === null || $deadline === '') {
			return false;
		}

		$status = $complaint['status'] ?? '';

		if ($this->isOpenStatus(status: $status) === false) {
			return false;
		}

		try {
			$deadlineDate = new DateTimeImmutable((string)$deadline);
		} catch (Exception $e) {
			return false;
		}

		$reference = $now ?? new DateTimeImmutable();

		return $deadlineDate < $reference;
	}                 

	   
                                                                           
   
                                                     
   
                                              
                                                                                   
    
	public function isOpenStatus(string $status): bool {
		return in_array($status, ['new', 'in_progress'], true);
	}                    
}           
