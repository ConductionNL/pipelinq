<?php

   
                                
  
                                                                        
                                                                     
                                                                   
                                                               
                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                            
  
                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                            
  
                                                                                                    
   
class DeliveryAuditLogger {
	   
                                                                    
   
            
    
	public const DEFAULT_RETENTION_YEARS = 10;

	   
                
   
                                            
                                          
                                                                                         
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                       
   
                                          
                                               
                                                                                  
                                                      
   
                
                                                              
    
	public function logQueued(
		string $messageId,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(messageId: $messageId, event: 'queued', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
	}                 

	   
                                    
   
                                          
                                             
                                               
                                                                     
                               
   
                
                                                              
    
	public function logSent(
		string $messageId,
		string $logiusMessageId,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(
			messageId: $messageId,
			event: 'sent',
			payloadHash: $payloadHash,
			retentionUntil: $retentionUntil,
			actor: $actor,
			extras: ['logiusMessageId' => $logiusMessageId]
		);
	}               

	   
                     
   
                                          
                                       
                                                            
                               
   
                
                                                              
    
	public function logRead(
		string $messageId,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(messageId: $messageId, event: 'read', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
	}               

	   
                                                            
   
                                          
                                         
                                       
                                                            
                               
   
                
                                                              
    
	public function logFallback(
		string $messageId,
		string $reason,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(
			messageId: $messageId,
			event: 'fallback',
			payloadHash: $payloadHash,
			retentionUntil: $retentionUntil,
			actor: $actor,
			extras: ['reason' => $reason]
		);
	}                   

	   
                  
   
                                          
                                         
                                       
                                                            
                               
   
                
                                                              
    
	public function logFailed(
		string $messageId,
		string $reason,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(
			messageId: $messageId,
			event: 'failed',
			payloadHash: $payloadHash,
			retentionUntil: $retentionUntil,
			actor: $actor,
			extras: ['reason' => $reason]
		);
	}                 

	   
                               
   
                                      
                                       
                                                            
                               
   
                
                                                              
    
	public function logReplyReceived(
		string $replyId,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(messageId: $replyId, event: 'reply-received', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
	}                        

	   
                           
   
                                          
                                
                                       
                                                            
                               
   
                
                                                              
    
	public function logProcessingError(
		string $messageId,
		string $reason,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(
			messageId: $messageId,
			event: 'processing-error',
			payloadHash: $payloadHash,
			retentionUntil: $retentionUntil,
			actor: $actor,
			extras: ['reason' => $reason]
		);
	}                          

	   
                  
   
                                          
                                       
                                                            
                               
   
                
                                                              
    
	public function logOptedOut(
		string $messageId,
		string $payloadHash,
		?DateTimeInterface $retentionUntil = null,
		string $actor = 'system',
	): void {
		$this->writeEvent(messageId: $messageId, event: 'opted-out', payloadHash: $payloadHash, retentionUntil: $retentionUntil, actor: $actor);
	}                   

	   
                                                                
   
                                                                 
                                                                     
                                                            
   
                                          
                                                                 
   
                             
   
                                                                                                     
   
                                                                                                                                  
    
	public function calculateRetentionUntil(string $caseType, ?DateTimeInterface $from = null): DateTimeImmutable {
		$years = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'selectielijst.' . $caseType,
			(string)self::DEFAULT_RETENTION_YEARS
		);
		if ($years <= 0) {
			$years = self::DEFAULT_RETENTION_YEARS;
		}

		$anchor = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($from instanceof DateTimeInterface) {
			$anchor = DateTimeImmutable::createFromInterface($from);
		}

		$retentionUntil = $anchor->modify('+' . $years . ' years');
		assert($retentionUntil !== false);
		return $retentionUntil;
	}                               

	   
                                                    
   
                                     
   
                              
                                                              
    
	public function hashPayload(string $body): string {
		return hash('sha256', $body);
	}                   

	   
                        
   
                                          
                                   
                                    
                                                                
                               
                                                      
   
                
    
	private function writeEvent(
		string $messageId,
		string $event,
		string $payloadHash,
		?DateTimeInterface $retentionUntil,
		string $actor,
		array $extras = [],
	): void {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$retention = ($retentionUntil ?? $this->calculateRetentionUntil(caseType: ''));

		$row = array_merge(
			$extras,
			[
				'messageId' => $messageId,
				'event' => $event,
				'eventAt' => $now->format(DATE_ATOM),
				'actor' => $actor,
				'payloadHash' => $payloadHash,
				'retentionUntil' => $retention->format(DATE_ATOM),
			]
		);

		try {
			[$register, $schema] = $this->config();
			$this->getObjectService()->saveObject(
				object: $row,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			                                                             
			                                       
			$this->logger->error(
				'DeliveryAuditLogger write failed.',
				['exception' => $e->getMessage(), 'event' => $event, 'messageId' => $messageId]
			);
		}
	}                  

	   
                             
   
                                                             
    
	private function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                              
   
                                       
    
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			'deliveryAuditLog_schema',
			''
		);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('DeliveryAuditLog register or schema not configured.');
		}

		return [$register, $schema];
	}              
}           
