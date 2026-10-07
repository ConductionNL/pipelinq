<?php

   
                            
  
                                                                         
                                                                                   
                                                                          
                                                                            
                                                                       
                                       
  
                    
                                          
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                                                  
  
                                                                    
                                                                  
                                                                 
                                                         
  
                                                                   
   
class TwilioSmsClient implements SmsProviderClientInterface {
	use MessageDispatchTrait;

	   
                                                               
   
               
    
	private const SEND_PATH = 'Messages.json';

	   
                
   
                                                                                    
                                          
                                                                     
                                                          
                                                                         
                                                                            
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private array $credentials,
		private string $fromNumber,
		private string $webhookSecret,
		private ?string $sourceId = null,
	) {
	}                   

	   
                                 
   
                                                            
                                        
   
                                                                         
   
                                                                   
                                                                  
   
                                                                                         
    
	public function send(string $toNumber, string $body): array {
		$payload = [
			'From' => $this->fromNumber,
			'To' => $toNumber,
			'Body' => $body,
		];

		$result = $this->dispatchViaLeaf(
			source: (string)($this->sourceId ?? ''),
			body: $payload,
			path: self::SEND_PATH,
			                                                            
			                                                  
			headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
		);
		$sid = (string)($result['sid'] ?? ($result['externalMessageId'] ?? ''));

		return [
			'externalMessageId' => $sid,
			'vendor' => $this->getVendor(),
		];
	}            

	   
                                                              
   
                                                                 
                                                                
   
                                            
                                          
   
                                     
                                                              
    
	public function verifySignature(string $rawBody, string $signature): bool {
		if ($this->webhookSecret === '' || $signature === '') {
			return false;
		}

		$expected = base64_encode(hash_hmac('sha256', $rawBody, $this->webhookSecret, true));
		if (hash_equals($expected, $signature) === true) {
			return true;
		}

		$expectedHex = hash_hmac('sha256', $rawBody, $this->webhookSecret);
		return hash_equals($expectedHex, $signature);
	}                       

	   
               
   
                            
                                                              
    
	public function getVendor(): string {
		return 'twilio';
	}                 
}           
