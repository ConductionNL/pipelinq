<?php

   
                        
  
                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                           
                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTime;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

   
                                                    
  
                                                                           
  
                                                    
   
class TaskService {
	   
                     
   
                      
    
	public const VALID_TYPES = [
		'callbackRequest',
		'followUpTask',
		'informationRequest',
	];

	   
                        
   
                      
    
	public const VALID_STATUSES = [
		'open',
		'in_progress',
		'completed',
		'expired',
	];

	   
                          
   
                      
    
	public const VALID_PRIORITIES = [
		'high',
		'normal',
		'low',
	];

	   
                                                   
   
            
    
	private const DEFAULT_BUSINESS_HOUR_START = 8;

	   
                                                 
   
            
    
	private const DEFAULT_BUSINESS_HOUR_END = 17;

	   
                
   
                                                      
                                                
                                              
    
	public function __construct(
		private IUserSession $userSession,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                             
   
                                                                          
                                                                       
   
                                               
    
	private function getBusinessHourStart(): int {
		return $this->appConfig->getValueInt(
			Application::APP_ID,
			'task.business_hour_start',
			self::DEFAULT_BUSINESS_HOUR_START
		);
	}                            

	   
                                                            
   
                                                                            
                                                                 
   
                                             
    
	private function getBusinessHourEnd(): int {
		return $this->appConfig->getValueInt(
			Application::APP_ID,
			'task.business_hour_end',
			self::DEFAULT_BUSINESS_HOUR_END
		);
	}                          

	   
                                                                
   
                                            
                                                                        
    
	public function getDefaultDeadline(): string {
		$now = new DateTime();
		$deadline = clone $now;

		                             
		$deadline->modify('+1 day');
		while ($this->isWeekend(date: $deadline) === true) {
			$deadline->modify('+1 day');
		}

		$deadline->setTime($this->getBusinessHourEnd(), 0, 0);

		return $deadline->format(DateTime::ATOM);
	}                          

	   
                                                   
   
                                                                           
                               
   
                                                         
                                                              
   
                                                     
                                                                        
    
	public function calculateDeadline(string $createdAt, int $businessHours): string {
		$start = new DateTime($createdAt);
		$remaining = $businessHours;
		$businessStart = $this->getBusinessHourStart();
		$businessEnd = $this->getBusinessHourEnd();

		while ($remaining > 0) {
			$start->modify('+1 hour');

			if ($this->isWeekend(date: $start) === true) {
				continue;
			}

			$hour = (int)$start->format('G');
			if ($hour >= $businessStart && $hour < $businessEnd) {
				$remaining--;
			}
		}

		return $start->format(DateTime::ATOM);
	}                         

	   
                                    
   
                                            
   
                                                        
    
	private function isWeekend(DateTime $date): bool {
		$dayOfWeek = (int)$date->format('N');

		return $dayOfWeek >= 6;
	}                 
}           
