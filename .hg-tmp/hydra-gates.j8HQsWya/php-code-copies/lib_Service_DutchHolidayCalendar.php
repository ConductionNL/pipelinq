<?php

   
                                 
  
                                                                      
                                                                 
                                                                     
                                                                     
         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

   
                                                                 
  
                                                                                                       
   
class DutchHolidayCalendar {
	   
                                                      
   
                              
    
	private const FIXED_HOLIDAYS = [
		'01-01' => 'Nieuwjaarsdag',
		'04-27' => 'Koningsdag',
		'12-25' => 'Eerste Kerstdag',
		'12-26' => 'Tweede Kerstdag',
	];

	   
                                                     
   
                           
    
	private array $extraHolidays = [];

	   
                
   
                                                                       
                                                                       
                                                     
    
	public function __construct(array $extraHolidays = []) {
		foreach ($extraHolidays as $extra) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $extra) !== 1) {
				throw new InvalidArgumentException(
					'extraHolidays entries must be ISO Y-m-d strings.'
				);
			}

			$this->extraHolidays[] = $extra;
		}
	}                   

	   
                                                           
   
                                                                
                                                               
                                                                
                                                  
   
                                                           
                                                         
                                                                    
   
                                                                           
                                                                                           
    
	public function addWorkingDays(
		DateTimeInterface $from,
		int $days,
		string $tenantTimeZone = 'Europe/Amsterdam',
	): DateTimeImmutable {
		if ($days < 0) {
			throw new InvalidArgumentException('days MUST be >= 0.');
		}

		$tz = new DateTimeZone($tenantTimeZone);
		$current = DateTimeImmutable::createFromInterface($from)
			->setTimezone($tz);
		                                                               
		                                        
		$current = $current->setTime(0, 0, 0);

		$added = 0;
		while ($added < $days) {
			$current = $current->modify('+1 day');
			if ($this->isWorkingDay(date: $current) === true) {
				$added++;
			}
		}

		return $current;
	}                      

	   
                                                     
   
                                                       
   
                                                  
                                                                                           
    
	public function isWorkingDay(DateTimeInterface $date): bool {
		                                            
		$weekday = (int)$date->format('N');
		if ($weekday === 6 || $weekday === 7) {
			return false;
		}

		return $this->isHoliday(date: $date) === false;
	}                    

	   
                                                           
   
                                                       
   
                                              
   
                                                                
    
	public function isHoliday(DateTimeInterface $date): bool {
		$monthDay = $date->format('m-d');
		$ymd = $date->format('Y-m-d');
		$year = (int)$date->format('Y');

		if (isset(self::FIXED_HOLIDAYS[$monthDay]) === true) {
			return true;
		}

		if ($monthDay === '05-05' && ($year % 5) === 0) {
			                                                            
			return true;
		}

		if (in_array($ymd, $this->extraHolidays, true) === true) {
			return true;
		}

		$variable = $this->variableHolidaysForYear(year: $year);
		return in_array($ymd, $variable, true);
	}                 

	   
                                                                    
   
                                        
   
                                                              
    
	private function variableHolidaysForYear(int $year): array {
		                                                                              
		                                                                
		                                               
		$easterOffset = $this->easterOffset(year: $year);
		$easter = (new DateTimeImmutable($year . '-03-21'))
			->modify('+' . $easterOffset . ' days');

		$goedeVrijdag = $easter->modify('-2 days');
		$tweedePaasdag = $easter->modify('+1 day');
		$hemelvaartsdag = $easter->modify('+39 days');
		$pinksteren = $easter->modify('+49 days');
		$tweedePinksterdag = $easter->modify('+50 days');

		return [
			$goedeVrijdag->format('Y-m-d'),
			$easter->format('Y-m-d'),
			$tweedePaasdag->format('Y-m-d'),
			$hemelvaartsdag->format('Y-m-d'),
			$pinksteren->format('Y-m-d'),
			$tweedePinksterdag->format('Y-m-d'),
		];
	}                               

	   
                                                                                   
   
                                    
   
                                            
   
                                                                                                                       
    
	private function easterOffset(int $year): int {
		$a = ($year % 19);
		$b = intdiv($year, 100);
		$c = ($year % 100);
		$d = intdiv($b, 4);
		$e = ($b % 4);
		$f = intdiv(($b + 8), 25);
		$g = intdiv(($b - $f + 1), 3);
		$h = ((19 * $a) + $b - $d - $g + 15) % 30;
		$i = intdiv($c, 4);
		$k = ($c % 4);
		$l = (32 + (2 * $e) + (2 * $i) - $h - $k) % 7;
		$m = intdiv(($a + (11 * $h) + (22 * $l)), 451);
		$month = intdiv(($h + $l - (7 * $m) + 114), 31);
		$day = ((($h + $l - (7 * $m) + 114) % 31) + 1);

		$easter = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
		$anchor = new DateTimeImmutable(sprintf('%04d-03-21', $year));
		return (int)$anchor->diff($easter)->days;
	}                    
}           
