<?php

   
                  
  
                                                             
                                                                                 
                                                                                  
                                                                              
                                             
  
                                                                                 
                          
                                           
                                      
                                            
                                           
                                         
                                             
                                         
                                                  
                                       
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                              
  
                                                                                                                         
   
class TicketService {
	   
                                                                                
   
               
    
	public const TYPE_REQUEST = 'request';

	   
                                                                           
   
               
    
	public const TYPE_COMPLAINT = 'complaint';

	   
                                                                               
   
               
    
	public const TYPE_CONTACTMOMENT = 'interaction';

	   
                                                 
   
                           
    
	public const TYPES = [
		self::TYPE_REQUEST,
		self::TYPE_COMPLAINT,
		self::TYPE_CONTACTMOMENT,
	];

	   
                                                            
   
                                                                             
                                                                               
   
                           
    
	public const DATE_TIME_FIELDS = [
		'occurredAt',
		'slaDeadline',
		'resolvedAt',
	];

	   
                                               
   
                                    
                                                            
   
                           
    
	public const DIRECTIONS = [
		'inbound',
		'outbound',
		'internal',
	];

	   
                                                                           
   
                                                                          
                                                                            
                                                                         
                                                                         
                              
   
                                          
   
                                                                                                                                                    
    
	public const FACET_REQUIRED = [
		self::TYPE_CONTACTMOMENT => ['direction'],
	];

	   
                                                                      
                                                                
                                                                      
   
                              
    
	private const TYPE_VOCABULARY = [
		self::TYPE_REQUEST => '/\b(request|requests|verzoek|verzoeken|aanvraag|aanvragen)\b/u',
		self::TYPE_CONTACTMOMENT => '/\b(contactmoment|contactmomenten|contact)\b/u',
	];

	   
                
   
                                                
                                              
                                                                                 
                                                                           
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly McpAnswer $mcp,
	) {
	}                   

	   
                                       
   
                                                                                 
   
                                                              
   
                                                                                                                          
    
	public function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                             
   
                                                               
                                                                     
                                                                        
                                                                      
                                                                          
                                                                       
                                                         
   
                                                          
   
                                                                                                                          
    
	public function getRegisterId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; ticket reads/writes are refused, not run unscoped'
			);
		}

		return $registerId;
	}                     

	   
                                 
   
                                                               
                                                                            
                                                                     
                                                                       
                                                                           
                                                                         
                                           
   
                                                        
   
                                                                                                                          
    
	public function getSchemaId(): string {
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'ticket_schema', '');
		if ($schemaId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "ticket_schema" is not configured; ticket reads/writes are refused, not run unscoped'
			);
		}

		return $schemaId;
	}                   

	   
                                                              
   
                                                        
   
                                                                                                                          
    
	public function isConfigured(): bool {
		return $this->getRegisterId() !== '' && $this->getSchemaId() !== '';
	}                    

	   
                                                                 
   
                                                                           
                                                                             
                                                
   
                                                                 
   
                                                                          
                                         
   
                                                                                                                          
    
	public function detectTypeInText(string $text): ?string {
		$lower = mb_strtolower($text);
		foreach (self::TYPE_VOCABULARY as $ticketType => $pattern) {
			if (preg_match($pattern, $lower) === 1) {
				return $ticketType;
			}
		}

		return null;
	}                        

	   
                                
   
                                                                              
                                                               
   
                                                          
                                                                              
                               
   
                                                       
   
                                                                                                                            
    
	public function findByType(string $ticketType, array $extraFilters = [], int $limit = 10000): array {
		if ($this->isConfigured() === false) {
			return [];
		}

		$filters = array_merge(
			[
				'register' => $this->getRegisterId(),
				'schema' => $this->getSchemaId(),
				'ticketType' => $ticketType,
			],
			$extraFilters
		);

		try {
			return $this->getObjectService()->findAll(['filters' => $filters, 'limit' => $limit]);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TicketService.findByType failed',
				['ticketType' => $ticketType, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}                  

	   
                                             
   
                                                                         
                                             
   
                                                          
                                                           
                                                                     
   
                                                                              
                                                                            
                                                                              
                                                                  
   
                                                                   
                                                                         
                                                                          
                                                                       
                                                                        
                                        
   
                                                                                                                                
    
	public function save(string $ticketType, array $payload, ?string $uuid = null): object {
		if ($this->isConfigured() === false) {
			throw new RuntimeException('Ticket register or schema not configured.');
		}

		$payload = $this->sanitizeForSave(payload: $payload);

		$this->assertFacetFields(ticketType: $ticketType, payload: $payload);

		$payload['ticketType'] = $ticketType;

		return $this->getObjectService()->saveObject(
			object: $payload,
			register: $this->getRegisterId(),
			schema: $this->getSchemaId(),
			uuid: $uuid,
		);
	}            

	   
                                                            
   
                                                                        
                                                                          
                                                                          
                                                                         
                                                              
   
                                                          
                                                           
   
                
   
                                                                           
                                                   
   
                                                                                                                                                    
    
	public function assertFacetFields(string $ticketType, array $payload): void {
		foreach ((self::FACET_REQUIRED[$ticketType] ?? []) as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				throw new InvalidArgumentException(
					"A {$ticketType} ticket requires {$field}."
				);
			}
		}

		$direction = ($payload['direction'] ?? null);
		if (is_string($direction) === true && $direction !== ''
			&& in_array($direction, self::DIRECTIONS, true) === false
		) {
			throw new InvalidArgumentException(
				'direction must be one of ' . implode(', ', self::DIRECTIONS) . "; got {$direction}."
			);
		}

		$this->assertCaseSet(payload: $payload);
	}                         

	   
                                                                 
   
                                                                          
                                                                          
                                                            
   
                                                           
   
                
   
                                                                           
                                    
   
                                                                                                                                                                     
    
	private function assertCaseSet(array $payload): void {
		$set = ($payload['caseReferences'] ?? null);
		if (is_array($set) === false || $set === []) {
			return;
		}

		$seen = [];
		foreach ($set as $reference) {
			$reference = trim((string)$reference);
			if ($reference === '') {
				continue;
			}

			if (in_array($reference, $seen, true) === true) {
				throw new InvalidArgumentException(
					"caseReferences already holds {$reference}; a contact moment is filed on a case once."
				);
			}

			$seen[] = $reference;
		}

		$primary = trim((string)($payload['primaryCaseReference'] ?? ''));
		if ($primary !== '' && in_array($primary, $seen, true) === false) {
			throw new InvalidArgumentException(
				"primaryCaseReference {$primary} is not one of the cases this contact moment is filed on."
			);
		}
	}                     

	   
                                                             
   
                                                                           
                                                                            
                                                                          
                                                                           
                                         
   
                                                                             
                                                                          
                                                
   
                                                           
   
                                                                         
   
                                                                                                                                
    
	public function sanitizeForSave(array $payload): array {
		foreach (self::DATE_TIME_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === false || $value === '') {
				continue;
			}

			try {
				$payload[$field] = (new DateTimeImmutable($value))->format('c');
			} catch (Exception $e) {
				                                                             
				continue;
			}
		}

		return $payload;
	}                       

	                                                                      
	                                                                          
	                                                                        
	                                                                       
	                                                                 
	#[McpTool(
		name: 'logContactmoment',
		subject: 'contactMoment',
		action: 'create',
		description: 'Log a client interaction as a contactmoment (client, channel, title and direction are required; outcome and notes are optional).',
		readOnlyHint: false,
		destructiveHint: false,
		idempotentHint: false,
		scope: 'create'
	)]
	   
                                                                          
                                                  
   
                                                         
                                                                      
                                                                   
                                                                        
                                                                    
                                                                          
                                                                 
   
                                                                   
                                                                                       
                                                            
                                                                                      
                                                                                                    
                                                                             
   
                                
   
                                                                                                                   
    
	public function logContactmoment(
		string $client,
		string $channel,
		string $title,
		string $direction = '',
		?string $outcome = null,
		?string $notes = null,
	): array {
		$client = trim($client);
		if ($client === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument client is missing.');
		}

		$channel = trim($channel);
		if ($channel === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument channel is missing.');
		}

		$title = trim($title);
		if ($title === '') {
			return $this->mcp->error(code: 'invalid_arguments', message: 'Required argument title is missing.');
		}

		                                                                
		                                                                  
		                                                                  
		                                                                     
		                                               
		$direction = trim($direction);
		if ($direction === '') {
			return $this->mcp->error(
				code: 'invalid_arguments',
				message: 'Required argument direction is missing.'
			);
		}

		if (in_array($direction, self::DIRECTIONS, true) === false) {
			return $this->mcp->error(
				code: 'invalid_arguments',
				message: 'Argument direction must be one of ' . implode(', ', self::DIRECTIONS) . '.'
			);
		}

		if ($this->isConfigured() === false) {
			return $this->mcp->error(
				code: 'not_configured',
				message: 'Pipelinq is not fully configured: the OpenRegister register or ticket schema is missing.'
			);
		}

		$payload = [
			'client' => $client,
			'channel' => $channel,
			'title' => $title,
			'direction' => $direction,
			'occurredAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		if ($outcome !== null && $outcome !== '') {
			$payload['outcome'] = $outcome;
		}

		if ($notes !== null && $notes !== '') {
			$payload['description'] = $notes;
		}

		try {
			$saved = $this->save(ticketType: self::TYPE_CONTACTMOMENT, payload: $payload);
		} catch (\Exception $e) {
			return $this->mcp->fromException(operation: 'log contactmoment', exception: $e);
		}

		$data = $this->mcp->toArray(item: $saved);

		return [
			'ticketId' => (string)($data['id'] ?? $data['uuid'] ?? ''),
		];

	}                        

}           
