<?php

   
                               
  
                                                              
                                                                    
                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                            
  
                       
                                                                 
                                                           
               
  
                                                                   
   
class SmsProviderFactory {
	   
                
   
                                                      
                                          
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                     
   
                                                                     
   
                                                                   
                                                                        
   
                                                                    
    
	public function create(array $channelProvider): ?SmsProviderClientInterface {
		$vendor = (string)($channelProvider['vendor'] ?? '');
		$fromNumber = (string)($channelProvider['phoneNumber'] ?? '');
		$secret = (string)($channelProvider['webhookSecret'] ?? '');
		$sourceId = (string)($channelProvider['sourceId'] ?? ($channelProvider['openconnectorSourceId'] ?? ''));
		$credentials = (array)($channelProvider['credentials'] ?? []);

		$sourceIdOrNull = $sourceId;
		if ($sourceId === '') {
			$sourceIdOrNull = null;
		}

		return match ($vendor) {
			'twilio' => new TwilioSmsClient(
				container: $this->container,
				logger: $this->logger,
				credentials: $credentials,
				fromNumber: $fromNumber,
				webhookSecret: $secret,
				sourceId: $sourceIdOrNull,
			),
			'messagebird' => new MessageBirdSmsClient(
				container: $this->container,
				logger: $this->logger,
				credentials: $credentials,
				fromNumber: $fromNumber,
				webhookSecret: $secret,
				sourceId: $sourceIdOrNull,
			),
			'cm-com' => new CmComSmsClient(
				container: $this->container,
				logger: $this->logger,
				credentials: $credentials,
				fromNumber: $fromNumber,
				webhookSecret: $secret,
				sourceId: $sourceIdOrNull,
			),
			default => null,
		};             
	}              
}           
