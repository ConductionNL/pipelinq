<?php

   
                                        
  
                                                                  
                                                             
                                                                  
                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                
  
                       
                                                              
                                                              
                       
                                                                 
                                                     
  
                                                                   
                                                     
  
                                                                   
   
class TemplateApprovalSyncService {
	   
                          
    
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	   
                                        
    
	private const DEFAULT_SCHEMA_SLUG = 'messageTemplate';

	   
                
   
                                                      
                                            
                                                                      
                                                                   
                                                                        
                                          
   
                                                                    
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ChannelProviderRepository $providerRepo,
		private WhatsAppProviderClient $providerClient,
		private NotificationService $notificationService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                             
   
                                                                                     
   
                                                                    
    
	public function syncAll(): array {
		$providers = array_merge(
			$this->providerRepo->listActive(kind: 'whatsapp-cloud-api'),
			$this->providerRepo->listActive(kind: 'whatsapp-bsp'),
		);

		$totalUpdates = 0;
		$totalChanges = 0;
		foreach ($providers as $provider) {
			$summary = $this->syncOne(channelProvider: $provider);
			$totalUpdates += (int)$summary['templatesUpdated'];
			$totalChanges += (int)$summary['statusChanges'];
		}

		return [
			'providers' => count($providers),
			'templatesUpdated' => $totalUpdates,
			'statusChanges' => $totalChanges,
		];
	}               

	   
                                  
   
                                                              
   
                                                                     
   
                                                                    
    
	public function syncOne(array $channelProvider): array {
		$providerId = $this->extractId(payload: $channelProvider);
		if ($providerId === '') {
			return ['templatesUpdated' => 0, 'statusChanges' => 0];
		}

		try {
			$remoteTemplates = $this->providerClient->listTemplates(channelProvider: $channelProvider);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.syncOne: listTemplates failed',
				['providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return ['templatesUpdated' => 0, 'statusChanges' => 0];
		}

		$localIndex = $this->buildLocalIndex(providerId: $providerId);
		$updates = 0;
		$changes = 0;

		foreach ($remoteTemplates as $remote) {
			$externalId = (string)($remote['name'] ?? ($remote['externalId'] ?? ''));
			$language = (string)($remote['language'] ?? 'nl');
			if ($externalId === '') {
				continue;
			}

			$key = $externalId . ':' . $language;
			$local = ($localIndex[$key] ?? null);
			$newStatus = $this->normaliseStatus(remoteStatus: (string)($remote['status'] ?? 'pending'));

			$payload = $local ?? [
				'providerId' => $providerId,
				'externalId' => $externalId,
				'language' => $language,
				'category' => (string)($remote['category'] ?? 'utility'),
				'body' => (string)($remote['body'] ?? ''),
				'header' => (string)($remote['header'] ?? ''),
				'buttons' => $remote['buttons'] ?? [],
				'status' => 'pending',
			];

			$oldStatus = (string)($payload['status'] ?? 'pending');
			$payload['category'] = (string)($remote['category'] ?? ($payload['category'] ?? 'utility'));
			$payload['body'] = (string)($remote['body'] ?? ($payload['body'] ?? ''));
			$payload['header'] = (string)($remote['header'] ?? ($payload['header'] ?? ''));
			$payload['buttons'] = $remote['buttons'] ?? ($payload['buttons'] ?? []);
			$payload['status'] = $newStatus;
			$payload['lastSyncedAt'] = $this->nowIso();

			$id = null;
			if ($local !== null) {
				$id = $this->extractId(payload: $local);
			}

			$this->saveObject(payload: $payload, id: $id);
			$updates++;

			if ($oldStatus !== $newStatus) {
				$changes++;
				$this->notifyStatusChange(
					providerId: $providerId,
					externalId: $externalId,
					oldStatus: $oldStatus,
					newStatus: $newStatus,
				);
			}
		}             

		return ['templatesUpdated' => $updates, 'statusChanges' => $changes];
	}               

	   
                                                         
   
                                                                                  
   
                                                         
   
                                    
    
	private function normaliseStatus(string $remoteStatus): string {
		return match (strtolower($remoteStatus)) {
			'approved' => 'approved',
			'rejected', 'flagged' => 'rejected',
			'disabled', 'paused' => 'disabled',
			default => 'pending',
		};
	}                       

	   
                                                               
   
                                            
   
                                                      
    
	private function buildLocalIndex(string $providerId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'providerId' => $providerId,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.buildLocalIndex: findAll failed',
				['providerId' => $providerId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$index = [];
		foreach (($rows ?? []) as $row) {
			$arr = $this->toArray(value: $row);
			$externalId = (string)($arr['externalId'] ?? '');
			$language = (string)($arr['language'] ?? 'nl');
			if ($externalId === '') {
				continue;
			}

			$index[$externalId . ':' . $language] = $arr;
		}

		return $index;
	}                       

	   
                                                      
   
                                            
                                                   
                                             
                                        
   
                
    
	private function notifyStatusChange(
		string $providerId,
		string $externalId,
		string $oldStatus,
		string $newStatus,
	): void {
		try {
			$this->notificationService->sendNotification(
				userId: 'admin',
				subject: 'messaging_template_status_changed',
				parameters: [
					'providerId' => $providerId,
					'externalId' => $externalId,
					'oldStatus' => $oldStatus,
					'newStatus' => $newStatus,
				],
				objectType: 'messageTemplate',
				objectId: $externalId,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.notifyStatusChange: notification failed',
				['exception' => $e->getMessage()]
			);
		}
	}                          

	   
                           
   
                                                                        
                                                                  
   
                                                 
                                               
   
                                                
   
                                                                            
                                                                 
    
	private function saveObject(array $payload, ?string $id): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.saveObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}                  

	   
                                       
   
                                        
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'TemplateApprovalSyncService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
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

	   
                                              
   
                                                 
   
                               
    
	private function extractId(array $payload): string {
		$searchSpaces = [$payload];
		$self = ($payload['@self'] ?? null);
		if (is_array($self) === true) {
			$searchSpaces[] = $self;
		}

		foreach ($searchSpaces as $space) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($space[$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}                 

	   
                  
   
                        
    
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}                       

	   
                
   
                        
    
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'messageTemplate_schema',
			''
		);
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}                     

	   
                                   
   
                             
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              
}           
