<?php

   
                                   
  
                                                                          
                                                                  
                                                                     
                                                                          
                                                                             
                                                              
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Provider\MessageDispatchTrait;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                             
  
                       
                                                             
                                                    
                                                                  
                                                              
                           
                                                               
                                            
                                                                 
                      
                                                                  
                        
                                                           
                                      
  
                                                                   
                                 
  
                                                                   
   
class WhatsAppProviderClient {
	use MessageDispatchTrait;

	   
                                                                        
                                                              
                                                             
                        
   
                              
    
	private const ACTION_PATHS = [
		'send-template' => 'messages',
		'send-text' => 'messages',
		'download-media' => 'media',
		'upload-media' => 'media',
		'list-templates' => 'message_templates',
	];

	   
                
   
                                                      
                                          
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                          
   
                                                              
                                               
                                                     
                                                      
                                                                         
   
                                                                         
   
                                                                   
                                                                  
                                                              
    
	public function sendTemplate(
		array $channelProvider,
		string $phoneNumber,
		string $templateName,
		string $language,
		array $parameters,
	): array {
		$payload = [
			'messaging_product' => 'whatsapp',
			'to' => $phoneNumber,
			'type' => 'template',
			'template' => [
				'name' => $templateName,
				'language' => ['code' => $language],
				'components' => [
					[
						'type' => 'body',
						'parameters' => array_values(
							array_map(
								static function (string $value): array {
									return ['type' => 'text', 'text' => $value];
								},
								$parameters
							)
						),
					],
				],
			],
		];

		$result = $this->dispatch(
			channelProvider: $channelProvider,
			action: 'send-template',
			payload: $payload,
		);

		return [
			'externalMessageId' => $this->extractWamid(result: $result),
			'vendor' => (string)($channelProvider['vendor'] ?? ''),
		];
	}                    

	   
                                                       
   
                                                              
                                               
                                     
                                                                    
   
                                                                         
   
                                                       
                                                       
                                                              
    
	public function sendFreeForm(
		array $channelProvider,
		string $phoneNumber,
		string $body,
		array $mediaIds = [],
	): array {
		$payload = [
			'messaging_product' => 'whatsapp',
			'to' => $phoneNumber,
			'type' => 'text',
			'text' => ['body' => $body],
		];

		if ($mediaIds !== []) {
			$payload['type'] = 'image';
			$payload['image'] = ['id' => $mediaIds[0]];
		}

		$result = $this->dispatch(
			channelProvider: $channelProvider,
			action: 'send-text',
			payload: $payload,
		);

		return [
			'externalMessageId' => $this->extractWamid(result: $result),
			'vendor' => (string)($channelProvider['vendor'] ?? ''),
		];
	}                    

	  
                           
   
                                                                              
                                                                            
                                                                             
                                                                           
                                                                        
                                                                 
                                             
    

	   
                                                    
   
                                                              
   
                                                           
   
                                                       
                                                       
                                                              
    
	public function listTemplates(array $channelProvider): array {
		$result = $this->dispatch(
			channelProvider: $channelProvider,
			action: 'list-templates',
			payload: [],
		);

		if (isset($result['data']) === true && is_array($result['data']) === true) {
			return $result['data'];
		}

		return [];
	}                     

	   
                                             
   
                                                                                      
                                    
                                                       
   
                                     
                                                              
    
	public function verifySignature(array $channelProvider, string $rawBody, string $signature): bool {
		$secret = (string)($channelProvider['webhookSecret'] ?? '');
		if ($secret === '' || $signature === '') {
			return false;
		}

		$compare = $signature;
		if (str_starts_with($signature, 'sha256=') === true) {
			$compare = substr($signature, 7);
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $compare);
	}                       

	   
                                                                      
   
                                                                          
                                                                         
                                                                           
                                                                       
   
                                                              
                                      
                                                 
   
                                                                
   
                                                       
                                                       
    
	private function dispatch(array $channelProvider, string $action, array $payload): array {
		$sourceId = (string)($channelProvider['sourceId'] ?? ($channelProvider['openconnectorSourceId'] ?? ''));
		if ($sourceId === '') {
			throw new PermanentSmsProviderException(message: 'WhatsApp provider source not configured');
		}

		$path = self::ACTION_PATHS[$action] ?? 'messages';

		return $this->dispatchViaLeaf(
			source: $sourceId,
			body: $payload,
			path: $path,
		);
	}                

	   
                                              
   
                                                          
   
                                  
    
	private function extractWamid(array $result): string {
		if (isset($result['messages']) === true && is_array($result['messages']) === true) {
			$first = $result['messages'][0] ?? null;
			if (is_array($first) === true && isset($first['id']) === true) {
				return (string)$first['id'];
			}
		}

		if (isset($result['externalMessageId']) === true) {
			return (string)$result['externalMessageId'];
		}

		return (string)($result['id'] ?? '');
	}                    
}           
