<?php

   
                                    
  
                                                                
                                                                   
                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

   
                                      
  
                        
                                   
                                                             
                                                            
                                                                        
  
                                                                       
                                                             
  
                                                                
  
                                                  
   
class BusinessHoursCalculator {
	public const CALENDAR_24X7 = '24x7';
	public const CALENDAR_BUSINESS = 'business-hours';
	public const CALENDAR_EXT_BUSINESS = 'extended-business-hours';

	   
                
   
                                                                   
                                                   
                                              
    
	public function __construct(
		private HolidayCalendarService $holidays,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                      
   
                                              
                                                      
                                                  
                                                         
   
                                                           
   
                                                                                                                     
   
                                                          
    
	public function addDuration(
		string $calendarType,
		DateTimeInterface $startTime,
		DateInterval $duration,
		string $holidayCalendar,
	): DateTimeImmutable {
		$start = DateTimeImmutable::createFromInterface($startTime);

		if ($calendarType === self::CALENDAR_24X7) {
			return $start->add($duration);
		}

		$minutes = $this->intervalToMinutes(duration: $duration);
		if ($minutes <= 0) {
			return $start;
		}

		return $this->advanceWithinWindow(
			calendarType: $calendarType,
			start: $start,
			minutesNeeded: $minutes,
			holidayCalendar: $holidayCalendar
		);
	}                   

	   
                                                      
   
                                           
   
                        
   
                                                          
    
	public function intervalToMinutes(DateInterval $duration): int {
		                                                                    
		$ref = new DateTimeImmutable('@0', new DateTimeZone('UTC'));
		$next = $ref->add($duration);
		return (int)floor(($next->getTimestamp() - $ref->getTimestamp()) / 60);
	}                         

	   
                                                 
   
                                                           
   
                                                          
    
	public function getBusinessHoursWindow(): array {
		$start = $this->appConfig->getValueString(Application::APP_ID, 'sla_business_hours_start', '09:00');
		$end = $this->appConfig->getValueString(Application::APP_ID, 'sla_business_hours_end', '17:00');
		if (preg_match('/^\d{2}:\d{2}$/', $start) !== 1) {
			$start = '09:00';
		}

		if (preg_match('/^\d{2}:\d{2}$/', $end) !== 1) {
			$end = '17:00';
		}

		return ['start' => $start, 'end' => $end];
	}                              

	   
                                                          
   
                                                            
                                                       
   
                                                  
                                              
                                                         
                                                                                 
   
                                      
   
                                                                                                                     
   
                                                          
    
	public function elapsedBusinessMinutes(
		DateTimeInterface $start,
		DateTimeInterface $end,
		string $holidayCalendar,
		string $calendarType = self::CALENDAR_BUSINESS,
	): int {
		if ($calendarType === self::CALENDAR_24X7) {
			return max(0, (int)floor(($end->getTimestamp() - $start->getTimestamp()) / 60));
		}

		if ($end <= $start) {
			return 0;
		}

		$cursor = DateTimeImmutable::createFromInterface($start);
		$endStamp = $end->getTimestamp();
		$total = 0;

		                                                             
		while ($cursor->getTimestamp() < $endStamp) {
			[$winStart, $winEnd] = $this->dayWindow(
				calendarType: $calendarType,
				day: $cursor,
				holidayCalendar: $holidayCalendar
			);
			if ($winStart === null || $winEnd === null) {
				$cursor = $cursor->modify('+1 day')->setTime(0, 0);
				continue;
			}

			$segmentStart = max($cursor->getTimestamp(), $winStart->getTimestamp());
			$segmentEnd = min($endStamp, $winEnd->getTimestamp());

			if ($segmentEnd > $segmentStart) {
				$total += (int)floor(($segmentEnd - $segmentStart) / 60);
			}

			$cursor = $cursor->modify('+1 day')->setTime(0, 0);
		}

		return $total;
	}                              

	   
                                                                   
                                   
   
                                              
                                                  
                                                      
                                                         
   
                                          
    
	private function advanceWithinWindow(
		string $calendarType,
		DateTimeImmutable $start,
		int $minutesNeeded,
		string $holidayCalendar,
	): DateTimeImmutable {
		$cursor = $start;
		                                                                  
		                                                
		$maxLoops = 365 * 2;

		while ($minutesNeeded > 0 && $maxLoops-- > 0) {
			[$winStart, $winEnd] = $this->dayWindow(
				calendarType: $calendarType,
				day: $cursor,
				holidayCalendar: $holidayCalendar
			);
			if ($winStart === null || $winEnd === null) {
				                                            
				$cursor = $cursor->modify('+1 day')->setTime(0, 0);
				continue;
			}

			                                            
			if ($cursor < $winStart) {
				$cursor = $winStart;
			}

			if ($cursor >= $winEnd) {
				                                         
				$cursor = $cursor->modify('+1 day')->setTime(0, 0);
				continue;
			}

			$availMinutes = (int)floor(($winEnd->getTimestamp() - $cursor->getTimestamp()) / 60);
			if ($availMinutes >= $minutesNeeded) {
				$advanced = $cursor->modify('+' . $minutesNeeded . ' minutes');
				assert($advanced !== false);
				return $advanced;
			}

			$minutesNeeded -= $availMinutes;
			$cursor = $winEnd;
		}           

		if ($maxLoops <= 0) {
			$this->logger->error(
				'BusinessHoursCalculator: hit safety bound advancing deadline; calendar likely misconfigured',
				['calendarType' => $calendarType, 'holidayCalendar' => $holidayCalendar]
			);
		}

		return $cursor;
	}                           

	   
                                                               
   
                                                                
                                                              
             
   
                                              
                                             
                                                         
   
                                                                                  
    
	private function dayWindow(
		string $calendarType,
		DateTimeImmutable $day,
		string $holidayCalendar,
	): array {
		$dow = (int)$day->format('N');
		                
		if ($this->holidays->isHoliday($holidayCalendar, $day) === true) {
			return [null, null];
		}

		$win = $this->getBusinessHoursWindow();
		if ($calendarType === self::CALENDAR_BUSINESS) {
			if ($dow >= 6) {
				return [null, null];
			}

			return [
				$this->withTime(day: $day, time: $win['start']),
				$this->withTime(day: $day, time: $win['end']),
			];
		}

		if ($calendarType === self::CALENDAR_EXT_BUSINESS) {
			if ($dow <= 5) {
				return [
					$this->withTime(day: $day, time: '08:00'),
					$this->withTime(day: $day, time: '18:00'),
				];
			}

			if ($dow === 6) {
				return [
					$this->withTime(day: $day, time: '09:00'),
					$this->withTime(day: $day, time: '13:00'),
				];
			}

			return [null, null];
		}

		                                                               
		if ($dow >= 6) {
			return [null, null];
		}

		return [
			$this->withTime(day: $day, time: $win['start']),
			$this->withTime(day: $day, time: $win['end']),
		];
	}                 

	   
                                                                        
   
                                             
                                     
   
                                          
    
	private function withTime(DateTimeImmutable $day, string $time): DateTimeImmutable {
		[$hours, $minutes] = array_map('intval', explode(':', $time));
		return $day->setTime($hours, $minutes, 0);
	}                
}           
