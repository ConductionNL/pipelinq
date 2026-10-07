<?php

   
                                      
  
                                                                      
                                                                                     
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use Exception;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                                      
                                                          
  
                                                                     
                                                                       
                                                                      
                                                                       
                                                                       
                   
                                                                      
                                                                          
                                    
  
                                                                                                
  
                                                                                          
   
class Customer360SummaryService {
	   
                                                                                        
                                                                         
   
                           
    
	private const OPEN_TICKET_STATUSES = ['new', 'in_progress'];

	   
                                            
   
                                                                           
                                                                         
                                                                             
                                                                            
                                                                            
                                                                             
                                                                              
                                                                             
                                                                     
                                                                          
   
            
    
	private const AT_RISK_WINDOW_HOURS = 24;

	   
                
   
                                                                                       
                                                           
                                                                                         
                                                                                                     
                                          
                                                                                         
    
	public function __construct(
		private readonly RegisterResolverService $registerResolver,
		private readonly IAppConfig $appConfig,
		private readonly TicketService $ticketService,
		private readonly ActivityTimelineService $activityTimeline,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                               
   
                                                                                 
                                                                       
                                                                              
   
                                            
   
                                                                          
   
                                                                                           
    
	public function getSummary(string $clientId): array {
		$now = new DateTimeImmutable('now');

		[$openTicketsByType, $slaBreached, $slaAtRisk] = $this->collectOpenTickets(clientId: $clientId, now: $now);

		$openLeads = $this->findLeadsSafe(filters: ['client' => $clientId, 'status' => 'open']);
		$openLeadValue = 0.0;
		foreach ($openLeads as $lead) {
			$openLeadValue += (float)($lead['value'] ?? 0);
		}

		return [
			'clientId' => $clientId,
			'openTicketCount' => array_sum($openTicketsByType),
			'openTicketsByType' => $openTicketsByType,
			'sla' => [
				'breached' => $slaBreached,
				'atRisk' => $slaAtRisk,
			],
			'openLeadCount' => count($openLeads),
			'openLeadValue' => $openLeadValue,
			'lastActivityAt' => $this->resolveLastActivity(clientId: $clientId),
		];
	}                  

	   
                                                                           
                                                                      
   
                                            
                                                                      
   
                                                                                                    
    
	private function collectOpenTickets(string $clientId, DateTimeImmutable $now): array {
		$atRiskThreshold = $now->modify('+' . self::AT_RISK_WINDOW_HOURS . ' hours');

		$openTicketsByType = [];
		$slaBreached = 0;
		$slaAtRisk = 0;

		foreach (TicketService::TYPES as $ticketType) {
			$tickets = $this->ticketService->findByType(
				$ticketType,
				['client' => $clientId, 'status' => self::OPEN_TICKET_STATUSES]
			);
			$openTicketsByType[$ticketType] = count($tickets);

			foreach ($tickets as $rawTicket) {
				$ticket = $this->toArray(object: $rawTicket);

				$deadline = $this->parseDeadline(raw: (string)($ticket['slaDeadline'] ?? ''));
				if ($deadline !== null) {
					if ($deadline < $now) {
						$slaBreached++;
					} elseif ($deadline <= $atRiskThreshold) {
						$slaAtRisk++;
					}
				}
			}
		}             

		return [$openTicketsByType, $slaBreached, $slaAtRisk];
	}                          

	   
                                                                                   
   
                                                         
   
                                                               
    
	private function parseDeadline(string $raw): ?DateTimeImmutable {
		if ($raw === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($raw);
		} catch (Exception $e) {
			return null;
		}
	}                     

	   
                                                                      
                                                                        
                                         
   
                                            
   
                                                                                
    
	private function resolveLastActivity(string $clientId): ?string {
		try {
			$timeline = $this->activityTimeline->getTimeline(
				entityType: 'client',
				entityId: $clientId,
				params: ['_page' => 1, '_limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Customer360SummaryService: last-activity lookup failed',
				['clientId' => $clientId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		$date = ($timeline['items'][0]['date'] ?? null);
		if (is_string($date) === true && $date !== '') {
			return $date;
		}

		return null;
	}                           

	   
                                                  
   
                                                                                              
    
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                  
   
                                                                  
    
	private function getRegister(): string {
		return $this->registerResolver->resolve('customer-360');
	}                   

	   
                                           
   
                                                                 
   
                                                                      
    
	private function getSchema(string $schemaKey): string {
		return $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
	}                 

	   
                                                                        
   
                                                                       
   
                                                         
    
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}               

	   
                                                                          
                                                                                   
   
                                                             
   
                                                                 
    
	private function findLeadsSafe(array $filters): array {
		$register = $this->getRegister();
		$schema = $this->getSchema(schemaKey: 'lead_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->getObjectService()->findAll(
				['filters' => array_merge(['register' => $register, 'schema' => $schema], $filters)]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Customer360SummaryService: lead findAll failed',
				['error' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach ($results as $result) {
			$out[] = $this->toArray(object: $result);
		}

		return $out;
	}                     
}           
