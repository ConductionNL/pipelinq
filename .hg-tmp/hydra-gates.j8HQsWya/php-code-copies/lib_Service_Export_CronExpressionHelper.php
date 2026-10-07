<?php

   
                                 
  
                                                                             
                                                                              
                                                                                
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                    
                                               
  
                         
  
                                                 
  
                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

   
                                                                         
  
                                                                                 
                                                                         
                                                        
                                                                               
                                                                       
                                                                    
                                                                                         
                                                                                
  
                                                                                
   
class CronExpressionHelper {
	   
                                                              
   
                              
    
	private const SHORTCUTS = [
		'@hourly' => '0 * * * *',
		'@daily' => '0 0 * * *',
		'@weekly' => '0 0 * * 0',
		'@monthly' => '0 0 1 * *',
		'@yearly' => '0 0 1 1 *',
	];

	   
                                                                          
   
                                          
    
	private const BOUNDS = [
		[0, 59],
		[0, 23],
		[1, 31],
		[1, 12],
		[0, 7],
	];

	   
                                                                             
   
                                                  
   
                                 
   
                                                                                 
    
	public function isValid(string $expression): bool {
		$expression = trim($expression);
		if ($expression === '') {
			return false;
		}

		if (isset(self::SHORTCUTS[strtolower($expression)]) === true) {
			return true;
		}

		$fields = preg_split('/\s+/', $expression);
		if ($fields === false || count($fields) !== 5) {
			return false;
		}

		foreach ($fields as $index => $field) {
			if ($this->isFieldValid(field: $field, bounds: self::BOUNDS[$index]) === false) {
				return false;
			}
		}

		return true;
	}               

	   
                                                                               
   
                                                  
   
                                                                             
                                                                                                                                   
    
	public function normalize(string $expression): string {
		$expression = trim($expression);
		$lower = strtolower($expression);
		if (isset(self::SHORTCUTS[$lower]) === true) {
			return self::SHORTCUTS[$lower];
		}

		return $expression;
	}                 

	   
                                                              
   
                                                                            
                                                                            
   
                                                  
                                                             
   
                                                  
   
                                                                                 
    
	public function describe(string $expression, string $locale = 'en'): string {
		if ($this->isValid(expression: $expression) === false) {
			return '';
		}

		$isDutch = ($locale === 'nl');
		$normalized = $this->normalize(expression: $expression);
		$fields = preg_split('/\s+/', $normalized);
		if ($fields === false || count($fields) !== 5) {
			return $normalized;
		}

		[$minute, $hour, $dom, $month, $dow] = $fields;

		                           
		if ($minute === '0' && $hour === '*' && $dom === '*' && $month === '*' && $dow === '*') {
			if ($isDutch === true) {
				return 'Elk uur';
			}

			return 'Every hour';
		}

		                                               
		if (ctype_digit($minute) === true && ctype_digit($hour) === true
			&& $dom === '*' && $month === '*' && $dow === '*'
		) {
			$time = sprintf('%02d:%02d', (int)$hour, (int)$minute);
			if ($isDutch === true) {
				return "Elke dag om {$time}";
			}

			return "Every day at {$time}";
		}

		                                 
		if (ctype_digit($minute) === true && ctype_digit($hour) === true
			&& $dom === '*' && $month === '*' && ($dow === '0' || $dow === '7')
		) {
			$time = sprintf('%02d:%02d', (int)$hour, (int)$minute);
			if ($isDutch === true) {
				return "Elke zondag om {$time}";
			}

			return "Every Sunday at {$time}";
		}

		return $normalized;
	}                

	   
                                                     
   
                                                                         
                                                                           
                                                                
   
                                                  
                                                        
   
                                                             
   
                                                                                    
    
	public function isDue(string $expression, \DateTimeInterface $when): bool {
		if ($this->isValid(expression: $expression) === false) {
			return false;
		}

		$fields = preg_split('/\s+/', $this->normalize(expression: $expression));
		if ($fields === false || count($fields) !== 5) {
			return false;
		}

		[$minute, $hour, $dom, $month, $dow] = $fields;

		$minuteOk = $this->fieldMatches(field: $minute, value: (int)$when->format('i'), bounds: self::BOUNDS[0]);
		$hourOk = $this->fieldMatches(field: $hour, value: (int)$when->format('G'), bounds: self::BOUNDS[1]);
		$monthOk = $this->fieldMatches(field: $month, value: (int)$when->format('n'), bounds: self::BOUNDS[3]);
		if ($minuteOk === false || $hourOk === false || $monthOk === false) {
			return false;
		}

		$domValue = (int)$when->format('j');
		$dowValue = (int)$when->format('w');
		$domR = ($dom !== '*');
		$dowR = ($dow !== '*');
		$domOk = $this->fieldMatches(field: $dom, value: $domValue, bounds: self::BOUNDS[2]);
		$dowOk = $this->dowMatches(field: $dow, value: $dowValue);

		if ($domR === true && $dowR === true) {
			return ($domOk === true || $dowOk === true);
		}

		return ($domOk === true && $dowOk === true);
	}             

	   
                                                
   
                                        
                                        
                                                              
   
                                             
    
	private function fieldMatches(string $field, int $value, array $bounds): bool {
		if ($field === '*') {
			return true;
		}

		foreach (explode(',', $field) as $part) {
			if ($this->partMatches(part: $part, value: $value, bounds: $bounds) === true) {
				return true;
			}
		}

		return false;
	}                    

	   
                                                      
   
                                       
                                                     
   
                                           
    
	private function dowMatches(string $field, int $value): bool {
		if ($this->fieldMatches(field: $field, value: $value, bounds: self::BOUNDS[4]) === true) {
			return true;
		}

		                                  
		if ($value === 0) {
			return $this->fieldMatches(field: $field, value: 7, bounds: self::BOUNDS[4]);
		}

		return false;
	}                  

	   
                                                                          
   
                                       
                                        
                                                              
   
                                             
    
	private function partMatches(string $part, int $value, array $bounds): bool {
		$step = 1;
		if (str_contains($part, '/') === true) {
			[$range, $stepText] = explode('/', $part, 2);
			if (ctype_digit($stepText) === false || (int)$stepText < 1) {
				return false;
			}

			$step = (int)$stepText;
			$part = $range;
		}

		$low = $bounds[0];
		$high = $bounds[1];
		if ($part !== '*') {
			if (str_contains($part, '-') === true) {
				[$lowText, $highText] = explode('-', $part, 2);
				if (ctype_digit($lowText) === false || ctype_digit($highText) === false) {
					return false;
				}

				$low = (int)$lowText;
				$high = (int)$highText;
			} else {
				if (ctype_digit($part) === false) {
					return false;
				}

				$low = (int)$part;
				$high = (int)$part;
			}
		}

		if ($value < $low || $value > $high) {
			return false;
		}

		return ((($value - $low) % $step) === 0);
	}                   

	   
                                                     
   
                                                                            
                           
   
                                        
                                                                         
   
                                              
    
	private function isFieldValid(string $field, array $bounds): bool {
		if ($field === '*') {
			return true;
		}

		foreach (explode(',', $field) as $part) {
			if ($this->isFieldPartValid(part: $part, bounds: $bounds) === false) {
				return false;
			}
		}

		return true;
	}                    

	   
                                                      
   
                                       
                                                              
   
                                 
    
	private function isFieldPartValid(string $part, array $bounds): bool {
		$step = 1;
		if (str_contains($part, '/') === true) {
			[$range, $stepText] = explode('/', $part, 2);
			if (ctype_digit($stepText) === false || (int)$stepText < 1) {
				return false;
			}

			$step = (int)$stepText;
			$part = $range;
		}

		if ($part === '*') {
			return $step >= 1;
		}

		if (str_contains($part, '-') === true) {
			[$lowText, $highText] = explode('-', $part, 2);
			if (ctype_digit($lowText) === false || ctype_digit($highText) === false) {
				return false;
			}

			$low = (int)$lowText;
			$high = (int)$highText;
			return $low >= $bounds[0] && $high <= $bounds[1] && $low <= $high;
		}

		if (ctype_digit($part) === false) {
			return false;
		}

		$value = (int)$part;
		return $value >= $bounds[0] && $value <= $bounds[1];
	}                        
}           
