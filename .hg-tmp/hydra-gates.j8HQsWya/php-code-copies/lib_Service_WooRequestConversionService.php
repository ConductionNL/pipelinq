<?php

   
                                          
  
                                                                            
                                                                    
                                                                               
                                                                             
                                                                       
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                              
  
                                                                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Portal\MainRegisterReader;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                                
  
                                                                                                                                                                    
   
class WooRequestConversionService {
	   
                                                
   
               
    
	public const INTAKE_CLASS = 'OCA\\Dossiq\\Woo\\WooRequestIntake';

	   
                                                    
   
               
    
	public const ORIGIN = 'pipelinq';

	   
                                         
   
               
    
	public const STATUS_CONVERTED = 'converted';

	   
                                                    
   
               
    
	private const TICKET = 'ticket';

	   
                
   
                                                                                   
                                                                       
                                                  
                                                                                     
   
                                                                                                                                                                     
    
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly MainRegisterReader $tickets,
		private readonly LoggerInterface $logger,
		private readonly string $intakeClass = self::INTAKE_CLASS,
	) {
	}                   

	   
                                                   
   
                                                                                   
   
                                                                                           
   
                                                                                                                                                                     
    
	public function availability(array $ticket): array {
		$available = ($this->intake() !== null);

		return [
			'available' => $available,
			'canConvert' => ($available === true && $this->isConvertible(ticket: $ticket) === true),
			'status' => (string)($ticket['status'] ?? ''),
			'caseReference' => (string)($ticket['caseReference'] ?? ''),
		];
	}                    

	   
                                                                     
   
                                                        
                                                                                     
   
                                                                           
                                                                             
                                                                               
                                                       
   
                                                                                                                                                                     
    
	public function convert(string $ticketId, array $ticket): array {
		$intake = $this->intake();
		if ($intake === null) {
			return ['status' => 'not-available'];
		}

		if ($this->isConvertible(ticket: $ticket) === false) {
			return ['status' => 'not-convertible'];
		}

		try {
			$result = $intake->start($this->request(ticketId: $ticketId, ticket: $ticket));
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: Woo request intake refused the conversion', ['ticket' => $ticketId, 'reason' => $e->getMessage()]);
			return ['status' => 'intake-failed'];
		}

		$caseId = '';
		$caseUrl = '';
		if (is_array($result) === true) {
			$caseId = (string)($result['caseId'] ?? '');
			$caseUrl = (string)($result['caseUrl'] ?? '');
		}

		if ($caseId === '') {
			return ['status' => 'intake-failed'];
		}

		$ticket['ticketType'] = TicketService::TYPE_REQUEST;
		$ticket['status'] = self::STATUS_CONVERTED;
		$ticket['caseReference'] = $caseId;
		try {
			$this->tickets->save(schemaKey: self::TICKET, data: $ticket, id: $ticketId);
		} catch (Throwable $e) {
			                                                                  
			$this->logger->warning('Pipelinq: converted ticket not saved', ['ticket' => $ticketId, 'reason' => $e->getMessage()]);
			return ['status' => 'converted-unsynced', 'caseReference' => $caseId, 'caseUrl' => $caseUrl];
		}

		return ['status' => self::STATUS_CONVERTED, 'caseReference' => $caseId, 'caseUrl' => $caseUrl];
	}               

	   
                                                        
   
                                                        
                                                     
   
                                
    
	private function request(string $ticketId, array $ticket): array {
		$reference = (array)($ticket['subjectReference'] ?? []);

		return [
			'subjectRef' => (string)($ticket['portalSubject'] ?? ''),
			'collectionId' => (string)($reference['id'] ?? ''),
			'onderwerp' => (string)($ticket['title'] ?? ''),
			'omschrijving' => (string)($ticket['description'] ?? ''),
			'periodeVan' => null,
			'periodeTot' => null,
			'origin' => self::ORIGIN,
			'originReference' => $ticketId,
		];
	}               

	   
                                                                             
   
                                                   
   
                
    
	private function isConvertible(array $ticket): bool {
		$reference = ($ticket['subjectReference'] ?? null);

		return is_array($reference) === true
			&& (string)($reference['id'] ?? '') !== ''
			&& (string)($ticket['portalSubject'] ?? '') !== ''
			&& (string)($ticket['status'] ?? '') !== self::STATUS_CONVERTED
			&& (string)($ticket['caseReference'] ?? '') === '';
	}                     

	   
                                                          
   
                       
    
	private function intake(): ?object {
		if (class_exists($this->intakeClass) === false) {
			return null;
		}

		try {
			$intake = $this->container->get($this->intakeClass);
		} catch (Throwable $e) {
			$this->logger->debug('Pipelinq: Woo request intake not resolvable', ['reason' => $e->getMessage()]);
			return null;
		}

		if (is_object($intake) === false || method_exists($intake, 'start') === false) {
			return null;
		}

		return $intake;
	}              
}           
