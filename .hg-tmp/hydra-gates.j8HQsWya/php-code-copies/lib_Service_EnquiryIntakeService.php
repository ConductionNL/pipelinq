<?php

   
                          
  
                                                                           
                                 
  
                                                                            
                                                                               
                                                                               
                                                                               
                                                                          
                                      
  
                                                                                 
                                                                             
                                                                              
                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

   
                                                         
   
class EnquiryIntakeService {

	   
                                        
   
                                                                 
                                                                        
                                                                           
                                                                           
                       
   
                           
    
	private const SUBMITTER_FIELDS = [
		'title',
		'contactName',
		'contactEmail',
		'contactPhone',
		'organisation',
		'message',
		'source',
		'pageUrl',
		'locale',
	];

	   
                                                               
   
                                                                        
                                                                            
                                                                       
                                                  
   
                           
    
	private const ALLOWED_SOURCES = [
		                                                                     
		                                                                       
		                                                                       
		                                               
		'website-support',
		'website-partner',
		'website-contact',
	];

	   
                                            
   
                                                                      
                                                                           
    
	private const MAX_MESSAGE_LENGTH = 20000;

	   
                
   
                                                                                                         
                                                                                                         
                                                                                         
   
                                                                                          
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                               
   
                                                                  
   
                                                                         
                                                                           
                                                           
   
                                                                    
                                                                                   
   
                                                                                                                                                    
    
	public function submit(array $payload): string {
		$this->refuseHoneypot(payload: $payload);

		$fields = $this->whitelist(payload: $payload);
		$source = $this->resolveSource(submitted: ($fields['source'] ?? ''));

		$this->refuseEmpty(fields: $fields);

		$object = $fields;
		                                                                    
		                                                                   
		$object['source'] = $source;
		$object['status'] = 'new';
		$object['receivedAt'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$object['title'] = $this->resolveTitle(fields: $fields);

		return $this->store(object: $object);
	}              

	   
                                                        
   
                                                                          
                                                         
   
                                                                  
   
                
   
                                                                       
   
                                                                                                                               
    
	private function refuseHoneypot(array $payload): void {
		$raw = ($payload['website'] ?? '');
		                                                                       
		                                                                      
		                                             
		if (is_scalar($raw) === true && trim((string)$raw) === '') {
			return;
		}

		$this->logger->info('Pipelinq: website enquiry refused, honeypot filled.');
		throw new InvalidArgumentException('This submission could not be accepted.');
	}                      

	   
                                                       
   
                                                                            
                                                                           
                                      
   
                                                                  
   
                                                           
   
                                                                                                                                                    
    
	private function whitelist(array $payload): array {
		$fields = [];
		foreach (self::SUBMITTER_FIELDS as $name) {
			$raw = ($payload[$name] ?? '');
			                                                                
			                                                                   
			                                                                 
			                                              
			if (is_scalar($raw) === false) {
				continue;
			}

			$value = trim((string)$raw);
			if ($value === '') {
				continue;
			}

			$fields[$name] = $value;
		}

		return $fields;
	}                 

	   
                                                       
   
                                                          
   
                                       
   
                                                                          
   
                                                                                                                                                      
    
	private function resolveSource(string $submitted): string {
		if (in_array($submitted, self::ALLOWED_SOURCES, true) === true) {
			return $submitted;
		}

		                                                                     
		                                                                       
		                                                                    
		                                      
		$this->logger->warning(
			'Pipelinq: website enquiry refused, unknown source.',
			['source' => $submitted]
		);
		throw new InvalidArgumentException('This submission could not be accepted.');
	}                     

	   
                                                             
   
                                                                
   
                
   
                                                                                   
   
                                                                                                                                 
    
	private function refuseEmpty(array $fields): void {
		if (isset($fields['message']) === true || isset($fields['contactEmail']) === true) {
			if (mb_strlen((string)($fields['message'] ?? '')) > self::MAX_MESSAGE_LENGTH) {
				throw new InvalidArgumentException('This submission could not be accepted.');
			}

			return;
		}

		throw new InvalidArgumentException('This submission could not be accepted.');
	}                   

	   
                                    
   
                                                                          
                                                                             
                            
   
                                                                
   
                                     
   
                                                                                                                                                     
    
	private function resolveTitle(array $fields): string {
		$candidates = [
			($fields['title'] ?? ''),
			($fields['organisation'] ?? ''),
			($fields['contactName'] ?? ''),
			($fields['contactEmail'] ?? ''),
		];
		foreach ($candidates as $candidate) {
			if (trim($candidate) !== '') {
				return mb_substr(trim($candidate), 0, 255);
			}
		}

		return 'Website enquiry';
	}                    

	   
                                      
   
                                                            
   
                                                                                 
   
                                                                           
   
                                                                                                                                                      
    
	private function store(array $object): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'enquiry_schema', '');
		if ($registerId === '' || $schemaId === '') {
			                                                                
			                                           
			throw new RuntimeException('Pipelinq: the enquiry register or schema is not configured.');
		}

		$saved = $this->objectService->saveObject(
			$object,
			[],
			$registerId,
			$schemaId,
			null
		);

		return (string)($saved->getUuid() ?? '');
	}             
}           
