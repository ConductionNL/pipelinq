<?php

   
                             
  
                                                                            
                                                                          
                                                                               
                                                                             
                                                                              
                                                                       
                                                                             
                                                                            
                                                                                
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Service\Integration\Providers\MessageDispatchProvider;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                       
  
                                                                                                   
  
                                                                             
                                                                       
                                                                          
                                                       
   
class MessagingService {
	   
                                                                       
   
               
    
	private const DISPATCH_LEAF_CLASS = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\MessageDispatchProvider';

	   
                                                                             
   
                              
    
	private const SOURCE_TEST_PATHS = [
		'messagebird-sms' => '/messages',
		'cmcom-sms' => '/v1.0/message',
		'twilio-sms' => '/2010-04-01/Accounts/test/Messages.json',
		'whatsapp-cloud-api' => '/messages',
		'whatsapp-bsp' => '/messages',
	];

	   
                
   
                                                                    
                                            
                                                                      
                                              
                                                             
                                                                 
                                          
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ChannelProviderRepository $providerRepo,
		private SmsAdapter $smsAdapter,
		private WhatsAppAdapter $whatsAppAdapter,
		private ConsentService $consentService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                                                          
   
                                                                           
                                                                             
                                                              
   
                                                    
   
                                                          
   
                                                                                                    
    
	public function loadContact(string $contactId): ?array {
		if ($contactId === '') {
			return null;
		}

		$register = $this->registerSlug();
		if ($register === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		$schemas = [
			$this->schemaSlug(key: 'contact_schema', default: 'contact'),
			$this->schemaSlug(key: 'client_schema', default: 'client'),
		];

		foreach ($schemas as $schema) {
			try {
				$entity = $objectService->find(id: $contactId, register: $register, schema: $schema);
			} catch (Throwable $e) {
				$entity = null;
			}

			if ($entity !== null) {
				return $this->toArray(value: $entity);
			}
		}

		return null;
	}                   

	   
                                                                  
   
                                                                  
   
                                                                                                                          
    
	public function availableChannels(): array {
		$sms = ($this->providerRepo->listActive(kind: 'sms') !== []);
		$cloud = $this->providerRepo->listActive(kind: 'whatsapp-cloud-api');
		$bsp = $this->providerRepo->listActive(kind: 'whatsapp-bsp');
		$whatsapp = ($cloud !== [] || $bsp !== []);

		return ['sms' => $sms, 'whatsapp' => $whatsapp];
	}                         

	   
                                             
   
                                                                                 
   
                  
                                                   
                                  
                                                      
                                                   
                      
   
                                                                                                    
    
	public function preflight(string $contactId): array {
		return [
			'channels' => $this->availableChannels(),
			'whatsappSessionOpen' => $this->whatsAppAdapter->isWithinSessionWindow(contactId: $contactId),
			'consent' => [
				'sms' => $this->consentService->latestState(contactId: $contactId, channel: 'sms'),
				'whatsapp' => $this->consentService->latestState(contactId: $contactId, channel: 'whatsapp'),
			],
			'templates' => $this->approvedTemplates(),
		];
	}                 

	   
                                                                       
   
                                                                       
                                               
                                                                  
                                                                             
                                                                         
                                                            
                                                
                                                                 
                                                                                                 
   
                                                                                         
   
                                                                                                    
    
	public function send(
		array $contact,
		string $channel,
		string $body,
		?string $templateId,
		array $parameters,
		?string $providerHint,
		string $actor,
		string $clientId = '',
		string $inReplyTo = '',
	): array {
		$client = $clientId;
		if ($client === '') {
			$client = (string)($contact['client'] ?? '');
		}

		$context = ['agent' => $actor, 'clientId' => $client];
		if ($inReplyTo !== '') {
			$context['inReplyTo'] = $inReplyTo;
		}

		if ($channel === 'sms') {
			$outcome = $this->smsAdapter->send(
				contact: $contact,
				body: $body,
				providerHint: $providerHint,
				context: $context
			);
			return $this->sanitiseOutcome(outcome: $outcome);
		}

		if ($channel === 'whatsapp') {
			$outcome = $this->whatsAppAdapter->send(
				contact: $contact,
				body: $body,
				templateId: $templateId,
				parameters: $parameters,
				context: $context
			);
			return $this->sanitiseOutcome(outcome: $outcome);
		}

		return ['status' => 'unsupported-channel'];
	}            

	   
                                                                           
   
                                                                            
                                                                       
                                                                 
   
                                                                  
   
                                                                            
   
                                                                                                               
    
	public function runProviderTest(array $provider): array {
		$source = (string)($provider['sourceId'] ?? '');
		if ($source === '') {
			return ['reachable' => false, 'cause' => 'no-source-configured'];
		}

		if (class_exists(self::DISPATCH_LEAF_CLASS) === false) {
			return ['reachable' => false, 'cause' => 'leaf-unavailable'];
		}

		try {
			$leaf = $this->container->get(self::DISPATCH_LEAF_CLASS);
		} catch (Throwable $e) {
			return ['reachable' => false, 'cause' => 'leaf-unavailable'];
		}

		                                                                                 
		                                                                         
		                                                                           
		                                                                         
		if (($leaf instanceof MessageDispatchProvider) === false) {
			return ['reachable' => false, 'cause' => 'leaf-unavailable'];
		}

		$path = (self::SOURCE_TEST_PATHS[$source] ?? '/messages');
		$body = ['validate' => true, 'recipients' => ['+31600000000'], 'body' => 'pipelinq connectivity test'];

		try {
			$result = $leaf->dispatch(source: $source, body: $body, path: $path, headers: []);
		} catch (Throwable $e) {
			$this->logger->warning('MessagingService.runProviderTest: dispatch threw', ['source' => $source, 'error' => $e->getMessage()]);
			return ['reachable' => false, 'cause' => 'dispatch-error'];
		}

		if (($result['unavailable'] ?? false) === true) {
			return ['reachable' => false, 'cause' => (string)($result['cause'] ?? 'unknown')];
		}

		$response = $result['response'] ?? [];
		return ['reachable' => true, 'mock' => $this->looksLikeMock(response: $response)];
	}                       

	   
                                                                
   
                                            
                                                             
   
                                                                  
   
                                                                                                            
    
	public function webhookUrls(string $providerId, string $kind): array {
		$urls = [];
		if ($kind === 'sms') {
			$urls['sms'] = '/apps/pipelinq/api/messaging-webhooks/sms/' . $providerId;
			return $urls;
		}

		$urls['whatsapp'] = '/apps/pipelinq/api/messaging-webhooks/whatsapp/' . $providerId;
		return $urls;
	}                   

	   
                                                                        
   
                                                     
   
                                                        
    
	private function looksLikeMock(mixed $response): bool {
		$encoded = json_encode($response);
		if (is_string($encoded) === false) {
			return false;
		}

		return (stripos($encoded, 'mock') !== false);
	}                     

	   
                                                                         
   
                                                                
    
	private function approvedTemplates(): array {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->registerSlug();
		if ($register === '') {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'status' => 'approved',
						'register' => $register,
						'schema' => $this->schemaSlug(key: 'messageTemplate_schema', default: 'messageTemplate'),
					],
				]
			);
		} catch (Throwable $e) {
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$arr = $this->toArray(value: $row);
			$out[] = [
				'id' => (string)($arr['uuid'] ?? ($arr['id'] ?? '')),
				'externalId' => (string)($arr['externalId'] ?? ''),
				'language' => (string)($arr['language'] ?? ''),
				'body' => (string)($arr['body'] ?? ''),
			];
		}

		return $out;
	}                         

	   
                                                                           
   
                                                             
   
                                                                                 
    
	private function sanitiseOutcome(array $outcome): array {
		$status = (string)($outcome['status'] ?? '');

		if ($status === 'sent') {
			return ['status' => 'sent', 'messageId' => (string)($outcome['messageId'] ?? '')];
		}

		if ($status === 'consentMissing') {
			return ['status' => 'consent-missing'];
		}

		if ($status === 'budgetExceeded') {
			return ['status' => 'budget-exceeded'];
		}

		if ($status === 'sessionWindowExpired') {
			return ['status' => 'template-required'];
		}

		if ($status === 'noProviderAvailable') {
			return ['status' => 'no-provider'];
		}

		if (in_array($status, ['templateNotFound', 'templateNotApproved', 'templateParameterMismatch'], true) === true) {
			return ['status' => 'template-invalid', 'reason' => $status];
		}

		return ['status' => 'failed'];
	}                       

	   
                                                  
   
                                        
    
	private function objectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}
	}                     

	   
                                            
   
                                        
   
                                               
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}               

	   
                                                        
   
                                                                    
                                                                       
                                                                           
                                                                         
                                                                        
                                                                            
                                                                         
                                                                    
                                                       
   
                                     
    
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'pipelinq';
	}                    

	   
                                              
   
                                      
                                            
   
                        
    
	private function schemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}                  
}           
