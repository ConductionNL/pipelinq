<?php

   
                           
  
                                                                  
                                                                                   
                                                                           
                                     
  
                    
                                          
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                                  
  
                                                                      
                          
  
                                                                   
   
class CmComSmsClient implements SmsProviderClientInterface {
	use MessageDispatchTrait;

	   
                                                               
   
               
    
	private const SEND_PATH = 'messages';

	   
                
   
                                                      
                                          
                                                                 
                                                        
                                                    
                                                         
   
                                                                    
    
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
			'from' => $this->fromNumber,
			'to' => [$toNumber],
			'body' => $body,
		];

		$result = $this->dispatchViaLeaf(
			source: (string)($this->sourceId ?? ''),
			body: $payload,
			path: self::SEND_PATH,
		);
		$id = (string)($result['messageId'] ?? ($result['externalMessageId'] ?? ''));

		return [
			'externalMessageId' => $id,
			'vendor' => $this->getVendor(),
		];
	}            

	   
                                       
   
                                    
                                    
   
                                     
                                                              
    
	public function verifySignature(string $rawBody, string $signature): bool {
		if ($this->webhookSecret === '' || $signature === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
		return hash_equals($expected, $signature);
	}                       

	   
               
   
                            
                                                              
    
	public function getVendor(): string {
		return 'cm-com';
	}                 
}           
