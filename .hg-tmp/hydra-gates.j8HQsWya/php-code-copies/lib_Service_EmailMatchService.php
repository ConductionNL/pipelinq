<?php

   
                              
  
                                                                              
                                                                      
                                                                              
                                                                            
                                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                            
  
                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                        
  
                                                               
                                                                      
                                                                      
                                                              
                                                               
  
                                                                              
                                                                       
                                                                                  
                                                                                   
                                   
                                                                                   
                                                                                   
  
                                                   
   
class EmailMatchService {

	   
                                                             
                                                                       
                                                                     
                                                             
   
                          
    
	private const PUBLIC_DOMAINS = [
		'gmail.com',
		'googlemail.com',
		'outlook.com',
		'hotmail.com',
		'live.com',
		'msn.com',
		'yahoo.com',
		'ymail.com',
		'icloud.com',
		'me.com',
		'mac.com',
		'aol.com',
		'protonmail.com',
		'proton.me',
		'gmx.com',
		'gmx.net',
		'mail.com',
		'zoho.com',
		'yandex.com',
		'yandex.ru',
	];

	   
                                                                    
   
               
    
	public const SETTINGS_KEY = 'email_match_settings';

	   
                                                                   
   
               
    
	public const STATUS_KEY = 'email_match_status';

	   
                
   
                                                                        
                                                   
                                                                 
                                          
    
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {

	}                   

	   
                                           
   
                                                                      
                                                                        
                                                                     
   
                                                                         
   
                                                               
   
                                                    
    
	public function matchEmailToEntities(string $address): array {
		$address = strtolower(trim($address));
		if ($address === '') {
			return [];
		}

		$matches = [];

		$contacts = $this->findObjectsByEmail(schemaKey: 'contact_schema', email: $address);
		foreach ($contacts as $row) {
			$matches[] = ['entityType' => 'contact', 'entityId' => $row];
		}

		$clients = $this->findObjectsByEmail(schemaKey: 'client_schema', email: $address);
		foreach ($clients as $row) {
			$matches[] = ['entityType' => 'client', 'entityId' => $row];
		}

		return $matches;
	}                            

	   
                                                             
   
                                                                
                                                                   
                                                                      
   
                                                        
   
                                                         
   
                                                    
   
                                                                                    
                                                               
                                                                                           
                                                
    
	public function matchDomainToOrganization(string $domain): ?array {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return null;
		}

		if ($this->isPublicDomain(domain: $domain) === true) {
			return null;
		}

		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: 'client_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$service = $this->getObjectService();
			if ($service === null) {
				return null;
			}

			$rows = $service->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'type' => 'organization',
					],
					'limit' => 1000,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: matchDomainToOrganization lookup failed',
				['domain' => $domain]
			);
			return null;
		}         

		if (is_array($rows) === false) {
			return null;
		}

		$suffix = '@' . $domain;
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			$email = strtolower((string)($data['email'] ?? ''));
			if ($email === '') {
				continue;
			}

			if (str_ends_with($email, $suffix) === true) {
				$id = $this->idOf(object: $data);
				if ($id !== '') {
					return ['entityType' => 'client', 'entityId' => $id];
				}
			}
		}

		return null;
	}                                 

	   
                                                           
   
                                                        
   
                
   
                                                    
    
	public function isPublicDomain(string $domain): bool {
		$domain = strtolower(trim($domain));
		if ($domain === '') {
			return false;
		}

		return in_array($domain, self::PUBLIC_DOMAINS, true);
	}                      

	   
                                                                      
   
                                                                      
                                                                       
                                                                 
                                                                     
   
                                                  
                                                  
                                          
   
                                                         
   
                                                    
   
                                                                                   
                                                                       
                                                                                           
                                                    
    
	public function matchAndLinkMessage(int $mailAccountId, int $mailMessageId, string $userId): int {
		$message = $this->fetchMailMessageMeta(messageId: $mailMessageId, accountId: $mailAccountId);
		if ($message === null) {
			return 0;
		}

		$addresses = $this->extractAddresses(message: $message);
		if ($addresses === []) {
			return 0;
		}

		$exclude = $this->getExcludedAddresses(userId: $userId);
		$entities = [];
		foreach ($addresses as $address) {
			if (in_array($address, $exclude, true) === true) {
				continue;
			}

			foreach ($this->matchEmailToEntities(address: $address) as $hit) {
				$key = $hit['entityType'] . ':' . $hit['entityId'];
				$entities[$key] = $hit;
			}

			$domain = $this->extractDomain(email: $address);
			if ($domain === null) {
				continue;
			}

			$org = $this->matchDomainToOrganization(domain: $domain);
			if ($org !== null) {
				$key = $org['entityType'] . ':' . $org['entityId'];
				$entities[$key] = $org;
			}
		}             

		if ($entities === []) {
			return 0;
		}

		$linkService = $this->getEmailLinkService();
		if ($linkService === null) {
			return 0;
		}

		$register = $this->registerSlug();

		                                                                       
		                                                                        
		                                                          
		if ($register === '') {
			return 0;
		}

		$createdCount = 0;
		foreach ($entities as $entity) {
			$schemaSlug = $entity['entityType'] . '_schema';
			$schema = $this->schemaSlug(key: $schemaSlug);
			if ($schema === '') {
				continue;
			}

			try {
				$before = $this->existingLinkId(
					linkService: $linkService,
					objectUuid: $entity['entityId'],
					mailAccountId: $mailAccountId,
					mailMessageId: $mailMessageId
				);

				$linkService->linkEmail(
					objectUuid: $entity['entityId'],
					registerId: (int)$register,
					schemaId: (int)$schema,
					mailAccountId: $mailAccountId,
					messageId: (string)$mailMessageId,
					messageUid: (string)$message['uid']
				);

				if ($before === null) {
					$createdCount++;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: email leaf linkEmail failed',
					[
						'entityType' => $entity['entityType'],
						'entityId' => $entity['entityId'],
						'messageId' => $mailMessageId,
					]
				);
			}         
		}             

		return $createdCount;
	}                           

	   
                                      
   
                                                                        
                                                                       
                                                                      
                                                                     
            
   
                                                 
   
                                                             
   
                                                    
    
	public function runForUser(string $userId): array {
		$settings = $this->getSettings(userId: $userId);
		if ($settings['enabled'] !== true || $settings['account'] <= 0) {
			return ['linked' => 0, 'scanned' => 0];
		}

		$scanned = 0;
		$linked = 0;
		$lastId = (int)$settings['cursor'];
		$maxId = $lastId;

		$messages = $this->listInboundMessages(accountId: $settings['account'], sinceId: $lastId);
		foreach ($messages as $msg) {
			$scanned++;
			try {
				$linked += $this->matchAndLinkMessage(
					mailAccountId: $settings['account'],
					mailMessageId: $msg['id'],
					userId: $userId
				);
				if ($msg['id'] > $maxId) {
					$maxId = $msg['id'];
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'Pipelinq: email match per-message failure',
					['messageId' => $msg['id']]
				);
			}
		}

		$this->writeStatus(userId: $userId, linked: $linked, scanned: $scanned, error: null);
		if ($maxId > $lastId) {
			$settings['cursor'] = $maxId;
			$this->writeSettings(userId: $userId, settings: $settings);
		}

		return ['linked' => $linked, 'scanned' => $scanned];
	}                  

	   
                                          
   
                                      
   
                                                                                          
   
                                                    
    
	public function getSettings(string $userId): array {
		$json = $this->appConfig->getValueString(
			Application::APP_ID,
			$this->settingsKeyFor(userId: $userId),
			''
		);

		if ($json === '') {
			return [
				'account' => 0,
				'enabled' => false,
				'excludedAddresses' => [],
				'cursor' => 0,
			];
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return [
				'account' => 0,
				'enabled' => false,
				'excludedAddresses' => [],
				'cursor' => 0,
			];
		}

		return [
			'account' => (int)($decoded['account'] ?? 0),
			'enabled' => (bool)($decoded['enabled'] ?? false),
			'excludedAddresses' => $this->sanitiseAddresses(items: $decoded['excludedAddresses'] ?? []),
			'cursor' => (int)($decoded['cursor'] ?? 0),
		];

	}                   

	   
                                             
   
                                      
                                                                                                                      
   
                
   
                                                    
    
	public function writeSettings(string $userId, array $settings): void {
		$payload = [
			'account' => (int)$settings['account'],
			'enabled' => (bool)$settings['enabled'],
			'excludedAddresses' => $this->sanitiseAddresses(items: $settings['excludedAddresses']),
			'cursor' => (int)($settings['cursor'] ?? 0),
		];

		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->settingsKeyFor(userId: $userId),
			json_encode($payload, JSON_UNESCAPED_UNICODE)
		);

	}                     

	   
                                        
   
                                      
   
                                                                         
   
                                                    
    
	public function getStatus(string $userId): array {
		$json = $this->appConfig->getValueString(
			Application::APP_ID,
			$this->statusKeyFor(userId: $userId),
			''
		);

		if ($json === '') {
			return ['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null];
		}

		$decoded = json_decode($json, true);
		if (is_array($decoded) === false) {
			return ['lastRunAt' => null, 'linked' => 0, 'scanned' => 0, 'error' => null];
		}

		$lastRunAt = null;
		if (isset($decoded['lastRunAt']) === true) {
			$lastRunAt = (string)$decoded['lastRunAt'];
		}

		$error = null;
		if (isset($decoded['error']) === true) {
			$error = (string)$decoded['error'];
		}

		return [
			'lastRunAt' => $lastRunAt,
			'linked' => (int)($decoded['linked'] ?? 0),
			'scanned' => (int)($decoded['scanned'] ?? 0),
			'error' => $error,
		];

	}                 

	   
                                           
   
                                      
                                                
                                                   
                                                            
   
                
   
                                                    
    
	public function writeStatus(string $userId, int $linked, int $scanned, ?string $error = null): void {
		$payload = [
			'lastRunAt' => gmdate('c'),
			'linked' => $linked,
			'scanned' => $scanned,
			'error' => $error,
		];

		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->statusKeyFor(userId: $userId),
			json_encode($payload, JSON_UNESCAPED_UNICODE)
		);

	}                   

	   
                                                                    
   
                                                         
   
                                                           
    
	private function extractAddresses(array $message): array {
		$addresses = [];
		if (isset($message['sender']) === true && is_string($message['sender']) === true) {
			$addresses[] = strtolower(trim($message['sender']));
		}

		if (isset($message['recipients']) === true && is_array($message['recipients']) === true) {
			foreach ($message['recipients'] as $address) {
				if (is_string($address) === true && trim($address) !== '') {
					$addresses[] = strtolower(trim($address));
				}
			}
		}

		$addresses = array_values(array_unique(array_filter($addresses)));
		return $addresses;
	}                        

	   
                                                  
   
                                       
   
                                                              
    
	private function extractDomain(string $email): ?string {
		$parts = explode('@', $email);
		if (count($parts) !== 2 || $parts[1] === '') {
			return null;
		}

		return strtolower(trim($parts[1]));
	}                     

	   
                                               
   
                                  
   
                             
    
	private function sanitiseAddresses(mixed $items): array {
		if (is_string($items) === true) {
			$split = preg_split('/[\\s,]+/', $items);
			if ($split === false) {
				$split = [];
			}

			$items = $split;
		}

		if (is_array($items) === false) {
			return [];
		}

		$out = [];
		foreach ($items as $entry) {
			if (is_string($entry) === false) {
				continue;
			}

			$clean = strtolower(trim($entry));
			if ($clean !== '' && filter_var($clean, FILTER_VALIDATE_EMAIL) !== false) {
				$out[] = $clean;
			}
		}

		return array_values(array_unique($out));
	}                         

	   
                                                  
   
                                  
   
                             
    
	private function getExcludedAddresses(string $userId): array {
		return $this->getSettings(userId: $userId)['excludedAddresses'];
	}                            

	   
                                                           
   
                                                                                    
                                                   
   
                                                 
   
                                                                                  
                                                                     
    
	private function findObjectsByEmail(string $schemaKey, string $email): array {
		$register = $this->registerSlug();
		$schema = $this->schemaSlug(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$service = $this->getObjectService();
			if ($service === null) {
				return [];
			}

			$rows = $service->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'email' => $email,
					],
					'limit' => 100,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: findObjectsByEmail failed',
				['schemaKey' => $schemaKey]
			);
			return [];
		}         

		if (is_array($rows) === false) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$data = $this->toArray(object: $row);
			if ($data === null) {
				continue;
			}

			$rowEmail = strtolower((string)($data['email'] ?? ''));
			if ($rowEmail !== $email) {
				continue;
			}

			$id = $this->idOf(object: $data);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}                          

	   
                                                              
   
                       
    
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

	}                        

	   
                                                                 
   
                       
    
	private function getEmailLinkService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\EmailLinkService');
		} catch (Throwable $e) {
			return null;
		}

	}                           

	   
                                                                         
                                                                             
   
                                                                         
                                                                          
                                                                         
                                           
   
                                                            
                                                    
                                              
                                              
   
                                               
   
                                                                                   
                                                                                
    
	private function existingLinkId(
		object $linkService,
		string $objectUuid,
		int $mailAccountId,
		int $mailMessageId,
	): ?int {
		if (method_exists($linkService, 'getLinkedEmails') === false) {
			return null;
		}

		try {
			$page = $linkService->getLinkedEmails($objectUuid, null, 100);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($page) === false) {
			return null;
		}

		$items = [];
		if (isset($page['items']) === true && is_array($page['items']) === true) {
			$items = $page['items'];
		}

		foreach ($items as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$accId = (int)($row['mailAccountId'] ?? 0);
			$msgId = (int)($row['mailMessageId'] ?? 0);
			if ($accId === $mailAccountId && $msgId === $mailMessageId) {
				return (int)($row['id'] ?? 0);
			}
		}

		return null;
	}                      

	   
                                                         
   
                                                               
   
                                          
                                          
   
                                                                                                           
   
                                                                                 
                                                      
                                                                                         
                                                  
    
	private function fetchMailMessageMeta(int $messageId, int $accountId): ?array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id', 'm.uid', 'm.subject', 'm.sent_at', 'mb.account_id')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('m.id', $qb->createNamedParameter($messageId)))
				->andWhere($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			return null;
		}

		if ($row === false || is_array($row) === false) {
			return null;
		}

		$recipients = [];
		$sender = null;
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('email', 'type')
				->from('mail_recipients')
				->where($qb->expr()->eq('message_id', $qb->createNamedParameter($messageId)));

			$result = $qb->executeQuery();
			while (($rcpt = $result->fetch()) !== false) {
				$email = strtolower(trim((string)($rcpt['email'] ?? '')));
				if ($email === '') {
					continue;
				}

				$type = (int)($rcpt['type'] ?? -1);
				if ($type === 0) {
					$sender = $email;
					continue;
				}

				$recipients[] = $email;
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			                                            
		}         

		$sentAt = null;
		                                                                     
		                            
		if (isset($row['sent_at']) === true) {
			$sentAt = date('c', (int)$row['sent_at']);
		}

		$subject = null;
		if (isset($row['subject']) === true) {
			$subject = (string)$row['subject'];
		}

		return [
			'uid' => (string)($row['uid'] ?? ''),
			'subject' => $subject,
			'sender' => $sender,
			'recipients' => array_values(array_unique($recipients)),
			'date' => $sentAt,
		];

	}                            

	   
                                                                     
   
                                                               
                                                                    
                                                               
   
                                          
                                                                       
   
                                    
    
	private function listInboundMessages(int $accountId, int $sinceId): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId)))
				->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($sinceId)))
				->orderBy('m.id', 'ASC')
				->setMaxResults(200);

			$result = $qb->executeQuery();
			$rows = [];
			while (($row = $result->fetch()) !== false) {
				$rows[] = ['id' => (int)($row['id'] ?? 0)];
			}

			$result->closeCursor();
			return $rows;
		} catch (Throwable $e) {
			$this->logger->warning('Pipelinq: listInboundMessages failed');
			return [];
		}         

	}                           

	   
                                                
   
                                                   
   
                  
    
	private function idOf(array $object): string {
		if (isset($object['@self']) === true && is_array($object['@self']) === true) {
			$self = $object['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($object['id']) === true) {
			return (string)$object['id'];
		}

		if (isset($object['uuid']) === true) {
			return (string)$object['uuid'];
		}

		return '';
	}            

	   
                                                     
   
                                                
   
                                    
   
                                                                                 
                                                                             
    
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$payload = $object->getObject();
				if (is_array($payload) === true) {
					return $payload;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}        

		return null;
	}               

	   
                                       
   
                                                                       
                                                                      
                                                                           
                                                                            
                                                                           
                                                                      
   
                                                                         
    
	private function registerSlug(): string {
		$registerSlug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerSlug === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerSlug;
	}                    

	   
                                            
   
                                      
   
                  
    
	private function schemaSlug(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}                  

	   
                                                  
   
                                      
   
                  
    
	private function settingsKeyFor(string $userId): string {
		return self::SETTINGS_KEY . '.' . $userId;
	}                      

	   
                                                
   
                                      
   
                  
    
	private function statusKeyFor(string $userId): string {
		return self::STATUS_KEY . '.' . $userId;
	}                    
}           
