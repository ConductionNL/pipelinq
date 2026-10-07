<?php

   
                                 
  
                                                                                       
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                             
                                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                 
  
                                                                              
                                                                               
  
                                                                      
                                                                               
                                                                               
                                                                       
  
                                                                                                                   
                                                                                                                               
   
class ContactmomentService {
	use EntityAccessorTrait;

	   
                
   
                                                                    
                                                         
                                              
                                                                                         
                                                                     
    
	public function __construct(
		private TicketService $ticketService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private IL10N $l10n,
	) {
	}                   

	   
                                       
   
                                                                                 
   
                                                               
                                                                                
    
	public function getObjectService(): \OCA\OpenRegister\Contract\ObjectServiceInterface {
		                                                                 
		                                                              
		return $this->objectService;
	}                        

	   
                                                               
   
                                                                          
                                                                  
   
                                                                                
   
                                                          
   
                                                                                         
                                                                                                                                
    
	public function getConfig(): array {
		if ($this->ticketService->isConfigured() === false) {
			throw new RuntimeException('Contactmoment register or schema not configured.');
		}

		return [
			'register' => $this->ticketService->getRegisterId(),
			'schema' => $this->ticketService->getSchemaId(),
		];
	}                 

	   
                                                                 
   
                                                                   
                                                                        
                                                                         
                                              
                                                                           
                                                                           
                                                                       
                                       
   
                                                                             
                                                                           
                                                           
   
                                                      
                                                       
                                                       
                                                                  
                                                                      
                                                                           
                                                            
                                                         
                                        
                                                 
                                                    
   
                                                                                   
   
                                                                                                                    
                                                                                                                                
    
	public function recordOutboundMessage(
		string $channel,
		string $subject,
		string $summary,
		array $channelMetadata,
		string $clientId = '',
		string $agent = '',
	): ?string {
		try {
			$config = $this->getConfig();
		} catch (\Throwable $e) {
			$this->logger->info(
				'ContactmomentService.recordOutboundMessage: audit skipped (ticket schema unconfigured)',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		$payload = [
			'ticketType' => TicketService::TYPE_CONTACTMOMENT,
			'title' => $subject,
			'description' => $summary,
			'channel' => $channel,
			                                                                
			                                                            
			                                                                 
			                   
			'direction' => 'outbound',
			'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'channelMetadata' => $channelMetadata,
		];
		if ($clientId !== '') {
			$payload['client'] = $clientId;
		}

		if ($agent !== '') {
			$payload['assignee'] = $agent;
		}

		return $this->saveTicket(payload: $payload, config: $config);
	}                             

	   
                                                                        
                                     
   
                                                                        
                                                                      
                                                                         
                                                                         
                                                                        
                                                                    
                                                                            
                      
   
                                                
                                         
                                     
                                                                      
   
                                                                                    
   
                                                                                                       
    
	public function recordInboundFromUnknownNumber(
		string $platform,
		string $phone,
		string $body,
		string $messageId,
	): ?string {
		try {
			$config = $this->getConfig();
		} catch (\Throwable $e) {
			$this->logger->info(
				'ContactmomentService.recordInboundFromUnknownNumber: skipped (ticket schema unconfigured)',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		$channel = 'sms';
		$title = $this->l10n->t('SMS from unknown number %s', [$phone]);
		if ($platform === 'whatsapp') {
			$channel = 'chat';
			$title = $this->l10n->t('WhatsApp message from unknown number %s', [$phone]);
		}

		$payload = [
			'ticketType' => TicketService::TYPE_CONTACTMOMENT,
			'title' => $title,
			'description' => $body,
			'channel' => $channel,
			'direction' => 'inbound',
			'status' => 'new',
			'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'channelMetadata' => [
				'platform' => $platform,
				'direction' => 'inbound',
				'from' => $phone,
				'messageId' => $messageId,
			],
		];

		return $this->saveTicket(payload: $payload, config: $config);
	}                                      

	   
                                                    
   
                                                                          
                                                                           
                                                                        
                             
   
                                                       
                                                                                   
   
                                                                      
    
	private function saveTicket(array $payload, array $config): ?string {
		try {
			$saved = $this->objectService->saveObject(
				object: $payload,
				register: $config['register'],
				schema: $config['schema'],
				uuid: null,
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ContactmomentService: contact moment save failed',
				['channel' => (string)($payload['channel'] ?? ''), 'exception' => $e->getMessage()]
			);
			return null;
		}

		                                                                     
		                                                                    
		                        
		$uuid = $this->readEntityValue(entity: $saved, getter: 'getUuid');
		if ($uuid !== '') {
			return $uuid;
		}

		return null;
	}                  

	   
                                                    
   
                                                                            
                                                                    
                                                                             
                                  
   
                                                    
                                                                        
   
                                              
   
                                                             
                                                           
   
                                                                                              
                                                                                                                                
    
	public function delete(string $id, string $currentUserId): bool {
		$objectService = $this->getObjectService();
		$config = $this->getConfig();

		                                       
		$object = $objectService->find(
			id: $id,
			register: $config['register'],
			schema: $config['schema']
		);

		if ($object === null) {
			throw new DoesNotExistException(
				'Contactmoment not found: ' . $id
			);
		}

		$data = $this->entityToArray(entity: $object);

		if (($data['ticketType'] ?? '') !== TicketService::TYPE_CONTACTMOMENT) {
			throw new DoesNotExistException(
				'Contactmoment not found: ' . $id
			);
		}

		                                                                     
		                                                                        
		                                                                           
		                                                                         
		                  
		                                                                          
		                                                                     
		                                                            
		                                                                      
		                                                                                 
		$payload = $object->getObject();
		$createdBy = (string)($payload['createdBy'] ?? '');

		$isCreator = ($createdBy !== '' && $createdBy === $currentUserId);
		$isAdmin = $this->groupManager->isAdmin($currentUserId);

		if ($isCreator === false && $isAdmin === false) {
			throw new NotPermittedException(
				'Only the creating agent or an admin can delete this contactmoment.'
			);
		}

		$objectService
			->setRegister($config['register'])
			->setSchema($config['schema'])
			->deleteObject(uuid: $id);

		$this->logger->info(
			'Contactmoment deleted',
			[
				'id' => $id,
				'userId' => $currentUserId,
			]
		);

		return true;
	}              

	   
                                                                           
   
                                                                      
   
                                                                                  
    
	private function entityToArray(mixed $entity): array {
		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$data = $entity->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}                     
}           
