<?php

   
                            
  
                                                                          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

   
                           
  
                                                                           
                                                                                
                                                                             
                                                                              
  
                                                                             
                                                             
  
                                                                 
   
class PhoneNormaliser {
	   
                                                             
   
                                                                        
                                                  
   
                             
    
	private const COUNTRY_PREFIX = [
		'NL' => '31',
		'BE' => '32',
		'DE' => '49',
		'FR' => '33',
		'GB' => '44',
		'US' => '1',
		'CA' => '1',
		'LU' => '352',
		'ES' => '34',
		'IT' => '39',
		'PT' => '351',
	];

	   
                
   
                                                
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                          
   
                                                       
                                                          
   
                                                                            
                                                                        
                                                
   
                                                                
                                                                                                     
   
                                                 
   
                                                                                 
                                                                                 
                                             
                                                                  
    
	public function normaliseForOrg(string $rawNumber, ?string $orgId = null): array {
		$raw = trim($rawNumber);
		if ($raw === '') {
			return ['e164' => null, 'raw' => $rawNumber];
		}

		$country = $this->resolveDefaultCountry();

		                                                           
		$hasPlus = ($raw[0] === '+');
		$digits = preg_replace('/[^0-9]/', '', $raw);
		if ($digits === null || $digits === '') {
			$this->logFailure(rawNumber: $rawNumber, reason: 'no digits in input');
			return ['e164' => null, 'raw' => $rawNumber];
		}

		                                       
		if ($hasPlus === true) {
			$e164 = '+' . $digits;
			return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
		}

		                                                               
		if (str_starts_with($digits, '00') === true) {
			$e164 = '+' . substr($digits, 2);
			return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
		}

		                                                               
		if (str_starts_with($digits, '0') === true) {
			$countryCode = (self::COUNTRY_PREFIX[$country] ?? '31');
			$e164 = '+' . $countryCode . substr($digits, 1);
			return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
		}

		                                                                       
		$countryCode = (self::COUNTRY_PREFIX[$country] ?? '31');
		$e164 = '+' . $countryCode . $digits;
		return ['e164' => $this->validate(candidate: $e164, rawNumber: $rawNumber), 'raw' => $rawNumber];
	}                       

	   
                                                                         
   
                                                                       
                                                                         
                                                                         
                                                                      
                                                                   
   
                                                            
   
                                                                      
   
                                                                                                       
    
	public function normaliseInbound(string $rawNumber): string {
		$raw = trim($rawNumber);
		if ($raw === '') {
			return '';
		}

		$candidate = $raw;
		if (preg_match('/^[1-9][0-9 ]*$/', $raw) === 1) {
			$candidate = '+' . $raw;
		}

		return ($this->normaliseForOrg(rawNumber: $candidate)['e164'] ?? $raw);
	}                        

	   
                                                                
   
                                                         
    
	private function resolveDefaultCountry(): string {
		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'default_country_code',
			'NL'
		);

		$configured = strtoupper(trim($configured));
		if ($configured === '' || isset(self::COUNTRY_PREFIX[$configured]) === false) {
			return 'NL';
		}

		return $configured;
	}                             

	   
                                                   
   
                                                        
   
                                                        
                                                                   
   
                                                                           
    
	private function validate(string $candidate, string $rawNumber): ?string {
		if (preg_match('/^\+\d{8,15}$/', $candidate) !== 1) {
			$this->logFailure(rawNumber: $rawNumber, reason: 'E.164 candidate failed length/format check');
			return null;
		}

		return $candidate;
	}                

	   
                                
   
                                                     
                                                     
   
                
    
	private function logFailure(string $rawNumber, string $reason): void {
		$this->logger->warning(
			'CTI phone-number normalisation failed',
			[
				'rawNumber' => $rawNumber,
				'reason' => $reason,
			]
		);
	}                  
}           
