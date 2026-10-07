<?php

   
                                 
  
                                                                                                
                                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                           
  
                                                                                      
  
                                                                          
   
class BsnValidationService {
	   
                                                                  
    
	public const ERROR_LENGTH = 'length';

	   
                                                        
    
	public const ERROR_CHECKSUM = 'checksum';

	   
                                               
   
                                      
                            
                                            
                                                                        
                                             
                                                                                    
   
                                                                             
                                                                                       
   
                                                                       
   
                                                                                                                          
   
                                                                              
                                                                              
                                                                              
    
	public function validate(string $bsnInput): array {
		                                                                          
		if (strlen($bsnInput) !== 9 || ctype_digit($bsnInput) === false) {
			return [
				'isFormalValid' => false,
				'elevenTestScore' => -1,
				'errorCode' => self::ERROR_LENGTH,
				'errorMessage' => 'Een BSN bestaat uit exact 9 cijfers.',
				'maskedBsn' => self::mask(bsnInput: $bsnInput),
			];
		}

		                                                                                      
		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += ((int)$bsnInput[$i]) * (9 - $i);
		}

		                             
		$sum -= (int)$bsnInput[8];

		$modulo = $sum % 11;
		$isValid = ($modulo === 0);

		$errorCode = null;
		$errorMessage = null;
		if ($isValid === false) {
			$errorCode = self::ERROR_CHECKSUM;
			$errorMessage = 'Dit BSN voldoet niet aan de 11-proef (controlesom fout).';
		}

		return [
			'isFormalValid' => $isValid,
			'elevenTestScore' => $modulo,
			'errorCode' => $errorCode,
			'errorMessage' => $errorMessage,
			'maskedBsn' => self::mask(bsnInput: $bsnInput),
		];
	}                

	   
                                                                    
   
                                                                                         
                                                         
   
                                                                        
   
                                                                            
   
                                                                              
    
	public static function mask(string $bsnInput): string {
		$length = strlen($bsnInput);
		if ($length === 0) {
			return '';
		}

		if ($length < 5) {
			return str_repeat('*', $length);
		}

		                                                           
		$reveal = substr($bsnInput, 3, 4);
		return '***' . $reveal . '*';
	}            

	   
                                                   
   
                                                                                       
                                                                           
   
                                                                                         
   
                                    
   
                                                   
   
                                                                              
    
	public static function hash(string $bsnInput): string {
		return hash('sha256', $bsnInput);
	}            
}           
