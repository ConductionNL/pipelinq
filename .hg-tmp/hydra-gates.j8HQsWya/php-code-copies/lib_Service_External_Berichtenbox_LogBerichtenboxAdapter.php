<?php

   
                                                                  
  
                                                           
                                                              
                                                                   
                                      
                                             
                                                                
                                            
                                                                  
                                                      
                                               
                             
  
                                                                 
                                                             
  
                                                                     
                                                                
                                                                        
                                                                      
                                                  
                                                                        
                                                                 
                                                                      
                                                                       
                                                                   
                                                                         
                        
  
                                                              
                                                                   
                                                              
                                
  
                    
                                                       
  
                                                              
                                  
                                                                                    
  
                            
  
                                                                                      
  
                                                                    
                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\External\Berichtenbox;

use Psr\Log\LoggerInterface;

   
                                                                     
  
                                                                                      
   
class LogBerichtenboxAdapter implements BerichtenboxAdapterInterface {
	   
                                                  
   
                                                     
    
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                    
   
                                                                   
                                                              
                                            
   
                                                         
   
                                                    
                                                                                       
    
	public function dispatchMessage(array $message): BerichtenboxResult {
		$sanitised = $message;
		if (isset($sanitised['recipientBsn']) === true) {
			$sanitised['recipientBsn'] = '[REDACTED]';
		}

		if (isset($sanitised['body']) === true) {
			$sanitised['body'] = '[REDACTED-body-bytes=' . strlen((string)$sanitised['body']) . ']';
		}

		if (isset($sanitised['attachments']) === true && is_array($sanitised['attachments']) === true) {
			$sanitised['attachments'] = [
				'_redacted' => true,
				'count' => count($sanitised['attachments']),
			];
		}

		$reference = 'bbk-log-' . bin2hex(random_bytes(8));
		$this->logger->info(
			'Pipelinq Berichtenbox dispatch deferred (no outbound connector bound)',
			[
				'logiusKenmerk' => $reference,
				'message' => $sanitised,
			]
		);

		return new BerichtenboxResult(
			outcome: 'DISPATCH_DEFERRED',
			logiusReference: $reference,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind LogiusConnector (or an openconnector source slug `logius-berichtenbox`) by overriding '
					. 'BerichtenboxAdapterInterface in Application::register() to enable real transport.',
			],
		);
	}                       

	   
                                                                
           
   
                                                                 
                                                              
                       
   
                                                  
                                                         
   
                                                        
                                                                                       
    
	public function verifyDeliveryWebhook(string $rawBody, array $headers): BerichtenboxResult {
		$signature = $headers['X-Logius-Signature'] ?? ($headers['x-logius-signature'] ?? '');
		unset($headers['X-Logius-Signature'], $headers['x-logius-signature']);

		$reference = 'bbk-verify-log-' . bin2hex(random_bytes(6));
		$this->logger->info(
			'Pipelinq Berichtenbox verifyDeliveryWebhook deferred (no outbound connector bound)',
			[
				'logiusKenmerk' => $reference,
				'bodyLength' => strlen($rawBody),
				'signaturePresent' => ($signature !== ''),
				'headers' => $headers,
			]
		);

		return new BerichtenboxResult(
			outcome: 'VERIFY_DEFERRED',
			logiusReference: $reference,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind LogiusConnector and override BerichtenboxAdapterInterface in Application::register() '
					. 'to enable real webhook HMAC verification.',
			],
		);
	}                             

	   
                                                                
           
   
                                                          
                                                              
                       
   
                                                   
   
                                                          
   
                                                                                                     
                                                                            
                                  
                                                                                       
    
	public function checkMailbox(string $bsn): BerichtenboxResult {
		$reference = 'bbk-mbox-log-' . bin2hex(random_bytes(6));
		$this->logger->info(
			'Pipelinq Berichtenbox checkMailbox deferred (no outbound connector bound)',
			[
				'logiusKenmerk' => $reference,
				'bsn' => '[REDACTED]',
				'bsn_length_check' => (strlen($bsn) === 9),
			]
		);

		return new BerichtenboxResult(
			outcome: 'MAILBOX_DEFERRED',
			logiusReference: $reference,
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind LogiusConnector and override BerichtenboxAdapterInterface in Application::register() '
					. 'to enable real mailbox reachability checks. NEVER log BSN values.',
			],
		);
	}                    

	   
                                                           
   
               
   
                                                      
                                                    
    
	public function isDormant(): bool {
		return true;
	}                 
}           
