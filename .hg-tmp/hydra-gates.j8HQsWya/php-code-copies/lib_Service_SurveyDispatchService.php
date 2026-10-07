<?php

   
                                  
  
                                                                           
                                                 
  
                                                                              
                                                                              
                                                                      
                                                                              
           
  
                                                                           
                                                                               
                                           
  
                                                                              
                                                                               
                                                                              
          
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                                   
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                  
  
                                                                                                                                                 
   
class SurveyDispatchService {
	   
                                                                   
   
               
    
	public const RULES_KEY = 'survey_dispatch_rules';

	   
                                                                
   
            
    
	public const DEFAULT_COOLDOWN_DAYS = 30;

	   
                                        
   
            
    
	public const DEFAULT_EXPIRY_DAYS = 30;

	   
                                                                 
   
            
    
	private const LIMIT = 500;

	   
                
   
                                                                                  
                                                                               
                                                                    
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                  
   
                                                                                       
   
                                                                                                                                               
    
	public function rules(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::RULES_KEY, '[]');

		try {
			$rules = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyDispatchService: the dispatch rules are not valid JSON; no survey will be sent',
				['exception' => $e->getMessage()]
			);

			return [];
		}

		if (is_array($rules) === false) {
			return [];
		}

		return $rules;
	}             

	   
                                                 
   
                                                                           
                                                               
   
                                                          
                                                
                                                                                  
   
                                                                
   
                                                                                                                                               
    
	public function matchingRules(string $entityType, string $status, string $channel = ''): array {
		$matching = [];

		foreach ($this->rules() as $rule) {
			if (($rule['enabled'] ?? false) !== true) {
				continue;
			}

			$trigger = ($rule['trigger'] ?? []);
			if (is_array($trigger) === false) {
				continue;
			}

			if (trim((string)($trigger['entityType'] ?? '')) !== $entityType) {
				continue;
			}

			if (trim((string)($trigger['statusEquals'] ?? '')) !== $status) {
				continue;
			}

			$channelFilter = trim((string)($trigger['channelEquals'] ?? ''));
			if ($channelFilter !== '' && $channelFilter !== $channel) {
				continue;
			}

			$matching[] = $rule;
		}

		return $matching;
	}                     

	   
                                                              
   
                                                                         
                                                                          
                                                    
   
                                                        
                                                       
                                                                               
                                                        
                                                              
   
                                                                            
   
                                                                                                                                                  
    
	public function suppressionReason(
		array $rule,
		array $contact,
		array $recentInvitations = [],
		?DateTimeImmutable $now = null,
	): ?string {
		$now = ($now ?? new DateTimeImmutable());

		if (trim((string)($contact['contactsUid'] ?? $contact['id'] ?? '')) === '') {
			return 'no-channel-address';
		}

		if (($contact['surveyOptOut'] ?? false) === true) {
			return 'opt-out';
		}

		$cooldownDays = (int)($rule['cooldownDays'] ?? self::DEFAULT_COOLDOWN_DAYS);
		if ($cooldownDays > 0) {
			$since = $now->modify("-{$cooldownDays} days");
			foreach ($recentInvitations as $invitation) {
				                                                             
				                                                        
				                 
				$sentAt = trim((string)($invitation['sentAt'] ?? ''));
				if ($sentAt === '') {
					continue;
				}

				try {
					if (new DateTimeImmutable($sentAt) >= $since) {
						return 'cooldown';
					}
				} catch (Throwable $e) {
					continue;
				}
			}
		}

		$channel = trim((string)($rule['channel'] ?? 'email'));
		if ($this->addressFor(contact: $contact, channel: $channel) === '') {
			return 'no-channel-address';
		}

		return null;
	}                         

	   
                                                                     
   
                                                       
                                       
   
                               
    
	private function addressFor(array $contact, string $channel): string {
		if ($channel === 'email') {
			return trim((string)($contact['email'] ?? ''));
		}

		return trim((string)($contact['phone'] ?? ''));
	}                  

	   
                                                       
   
                                                                            
                                                                        
                                                                          
        
   
                                                        
                                                       
                                                                  
                                                                                          
                                                              
   
                                                           
   
                                                                                                                                                                    
    
	public function buildInvitation(
		array $rule,
		array $contact,
		array $entity,
		array $recentInvitations = [],
		?DateTimeImmutable $now = null,
	): array {
		$now = ($now ?? new DateTimeImmutable());
		$delayMinutes = max(0, (int)($rule['delayMinutes'] ?? 0));
		$expiryDays = max(1, (int)($rule['expiryDays'] ?? self::DEFAULT_EXPIRY_DAYS));

		$invitation = [
			'token' => $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC),
			'surveyRef' => trim((string)($rule['surveyRef'] ?? '')),
			'contactRef' => trim((string)($contact['contactsUid'] ?? $contact['id'] ?? '')),
			'clientRef' => trim((string)($contact['client'] ?? $entity['client'] ?? '')),
			'linkedEntityType' => trim((string)($rule['trigger']['entityType'] ?? '')),
			'linkedEntityId' => trim((string)($entity['id'] ?? $entity['uuid'] ?? '')),
			'channel' => trim((string)($rule['channel'] ?? 'email')),
			'deliveryAddress' => $this->addressFor(
				contact: $contact,
				channel: trim((string)($rule['channel'] ?? 'email')),
			),
			'dispatchRuleId' => trim((string)($rule['id'] ?? '')),
			'scheduledFor' => $now->modify("+{$delayMinutes} minutes")->format(DateTimeInterface::ATOM),
			'expiresAt' => $now->modify("+{$expiryDays} days")->format(DateTimeInterface::ATOM),
		];

		$reason = $this->suppressionReason(
			rule: $rule,
			contact: $contact,
			recentInvitations: $recentInvitations,
			now: $now,
		);

		if ($reason !== null) {
			$invitation['status'] = 'suppressed';
			$invitation['suppressionReason'] = $reason;

			return $invitation;
		}

		$invitation['status'] = 'scheduled';

		return $invitation;
	}                       

	   
                                                         
   
                                                                          
                                  
   
                                                          
                                                   
                                                       
   
                                                                     
   
                                                                                                                                                                    
    
	public function onInteractionCompleted(string $entityType, array $entity, array $contact): array {
		$rules = $this->matchingRules(
			entityType: $entityType,
			status: trim((string)($entity['status'] ?? '')),
			channel: trim((string)($entity['channel'] ?? '')),
		);

		if ($rules === []) {
			return [];
		}

		$recent = $this->invitationsForContact(
			contactRef: trim((string)($contact['contactsUid'] ?? $contact['id'] ?? ''))
		);

		$written = [];
		foreach ($rules as $rule) {
			$invitation = $this->buildInvitation(
				rule: $rule,
				contact: $contact,
				entity: $entity,
				recentInvitations: $recent,
			);

			if ($this->write(invitation: $invitation) === true) {
				$written[] = $invitation;
			}
		}

		return $written;
	}                              

	   
                                                                      
   
                                              
   
                                                             
    
	private function invitationsForContact(string $contactRef): array {
		if (trim($contactRef) === '') {
			return [];
		}

		return $this->read(filters: ['contactRef' => trim($contactRef)]);
	}                             

	   
                                     
   
                                                       
   
                                                      
    
	public function read(array $filters): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyInvitation_schema', '');
		if ($register === '' || $schema === '') {
			$this->logger->debug('SurveyDispatchService: the survey surface is not configured');

			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				[
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyDispatchService: the invitation read failed',
				['exception' => $e->getMessage()]
			);

			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}            

	   
                           
   
                                                           
                                                     
   
                                            
    
	public function write(array $invitation, ?string $uuid = null): bool {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'surveyInvitation_schema', '');
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$this->objectService->saveObject(
				object: $invitation,
				register: $register,
				schema: $schema,
				uuid: $uuid,
			);

			return true;
		} catch (Throwable $e) {
			                                                                  
			                                                              
			                                                    
			$this->logger->error(
				'SurveyDispatchService: the invitation could not be saved',
				['exception' => $e->getMessage()]
			);

			return false;
		}
	}             

	   
                                              
   
                                                                           
                                                                    
                                                                          
                           
   
                                                                         
   
                                                                                            
                  
   
                                                                                                                                    
    
	public function responseRate(array $invitations): array {
		$counts = ['sent' => 0, 'responded' => 0, 'suppressed' => 0, 'failed' => 0];

		foreach ($invitations as $invitation) {
			$status = (string)($invitation['status'] ?? '');
			if (isset($counts[$status]) === true) {
				$counts[$status]++;
			}
		}

		$delivered = ($counts['sent'] + $counts['responded']);

		$rate = 0.0;
		if ($delivered !== 0) {
			$rate = round((($counts['responded'] / $delivered) * 100), 1);
		}

		return [
			'delivered' => $delivered,
			'responded' => $counts['responded'],
			'rate' => $rate,
			'suppressed' => $counts['suppressed'],
			'failed' => $counts['failed'],
		];
	}                    
}           
