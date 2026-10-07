<?php

   
                                       
  
                                                                            
                                                                               
                                                                               
                                                                              
                                                  
  
                                                                   
  
                                                                   
                                                                            
                                
                                                                    
                                                                              
                                                                              
                  
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                     
  
                                                                                                                                                                   
   
class ContactMomentFilingService {
	   
                
   
                                                                                    
                                                                            
                                              
    
	public function __construct(
		private readonly TicketService $ticketService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                          
   
                                                                             
                                                                        
                                  
   
                                                                  
   
                                                                  
   
                                                                                                                                                                    
    
	public function caseSetOf(array $moment): array {
		$set = ($moment['caseReferences'] ?? null);
		if (is_array($set) === false || $set === []) {
			$single = trim((string)($moment['caseReference'] ?? ''));
			$set = [];
			if ($single !== '') {
				$set = [$single];
			}
		}

		$ordered = [];
		foreach ($set as $reference) {
			$reference = trim((string)$reference);
			if ($reference !== '' && in_array($reference, $ordered, true) === false) {
				$ordered[] = $reference;
			}
		}

		return $ordered;
	}                 

	   
                                                       
   
                                                                             
                                                                     
                                                      
   
                                                                  
   
                                                                           
   
                                                                                                                                                                     
    
	public function primaryOf(array $moment): string {
		$set = $this->caseSetOf(moment: $moment);
		$primary = trim((string)($moment['primaryCaseReference'] ?? ''));

		if ($primary !== '' && in_array($primary, $set, true) === true) {
			return $primary;
		}

		return ($set[0] ?? '');
	}                 

	   
                                                          
   
                                                      
                                                   
   
                                                                                 
   
                                                                                                                                                                    
    
	public function fileOnAlsoCase(string $momentId, string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return ['status' => 400, 'error' => 'A case is required.'];
		}

		$moment = $this->read(momentId: $momentId);
		if ($moment === null) {
			return ['status' => 404, 'error' => 'That contact moment could not be read.'];
		}

		$set = $this->caseSetOf(moment: $moment);
		if (in_array($caseId, $set, true) === true) {
			return [
				'status' => 409,
				'error' => "This contact moment is already filed on {$caseId}.",
			];
		}

		$set[] = $caseId;

		return $this->write(
			moment: $moment,
			set: $set,
			primary: $this->primaryOf(moment: $moment),
			trail: ['case' => $caseId, 'act' => 'filed'],
		);
	}                      

	   
                                       
   
                                                      
                                                  
                                                                      
                                      
   
                                                                                 
   
                                                                                                                                                                 
    
	public function unfileFromCase(string $momentId, string $caseId, ?string $newPrimary = null): array {
		$caseId = trim($caseId);
		$moment = $this->read(momentId: $momentId);
		if ($moment === null) {
			return ['status' => 404, 'error' => 'That contact moment could not be read.'];
		}

		$set = $this->caseSetOf(moment: $moment);
		if (in_array($caseId, $set, true) === false) {
			return ['status' => 404, 'error' => "This contact moment is not filed on {$caseId}."];
		}

		if (count($set) === 1) {
			return [
				'status' => 409,
				'error' => 'A contact moment has to stay on at least one case.',
			];
		}

		$remaining = array_values(array_filter($set, static fn (string $r): bool => $r !== $caseId));
		$primary = $this->primaryOf(moment: $moment);
		$newPrimary = trim((string)$newPrimary);

		if ($primary === $caseId) {
			if ($newPrimary === '') {
				return [
					'status' => 409,
					'error' => 'Removing the primary case needs the next primary named in the same act.',
				];
			}

			if (in_array($newPrimary, $remaining, true) === false) {
				return [
					'status' => 409,
					'error' => "The new primary has to be one of the remaining cases; {$newPrimary} is not.",
				];
			}

			$primary = $newPrimary;
		}

		return $this->write(
			moment: $moment,
			set: $remaining,
			primary: $primary,
			trail: ['case' => $caseId, 'act' => 'unfiled'],
		);
	}                      

	   
                                                                     
   
                                                                             
                                                                          
                             
   
                                                                  
                                                              
                                                                                     
   
                                                                                  
                 
   
                                                                                                                                                                        
    
	public function sharedMarker(array $moment, string $hostId, callable $mayRead): array {
		$others = array_values(
			array_filter(
				$this->caseSetOf(moment: $moment),
				static fn (string $reference): bool => $reference !== $hostId
			)
		);

		$visible = [];
		$hidden = 0;
		foreach ($others as $reference) {
			if ($mayRead($reference) === true) {
				$visible[] = $reference;
				continue;
			}

			                                                                 
			                                                               
			$hidden++;
		}

		return [
			'shared' => ($others !== []),
			'alsoOnCases' => $visible,
			'hiddenCount' => $hidden,
		];
	}                    

	   
                                             
   
                                                                          
                                                             
   
                                                      
   
                                                              
    
	private function read(string $momentId): ?array {
		$momentId = trim($momentId);
		if ($momentId === '' || $this->ticketService->isConfigured() === false) {
			return null;
		}

		try {
			$row = $this->ticketService->getObjectService()->find(id: $momentId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'ContactMomentFilingService: could not read the contact moment',
				['uuid' => $momentId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($row === null) {
			return null;
		}

		$data = $row->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}            

	   
                                                                     
   
                                                                  
                                                       
                                           
                                                                     
   
                                                                                 
    
	private function write(array $moment, array $set, string $primary, array $trail): array {
		$moment['caseReferences'] = array_values($set);
		$moment['primaryCaseReference'] = $primary;
		                                                                      
		                                                           
		$moment['caseReference'] = $primary;

		$filings = ($moment['caseFilings'] ?? []);
		if (is_array($filings) === false) {
			$filings = [];
		}

		$filings[] = [
			'case' => $trail['case'],
			'act' => $trail['act'],
			'by' => ($this->userSession->getUser()?->getUID() ?? ''),
			'at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
		$moment['caseFilings'] = $filings;

		try {
			$saved = $this->ticketService->save(
				ticketType: TicketService::TYPE_CONTACTMOMENT,
				payload: $moment,
				uuid: (string)($moment['id'] ?? $moment['uuid'] ?? ''),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'ContactMomentFilingService: the filing act could not be saved',
				['exception' => $e->getMessage()]
			);

			return ['status' => 500, 'error' => 'The filing could not be saved.'];
		}

		$data = $saved->jsonSerialize();
		if (is_array($data) === false) {
			$data = $moment;
		}

		return [
			'status' => 200,
			'contactMoment' => $data,
		];
	}             
}           
