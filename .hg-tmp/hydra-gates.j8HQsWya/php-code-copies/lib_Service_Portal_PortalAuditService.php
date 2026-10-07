<?php

   
                               
  
                                                                               
                                                                           
                                                                                
                                                                              
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

   
                                               
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalAuditService {
	   
                                 
   
               
    
	private const SCHEMA = 'portalAuditEvent';

	   
                
   
                                                                           
                                               
                                              
    
	public function __construct(
		private PortalObjectRepository $repository,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                          
                                                                          
                          
   
                                                               
                                          
                                                                 
                                                                                   
                                                                          
   
                
                                                                                         
                                                                            
                                                                            
                               
    
	public function log(
		?string $accountId,
		string $tenantId,
		string $eventType,
		string $outcome,
		array $details = [],
	): void {
		$record = [
			'accountId' => $accountId,
			'tenantId' => $tenantId,
			'eventType' => $eventType,
			'outcome' => $outcome,
			'occurredAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'targetObjectType' => ($details['targetObjectType'] ?? null),
			'targetObjectId' => ($details['targetObjectId'] ?? null),
			'ipHash' => ($details['ipHash'] ?? null),
			'userAgentHash' => ($details['userAgentHash'] ?? null),
		];

		                                                                        
		$extra = $details;
		unset($extra['targetObjectType'], $extra['targetObjectId'], $extra['ipHash'], $extra['userAgentHash']);
		if (empty($extra) === false) {
			$record['details'] = $extra;
		}

		try {
			$this->repository->save(self::SCHEMA, $record);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Pipelinq portal: failed to write audit event',
				['eventType' => $eventType, 'exception' => $e->getMessage()]
			);
		}
	}           

	   
                                                                     
   
                                            
   
                                                                      
                                                                                         
                                                                            
                                                                            
                               
    
	public function getForAccount(string $accountId): array {
		$events = $this->repository->findAll(self::SCHEMA, ['accountId' => $accountId]);
		return $this->sortByOccurredAtDesc(events: $events);
	}                     

	   
                                                            
   
                                          
   
                                                                      
                                                                                         
                                                                            
                                                                            
                               
    
	public function getForTenant(string $tenantId): array {
		$events = $this->repository->findAll(self::SCHEMA, ['tenantId' => $tenantId]);
		return $this->sortByOccurredAtDesc(events: $events);
	}                    

	   
                                                             
   
                                                               
   
                                                               
    
	private function sortByOccurredAtDesc(array $events): array {
		usort(
			$events,
			static function (array $left, array $right): int {
				return strcmp((string)($right['occurredAt'] ?? ''), (string)($left['occurredAt'] ?? ''));
			}
		);

		return $events;
	}                            
}           
