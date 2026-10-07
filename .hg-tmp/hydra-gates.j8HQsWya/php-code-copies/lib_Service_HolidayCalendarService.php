<?php

   
                                   
  
                                                                         
                                                                          
                                                                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                         
                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

   
                                                                      
  
                                                                         
                                                                       
                                                                         
                                                  
                                                                      
                                                    
  
                                                                
  
                                                  
   
class HolidayCalendarService {

	   
                                         
   
                                            
    
	private array $cache = [];

	   
                                                                    
   
                                           
    
	private array $yearCache = [];

	   
                
   
                                                            
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                       
   
                                                                  
                                                                    
                                                
   
                                                                                     
   
                                                                
   
                                                          
    
	public function loadCalendar(string $calendarName): array {
		if ($calendarName === '' || $calendarName === 'none') {
			return ['name' => 'none', 'rules' => [], 'computed' => []];
		}

		if (isset($this->cache[$calendarName]) === true) {
			return $this->cache[$calendarName];
		}

		$path = __DIR__ . '/../Resources/holidays/nl/' . $calendarName . '.json';
		if (file_exists($path) === false) {
			$this->logger->warning(
				'HolidayCalendarService: calendar file not found, falling back to empty',
				['calendar' => $calendarName, 'path' => $path]
			);
			$this->cache[$calendarName] = ['name' => $calendarName, 'rules' => [], 'computed' => []];
			return $this->cache[$calendarName];
		}

		try {
			$raw = file_get_contents($path);
			if ($raw === false) {
				$raw = '{}';
			}

			$data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
			if (is_array($data) === false) {
				throw new RuntimeException('calendar root is not an object');
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'HolidayCalendarService: failed to parse calendar JSON',
				['calendar' => $calendarName, 'error' => $e->getMessage()]
			);
			$this->cache[$calendarName] = ['name' => $calendarName, 'rules' => [], 'computed' => []];
			return $this->cache[$calendarName];
		}

		$this->cache[$calendarName] = $data;
		return $data;
	}                    

	   
                                                                
   
                                                                    
   
                                                                
   
                                                          
    
	public function compositeCalendar(array $calendarNames): array {
		$rules = [];
		$computed = [];
		foreach ($calendarNames as $name) {
			$cal = $this->loadCalendar(calendarName: $name);
			$rules = array_merge($rules, $cal['rules'] ?? []);
			$computed = array_merge($computed, $cal['computed'] ?? []);
		}

		return ['name' => implode(',', $calendarNames), 'rules' => $rules, 'computed' => $computed];
	}                         

	   
                                                                    
   
                                                               
                                                       
   
                                                                  
                                                     
   
                                                         
   
                                                          
    
	public function isHoliday(string $calendarName, DateTimeInterface $date): bool {
		if ($calendarName === '' || $calendarName === 'none') {
			return false;
		}

		$year = (int)$date->format('Y');
		$cache = $this->resolveYearSet(calendarName: $calendarName, year: $year);

		$key = $date->format('Y-m-d');
		return isset($cache[$key]);
	}                 

	   
                                                                       
   
                                                                                
                                       
   
                                                         
   
                                                                                                                                        
                                                                                                                                        
    
	private function resolveYearSet(string $calendarName, int $year): array {
		$cacheKey = $calendarName . ':' . $year;
		if (isset($this->yearCache[$cacheKey]) === true) {
			return $this->yearCache[$cacheKey];
		}

		$names = array_filter(array_map('trim', explode(',', $calendarName)));
		$cal = $this->loadCalendar(calendarName: ($names[0] ?? ''));
		if (count($names) > 1) {
			$cal = $this->compositeCalendar(calendarNames: $names);
		}

		$set = [];
		foreach (($cal['rules'] ?? []) as $rule) {
			if (isset($rule['date']) === false) {
				continue;
			}

			                                                                        
			if (($rule['lustrum'] ?? false) === true && ($year % 5) !== 0) {
				if ($this->lustrumOverrideEnabled(calendarName: $calendarName) === false) {
					continue;
				}
			}

			$key = $year . '-' . $rule['date'];
			$set[$key] = true;
		}

		foreach (($cal['computed'] ?? []) as $computed) {
			$offset = (int)($computed['easterOffset'] ?? 0);
			$easter = $this->easterDate(year: $year);
			$sign = '';
			if ($offset >= 0) {
				$sign = '+';
			}

			$date = $easter->modify($sign . $offset . ' days');
			$set[$date->format('Y-m-d')] = true;
		}

		                                                                                                    
		$overrides = $this->loadTenantOverrides();
		foreach ($names as $singleName) {
			foreach (($overrides[$singleName] ?? []) as $entry) {
				if (isset($entry['date']) === false) {
					continue;
				}

				$raw = (string)$entry['date'];
				$key = $raw;
				if (strlen($raw) === 5) {
					$key = $year . '-' . $raw;
				}

				$set[$key] = true;
			}
		}

		$this->yearCache[$cacheKey] = $set;
		return $set;
	}                      

	   
                                                             
   
                                                  
   
                                                              
    
	private function lustrumOverrideEnabled(string $calendarName): bool {
		unset($calendarName);
		$value = $this->appConfig->getValueString(Application::APP_ID, 'sla_bevrijdingsdag_yearly', 'false');
		return $value === 'true';
	}                              

	   
                                                        
   
                                                                                      
    
	private function loadTenantOverrides(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'sla_tenant_holiday_overrides', '');
		if ($raw === '') {
			return [];
		}

		try {
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
			if (is_array($decoded) === false) {
				return [];
			}

			return $decoded;
		} catch (Throwable $e) {
			$this->logger->warning(
				'HolidayCalendarService: tenant overrides JSON malformed, ignoring',
				['error' => $e->getMessage()]
			);
			return [];
		}
	}                           

	   
                                                                  
                                                        
   
                                                                      
                                                                 
                                               
   
                                        
   
                                                   
   
                                                                                                                       
   
                                                          
    
	public function easterDate(int $year): DateTimeImmutable {
		$a = $year % 19;
		$b = intdiv($year, 100);
		$c = $year % 100;
		$d = intdiv($b, 4);
		$e = $b % 4;
		$f = intdiv(($b + 8), 25);
		$g = intdiv(($b - $f + 1), 3);
		$h = ((19 * $a) + $b - $d - $g + 15) % 30;
		$i = intdiv($c, 4);
		$k = $c % 4;
		$l = (32 + (2 * $e) + (2 * $i) - $h - $k) % 7;
		$m = intdiv(($a + (11 * $h) + (22 * $l)), 451);
		$month = intdiv(($h + $l - (7 * $m) + 114), 31);
		$day = (($h + $l - (7 * $m) + 114) % 31) + 1;

		return new DateTimeImmutable(
			sprintf('%04d-%02d-%02d', $year, $month, $day),
			new DateTimeZone('UTC')
		);
	}                  
}           
