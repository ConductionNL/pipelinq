<?php

   
                                 
  
                                                                            
                                                                       
                                                                             
                                                                               
                                                 
                                                                   
                                                                           
                                                                           
                                                                          
                              
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Service\Marketing\Transport\ConnectorSourceTransport;
use OCA\Pipelinq\Service\Marketing\Transport\InstanceMailerTransport;
use OCA\Pipelinq\Service\Marketing\Transport\MailAccountTransport;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use OCA\Pipelinq\Service\Marketing\Transport\SendResult;
use OCA\Pipelinq\Service\UnsubscribeMail;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                         
  
                                                                                                                                             
  
                                                                                
                                                                            
                                                                              
                                                                                       
                                                                                  
                                                                                   
                                                                     
                                                                                   
                                                                          
                                                                           
                                                                                 
                                                                                  
                                                                                  
                                                                         
   
class MailTransportService {
	   
                                                                          
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                                                         
                            
    
	private const DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG = 'mailTransport';

	   
                                                                         
                            
    
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	   
                                                                        
                                            
    
	private const DEFAULT_RATE_LIMIT_PER_SECOND = 100;

	   
                                                                    
                                                          
   
                                                        
    
	private array $resolvedArticles = [];

	   
                
   
                                                      
                                                     
                                                                              
                                                                                     
                                                                                                     
                                          
                                                                                                        
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IMailer $mailer,
		private ArticleService $articleService,
		private ConnectorSourceRegister $connectorRegister,
		private LoggerInterface $logger,
		private PhysicalAddressRenderer $addressRenderer,
	) {
	}                   

	   
                                                                        
   
                                            
    
	private function unsubscribeMail(): ?UnsubscribeMail {
		try {
			$helper = $this->container->get(UnsubscribeMail::class);
		} catch (Throwable $e) {
			return null;
		}

		if ($helper instanceof UnsubscribeMail) {
			return $helper;
		}

		return null;
	}                       

	   
                                                      
   
                                                                        
                                                                     
                                                                  
                                                                       
                                                                       
              
   
                                                         
   
                                                                         
                                                                   
   
                                                                                                                                                                  
    
	public function resolveTransport(array $blast): ?array {
		$transportId = (string)($blast['transportId'] ?? '');
		if ($transportId !== '') {
			$named = $this->loadOne(id: $transportId);
			if ($named !== null && (bool)($named['active'] ?? true) === true) {
				return $named;
			}
		}

		$legacySourceId = (string)($blast['connectorSourceId'] ?? '');
		if ($transportId === '' && $legacySourceId !== '') {
			return $this->legacyProviderTransport(connectorSourceId: $legacySourceId);
		}

		return $this->loadDefaultTransport();
	}                        

	   
                                                                           
   
                                                                          
                                                                     
                                                                     
                      
   
                                                                      
                                                   
   
                                          
   
                                                                                                   
    
	public function resolveRateLimit(array $transport, int $callerRate): int {
		$candidate = self::DEFAULT_RATE_LIMIT_PER_SECOND;
		if ($callerRate > 0) {
			$candidate = $callerRate;
		}

		if ((string)($transport['kind'] ?? '') !== 'provider') {
			return max($candidate, 1);
		}

		$sourceRate = $this->readSourceRateLimit(connectorSourceId: (string)($transport['connectorSourceId'] ?? ''));
		if ($sourceRate !== null && $sourceRate > 0 && $sourceRate < $candidate) {
			return $sourceRate;
		}

		return max($candidate, 1);
	}                        

	   
                                                                        
                                                   
   
                                                                   
                                                               
                                                                        
   
                                                              
   
                                                                                                                                                      
    
	public function sendOneDelivery(array $delivery, array $template, array $transport): bool {
		$transport = $this->rollDailyLimitIfNewDay(transport: $transport);
		if ($this->underDailyLimit(transport: $transport) === false) {
			$this->logger->info(
				'MailTransportService.sendOneDelivery: transport at its daily limit',
				['transportId' => $this->extractId(payload: $transport)]
			);
			$this->markFailed(delivery: $delivery);
			return false;
		}

		$mail = $this->buildRenderedMail(template: $template, delivery: $delivery);
		$result = $this->dispatchToAdapter(mail: $mail, transport: $transport);
		if ($result->accepted === false) {
			$this->logger->warning(
				'MailTransportService.sendOneDelivery: transport rejected the delivery',
				['deliveryId' => $mail->deliveryId, 'reason' => $result->error]
			);
			$this->markFailed(delivery: $delivery);
			return false;
		}

		$this->markSent(delivery: $delivery, providerId: $result->providerId);
		$this->advanceSentToday(transport: $transport);
		return true;
	}                       

	   
                                                                                
   
                                                                            
                                                                        
                                                                           
                                                                          
                                                                       
                                                                        
                                                                          
                                                                  
                                                                          
                                                                   
                                                                   
              
   
                                                               
                                                            
   
                        
   
                                                                                                                                 
    
	private function buildRenderedMail(array $template, array $delivery): RenderedMail {
		$tokens = [
			'{{email}}' => (string)($delivery['email'] ?? ''),
			'{{contactId}}' => (string)($delivery['contactId'] ?? ''),
			                                                      
			                                                                
			                                                               
			                                                                   
			                                                              
			                                                    
			                                     
			'{{unsubscribe_link}}' => (string)($delivery['unsubscribeUrl'] ?? ''),
		];

		                                                                       
		                                                           
		$headers = [];
		$unsubscribeUrl = trim((string)($delivery['unsubscribeUrl'] ?? ''));
		if ($unsubscribeUrl !== '') {
			$headers = [
				InstanceMailerTransport::HEADER_UNSUBSCRIBE => '<'.$unsubscribeUrl.'>',
				InstanceMailerTransport::HEADER_UNSUBSCRIBE_POST => InstanceMailerTransport::ONE_CLICK,
			];
		}

		                                                                       
		                                                       
		$footer = (string)($template['footerOverride'] ?? '');
		$html = $this->addressRenderer->render(
			body: strtr($this->expandArticles(template: $template, format: ArticleService::FORMAT_HTML), $tokens),
			footerOverride: $footer,
			format: ArticleService::FORMAT_HTML,
		);
		$deliveryId = $this->extractId(payload: $delivery);
		if ($this->firstPartyTrackingEnabled() === true) {
			$html = $this->injectTrackingLinks(html: $html, blastDeliveryId: $deliveryId);
		}

		return new RenderedMail(
			fromEmail: (string)($template['senderEmail'] ?? ''),
			fromName: (string)($template['senderName'] ?? ''),
			replyTo: (string)($template['replyTo'] ?? ''),
			toEmail: (string)($delivery['email'] ?? ''),
			subject: strtr((string)($template['subject'] ?? ''), $tokens),
			html: $html,
			text: $this->addressRenderer->render(
				body: strtr($this->expandArticles(template: $template, format: ArticleService::FORMAT_TEXT), $tokens),
				footerOverride: $footer,
				format: ArticleService::FORMAT_TEXT,
			),
			headers: $headers,
			deliveryId: $deliveryId,
		);
	}                         

	   
                                                                  
   
                                                                       
                                                                        
                                                                        
                                                                     
   
                                                                      
                                                                       
                      
   
                                                           
                                           
   
                                                     
   
                                                                                                                                 
    
	private function expandArticles(array $template, string $format): string {
		$key = 'bodyText';
		if ($format === ArticleService::FORMAT_HTML) {
			$key = 'bodyHtml';
		}

		$body = (string)($template[$key] ?? '');
		if (str_contains($body, ArticleService::ARTICLES_MARKER) === false) {
			return $body;
		}

		return $this->articleService->expandArticlesMarker(
			body: $body,
			articles: $this->resolveTemplateArticles(template: $template),
			format: $format,
		);
	}                      

	   
                                                             
   
                                                           
   
                                                                   
   
                                                                                                                                 
    
	private function resolveTemplateArticles(array $template): array {
		$ids = ($template['articleIds'] ?? []);
		if (is_array($ids) === false) {
			$ids = [];
		}

		$key = implode('|', array_map('strval', array_filter($ids, 'is_scalar')));
		if (array_key_exists($key, $this->resolvedArticles) === false) {
			$this->resolvedArticles[$key] = $this->articleService->loadArticlesByIds(articleIds: $ids);
		}

		return $this->resolvedArticles[$key];
	}                               

	   
                                                        
   
                                                
                                                                  
   
                      
    
	private function dispatchToAdapter(RenderedMail $mail, array $transport): SendResult {
		$kind = (string)($transport['kind'] ?? '');
		try {
			$adapter = match ($kind) {
				'instance' => new InstanceMailerTransport(mailer: $this->mailer, logger: $this->logger, unsubscribeMail: $this->unsubscribeMail()),
				'mailAccount' => new MailAccountTransport(
					container: $this->container,
					logger: $this->logger,
					mailAccountRef: (string)($transport['mailAccountRef'] ?? ''),
					mailAccountUserId: (string)($transport['mailAccountUserId'] ?? ''),
				),
				'provider' => new ConnectorSourceTransport(
					container: $this->container,
					logger: $this->logger,
					connectorRegister: $this->connectorRegister,
					connectorSourceId: (string)($transport['connectorSourceId'] ?? ''),
					provider: (string)($transport['provider'] ?? ''),
				),
				default => null,
			};
		} catch (Throwable $e) {
			$this->logger->error(
				'MailTransportService.dispatchToAdapter: adapter construction failed',
				['kind' => $kind, 'exception' => $e->getMessage()]
			);
			return new SendResult(accepted: false, error: 'adapter-construction-failed');
		}

		if ($adapter === null) {
			$this->logger->error('MailTransportService.dispatchToAdapter: unknown transport kind', ['kind' => $kind]);
			return new SendResult(accepted: false, error: 'unknown-transport-kind');
		}

		return $adapter->send(mail: $mail);
	}                         

	   
                                                       
   
                
   
                                                                           
    
	private function firstPartyTrackingEnabled(): bool {
		return $this->appConfig->getValueString(
			Application::APP_ID,
			'blast.first_party_tracking',
			'false',
		) === 'true';
	}                                 

	   
                                                                     
                                                                         
   
                                                 
                                                              
   
                                                                  
   
                                                                           
    
	private function injectTrackingLinks(string $html, string $blastDeliveryId): string {
		if ($html === '' || $blastDeliveryId === '') {
			return $html;
		}

		try {
			$trackingLinkService = $this->container->get('OCA\\Pipelinq\\Service\\TrackingLinkService');
		} catch (Throwable $e) {
			$this->logger->info(
				'MailTransportService.injectTrackingLinks: TrackingLinkService unavailable',
				['exception' => $e->getMessage()]
			);
			return $html;
		}

		try {
			return $trackingLinkService->injectTracking(html: $html, blastDeliveryId: $blastDeliveryId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'MailTransportService.injectTrackingLinks: injection failed',
				['blastDeliveryId' => $blastDeliveryId, 'exception' => $e->getMessage()]
			);
			return $html;
		}
	}                           

	   
                                                                     
                                                                     
                                         
   
                                                                            
   
                                
    
	private function legacyProviderTransport(string $connectorSourceId): array {
		return [
			'kind' => 'provider',
			'provider' => 'sendgrid',
			'connectorSourceId' => $connectorSourceId,
			'dailyLimit' => 0,
			'sentToday' => 0,
			'active' => true,
		];
	}                               

	   
                                                     
   
                                     
    
	private function loadDefaultTransport(): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getMailTransportSchemaSlug(),
						'default' => true,
					],
					'limit' => 1,
				],
			);
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.loadDefaultTransport: findAll failed', ['exception' => $e->getMessage()]);
			return null;
		}

		foreach (($rows ?? []) as $row) {
			return $this->toArray(value: $row);
		}

		return null;
	}                            

	   
                                   
   
                                                 
   
                                     
    
	private function loadOne(string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$found = $objectService->find(
				id: $id,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
			);
		} catch (Throwable $e) {
			$this->logger->info('MailTransportService.loadOne: not found', ['id' => $id, 'exception' => $e->getMessage()]);
			return null;
		}

		if ($found === null) {
			return null;
		}

		return $this->toArray(value: $found);
	}               

	   
                                                        
   
                                                             
   
                                                                                 
    
	private function underDailyLimit(array $transport): bool {
		$dailyLimit = (int)($transport['dailyLimit'] ?? 0);
		if ($dailyLimit <= 0) {
			return true;
		}

		return (int)($transport['sentToday'] ?? 0) < $dailyLimit;
	}                       

	   
                                                                      
                                                                  
                                                    
   
                                                             
   
                                                                     
    
	private function rollDailyLimitIfNewDay(array $transport): array {
		$id = $this->extractId(payload: $transport);
		if ($id === '') {
			                                                                     
			return $transport;
		}

		$resetAt = (string)($transport['dailyLimitResetAt'] ?? '');
		$today = gmdate('Y-m-d');
		if ($resetAt !== '' && substr($resetAt, 0, 10) === $today) {
			return $transport;
		}

		$transport['sentToday'] = 0;
		$transport['dailyLimitResetAt'] = $this->nowIso();
		$this->saveObject(payload: $transport, id: $id);
		return $transport;
	}                              

	   
                                                                 
   
                                                             
   
                
    
	private function advanceSentToday(array $transport): void {
		$id = $this->extractId(payload: $transport);
		if ($id === '') {
			                                                     
			return;
		}

		$transport['sentToday'] = ((int)($transport['sentToday'] ?? 0) + 1);
		$this->saveObject(payload: $transport, id: $id);
	}                        

	   
                                                                      
   
                                                           
                                                                     
   
                
    
	private function markSent(array $delivery, ?string $providerId): void {
		$payload = $delivery;
		$payload['status'] = 'sent';
		$payload['sentAt'] = $this->nowIso();
		if ($providerId !== null && $providerId !== '') {
			$payload['providerId'] = $providerId;
		}

		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
	}                

	   
                                                                         
                                                                        
   
                                                           
   
                
    
	private function markFailed(array $delivery): void {
		$payload = $delivery;
		$payload['status'] = 'failed';
		$this->saveDelivery(payload: $payload, id: $this->extractId(payload: $delivery));
	}                  

	   
                                                                   
                                     
   
                                                   
   
                                                                                   
   
                                                                                
                                                                
                                                                                      
                                                    
    
	private function readSourceRateLimit(string $connectorSourceId): ?int {
		if ($connectorSourceId === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		                                                                         
		                                                                        
		                                                                            
		                                                                           
		                                                             
		                                                           
		$registerSlug = $this->connectorRegister->slugOrNull(
			operation: 'MailTransportService.readSourceRateLimit',
			sourceId: $connectorSourceId
		);
		if ($registerSlug === null) {
			return null;
		}

		try {
			$source = $objectService->find(
				id: $connectorSourceId,
				register: $registerSlug,
				schema: ConnectorSourceRegister::SOURCE_SCHEMA
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($source === null) {
			return null;
		}

		$sourceArray = $this->toArray(value: $source);
		$limitValue = ($sourceArray['rateLimitLimit'] ?? null);
		if ($limitValue === null || is_numeric($limitValue) === false) {
			return null;
		}

		$limit = (int)$limitValue;
		if ($limit <= 0) {
			return null;
		}

		$windowValue = ($sourceArray['rateLimitWindow'] ?? null);
		$window = 1;
		if ($windowValue !== null && is_numeric($windowValue) === true) {
			$window = (int)$windowValue;
		}

		if ($window <= 0) {
			$window = 1;
		}

		return max(1, (int)floor($limit / $window));
	}                           

	   
                                  
   
                                                 
                                   
   
                
    
	private function saveObject(array $payload, string $id): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getMailTransportSchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.saveObject: save failed', ['id' => $id, 'exception' => $e->getMessage()]);
		}
	}                  

	   
                                  
   
                                                 
                                   
   
                
    
	private function saveDelivery(array $payload, string $id): void {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getBlastDeliverySchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.saveDelivery: save failed', ['id' => $id, 'exception' => $e->getMessage()]);
		}
	}                    

	   
                                                                 
   
                       
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning('MailTransportService.getObjectService: unavailable', ['exception' => $e->getMessage()]);
			return null;
		}
	}                        

	   
                                            
   
                  
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                                               
   
                  
    
	private function getMailTransportSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'mailTransport_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG;
	}                                  

	   
                                               
   
                  
    
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blastDelivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}                                  

	   
                                                        
   
                                                     
   
                                                      
    
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			$value = ($payload[$key] ?? null);
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($self[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                 

	   
                                                                   
   
                                            
   
                                
    
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true) {
			if (method_exists($value, 'jsonSerialize') === true) {
				return (array)$value->jsonSerialize();
			}

			if (method_exists($value, 'getObject') === true) {
				return (array)$value->getObject();
			}
		}

		return [];
	}               

	   
                                  
   
                  
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
