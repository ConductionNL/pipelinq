<?php

   
                                              
  
                                                                             
                                                                                 
                                                                                 
                                                                        
                                                          
  
                                                                          
                                                                             
                                                                                
                                                                              
                                                                           
                                                                             
                                  
  
                    
                                      
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                    
                                    
  
                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyProvider;
use OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyResult;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use Psr\Log\LoggerInterface;

   
                                                                        
  
                                                                              
                                                                            
                                                                              
                                        
  
                                                                                    
   
class PipelinqBsnIdentityVerifyProvider implements IdentityVerifyProvider {
	   
                                                                        
                                                              
   
               
    
	public const PROVIDER_ID = 'pipelinq-bsn-brp';

	   
                                                                           
   
                           
    
	private const SUBJECT_KEYS = [
		'bsn',
		'subjectBsn',
		'subjectIdentifier',
		'subjectId',
		'subject',
		'verzoekerBsn',
	];

	   
                
   
                                                                            
                                                                      
                                              
    
	public function __construct(
		private readonly BsnValidationService $bsnValidation,
		private readonly HaalCentraalClient $brpClient,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                             
   
                  
   
                                                                                     
    
	public function getProviderId(): string {
		return self::PROVIDER_ID;
	}                     

	   
                                                                     
   
                             
                                                                          
                                                  
                                                      
                                                    
                                                  
   
                                                      
                                                                    
   
                                                                      
   
                                                                              
                                                                
   
                                                                                     
    
	public function verify(string $caseUuid, array $case): IdentityVerifyResult {
		$bsn = $this->extractBsn(case: $case);
		if ($bsn === null) {
			return IdentityVerifyResult::needsMore(
				providerId: self::PROVIDER_ID,
				message: 'No BSN present on the case; identity cannot be established yet.'
			);
		}

		$validation = $this->bsnValidation->validate(bsnInput: $bsn);
		if ($validation['isFormalValid'] === false) {
			return IdentityVerifyResult::failed(
				providerId: self::PROVIDER_ID,
				message: 'BSN failed the 11-proef (' . $validation['maskedBsn'] . ').'
			);
		}

		if ($this->brpClient->isConfigured() === false) {
			return IdentityVerifyResult::needsMore(
				providerId: self::PROVIDER_ID,
				message: 'BSN is formally valid but the BRP (Haal Centraal) client is not configured; '
					. 'positive identity requires a BRP match.'
			);
		}

		try {
			$person = $this->brpClient->lookupPersoon(bsn: $bsn, requestIdContext: $caseUuid);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq identity verify: BRP lookup failed',
				['case' => $caseUuid, 'exception' => $e->getMessage()]
			);
			return IdentityVerifyResult::failed(
				providerId: self::PROVIDER_ID,
				message: 'BRP lookup errored; identity not established (fail-closed).'
			);
		}

		if ($person === null || $person === []) {
			return IdentityVerifyResult::failed(
				providerId: self::PROVIDER_ID,
				message: 'No BRP record matched the supplied BSN.'
			);
		}

		return IdentityVerifyResult::verified(
			providerId: self::PROVIDER_ID,
			message: 'BSN passed the 11-proef and a BRP record was resolved.'
		);
	}              

	   
                                                                  
   
                                                                       
                                                                 
   
                                                                    
   
                                                              
    
	private function extractBsn(array $case): ?string {
		foreach (self::SUBJECT_KEYS as $key) {
			$value = ($case[$key] ?? null);
			if (is_string($value) === false && is_int($value) === false) {
				continue;
			}

			$candidate = trim((string)$value);
			if (strlen($candidate) === 9 && ctype_digit($candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}                  
}           
