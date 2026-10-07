<?php

   
                                
  
                                                                             
                                                     
                                                                             
                                                                        
                                                                          
                                                                              
                                                                         
  
                                                                                
                                                                              
                                                                          
                                                                          
                                                                              
                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                                                 
  
                                                                              
                 
  
                                                              
                                                                                                                                     
   
class DataDeletionService {
	   
                                                                         
   
               
    
	public const OR_REQUEST_SERVICE = 'OCA\OpenRegister\Service\Gdpr\DataSubjectRequestService';

	   
                                                                            
   
                                                                               
                                                                              
                                                                          
            
   
               
    
	public const ERASE_MODE_PSEUDONYMISE = 'pseudonymise';

	   
                
   
                                                                      
                                                                          
                                                                             
                                                                      
   
                                                                            
                                              
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                      
   
                                                                     
                                                                            
                                                                             
                                                                            
                                                                            
                                                                             
                                                                          
   
                                                                                  
                                                                         
   
                                                                                            
   
                                                
   
                                                                                                                                      
    
	public function pseudonymizeCustomerBookings(string $customerId, bool $dryRun = false): array {
		$summary = ['bookings' => 0, 'held' => 0];

		if ($customerId === '') {
			return $summary;
		}

		$result = $this->eraseViaOr(subjectId: $customerId, type: null, dryRun: $dryRun);

		$summary['bookings'] = count((array)($result['erased'] ?? []));
		$summary['held'] = count((array)($result['held'] ?? []));

		$this->logger->info(
			'Pipelinq: AVG booking erasure via OR completed',
			[
				'summary' => $summary,
				'dryRun' => $dryRun,
				'complete' => (bool)($result['complete'] ?? false),
				'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			]
		);

		return $summary;
	}                                    

	   
                                                                              
   
                                                                        
                                                                           
                                                                              
                                                                          
                                                                          
                                                                         
                                                          
   
                                                          
                                                             
                                                                         
   
                                                                                 
   
                                                
   
                                                               
    
	private function eraseViaOr(string $subjectId, ?string $type = null, bool $dryRun = false): array {
		$service = $this->requestService();
		if ($service === null) {
			return ['subject' => $subjectId, 'matchedCount' => 0, 'erased' => [], 'held' => [], 'failed' => [], 'complete' => false];
		}

		try {
			return $service->erase(
				subjectId: $subjectId,
				type: $type,
				eraseMode: self::ERASE_MODE_PSEUDONYMISE,
				dryRun: $dryRun
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq AVG: OR erase failed',
				['exception' => $e->getMessage()]
			);
			return ['subject' => $subjectId, 'matchedCount' => 0, 'erased' => [], 'held' => [], 'failed' => [], 'complete' => false];
		}
	}                  

	   
                                                                        
   
                                                               
    
	private function requestService(): ?object {
		try {
			return $this->container->get(self::OR_REQUEST_SERVICE);
		} catch (\Throwable $e) {
			return null;
		}
	}                      
}           
