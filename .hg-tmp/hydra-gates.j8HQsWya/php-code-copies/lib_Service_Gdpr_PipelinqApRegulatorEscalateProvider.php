<?php

   
                                                
  
                                                                      
                                                                 
                                                             
                                                                          
                                                                        
                                                                  
  
                                                                        
                                                                             
                                                                         
                                                                              
                                                                            
                                                                          
                                      
  
                    
                                      
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                    
                                    
  
                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateProvider;
use OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateResult;
use Psr\Log\LoggerInterface;

   
                                                                                
  
                                                                              
                                                                            
                                                                              
                                        
  
                                                                                    
   
class PipelinqApRegulatorEscalateProvider implements RegulatorEscalateProvider {
	   
                                                                        
                                                                 
   
               
    
	public const PROVIDER_ID = 'pipelinq-ap-complaint';

	   
                                                                      
   
               
    
	public const AP_COMPLAINT_URL = 'https://autoriteitpersoonsgegevens.nl/nl/zelf-doen/gebruik-uw-privacyrechten/klacht-melden-bij-de-ap';

	   
                                                                  
   
                           
    
	private const REFERENCE_KEYS = [
		'kenmerk',
		'reference',
		'caseNumber',
		'dossierNummer',
	];

	   
                
   
                                              
    
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                             
   
                  
   
                                                                                     
    
	public function getProviderId(): string {
		return self::PROVIDER_ID;
	}                     

	   
                                                                 
   
                                                                             
                                                                              
                                                                           
   
                                                      
                                                                    
   
                                                                                
   
                                                                                 
                                                                
   
                                                                                     
    
	public function escalate(string $caseUuid, array $case): RegulatorEscalateResult {
		$kenmerk = $this->extractReference(case: $case);
		if ($kenmerk === null) {
			$kenmerk = trim($caseUuid);
		}

		if ($kenmerk === '') {
			return RegulatorEscalateResult::refused(
				providerId: self::PROVIDER_ID,
				message: 'Case carries no kenmerk or uuid; AP-complaint escalation not performed (fail-closed).'
			);
		}

		$reference = 'AP-KLACHT/' . $kenmerk;
		$this->logger->info(
			'Pipelinq regulator escalate: AP-complaint reference issued',
			['case' => $caseUuid, 'reference' => $reference]
		);

		return RegulatorEscalateResult::escalated(
			providerId: self::PROVIDER_ID,
			reference: $reference,
			message: 'AP-complaint dossier prepared. Klacht melden bij de AP via ' . self::AP_COMPLAINT_URL
		);
	}                

	   
                                                         
   
                                                                    
   
                                                                    
    
	private function extractReference(array $case): ?string {
		foreach (self::REFERENCE_KEYS as $key) {
			$value = ($case[$key] ?? null);
			if (is_string($value) === false && is_int($value) === false) {
				continue;
			}

			$candidate = trim((string)$value);
			if ($candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}                        
}           
