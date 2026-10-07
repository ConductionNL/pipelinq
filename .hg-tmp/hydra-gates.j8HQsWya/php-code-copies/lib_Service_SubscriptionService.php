<?php

   
                                
  
                                                                         
                                                                            
                                                                            
                                                                           
                               
  
                                                                      
                                                                          
                                                                           
                                                                          
                                                                          
                                                    
  
                                                                            
                                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                                                         
  
                                                                                    
                                                                                 
                                                                   
                                                                                
                                                                              
                                                                                   
                                                                                  
                                                                 
  
                                                                                                                 
   
class SubscriptionService {
	   
                                                
   
               
    
	public const STATE_PENDING = 'pending';

	   
                                            
   
               
    
	public const STATE_CONFIRMED = 'confirmed';

	   
                                      
   
               
    
	public const STATE_UNSUBSCRIBED = 'unsubscribed';

	   
                                                      
   
               
    
	public const STATE_BOUNCED = 'bounced';

	   
                                                                     
   
               
    
	private const DEFAULT_SUBSCRIPTION_SCHEMA_SLUG = 'subscription';

	   
                                                                      
                                                                   
                                   
   
               
    
	private const CHANNEL = 'email';

	   
                                                                        
                                                                     
                           
   
                                                                        
                                                                         
                                                                     
                                                                        
                                                 
   
                                          
    
	private const TRANSITIONS = [
		'confirm' => [self::STATE_PENDING],
		'unsubscribe' => [self::STATE_PENDING, self::STATE_CONFIRMED, self::STATE_BOUNCED],
		'resubscribe' => [self::STATE_UNSUBSCRIBED],
		'markBounced' => [self::STATE_PENDING, self::STATE_CONFIRMED],
	];

	   
                                       
   
                              
    
	private const TRANSITION_TARGETS = [
		'confirm' => self::STATE_CONFIRMED,
		'unsubscribe' => self::STATE_UNSUBSCRIBED,
		'resubscribe' => self::STATE_PENDING,
		'markBounced' => self::STATE_BOUNCED,
	];

	   
                
   
                                                                              
                                                                         
                                                                     
                                                            
                                            
                                                                   
                                          
   
                                                                                                                  
    
	public function __construct(
		private ListObjectStore $store,
		private ListTokenService $tokens,
		private MailingListService $lists,
		private ComplianceService $compliance,
		private IMailer $mailer,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}                   

	   
                              
   
                                                                      
                                                                        
                                                                         
                                                                  
                                                                      
   
                                                   
                                                             
                                                                        
                                                                         
   
                                                                       
   
                                                                                                                  
    
	public function subscribe(
		string $listId,
		string $email,
		string $honeypot = '',
		?string $contactId = null,
	): array {
		$list = $this->lists->getMailingListById(listId: $listId);
		if ($this->lists->acceptsPublicSignup(list: $list) === false) {
			return ['status' => 'not-found'];
		}

		$email = strtolower(trim($email));
		if ($this->mailer->validateMailAddress($email) === false) {
			return ['status' => 'invalid'];
		}

		if (trim($honeypot) !== '') {
			$this->logger->info(
				'SubscriptionService.subscribe: honeypot filled, discarding',
				['listId' => $listId, 'emailHash' => $this->tokens->hashAddress(address: $email)]
			);
			return ['status' => 'accepted'];
		}

		$existing = $this->findSubscription(listId: $listId, email: $email);
		if ($existing !== null && (string)($existing['state'] ?? '') === self::STATE_CONFIRMED) {
			                                                              
			                                                               
			                                                          
			return ['status' => 'accepted'];
		}

		$this->startConfirmation(
			existing: $existing,
			list: (array)$list,
			listId: $listId,
			email: $email,
			contactId: $contactId,
			source: 'public-signup',
		);

		return ['status' => 'accepted'];
	}                 

	   
                                              
   
                                                                       
                                                                        
                                                                       
                                               
   
                                                       
                                                                       
   
                                                                          
                                                          
   
                                                                                                                                
    
	public function confirm(string $token, string $ipAddress = ''): array {
		$payload = $this->tokens->verify(token: $token, purpose: ListTokenService::PURPOSE_CONFIRM);
		if ($payload === null) {
			return ['status' => 'invalid'];
		}

		$subscriptionId = (string)($payload['s'] ?? '');
		$nonce = (string)($payload['n'] ?? '');
		$subscription = $this->loadSubscription(id: $subscriptionId);
		if ($subscription === null || $nonce === '') {
			return ['status' => 'invalid'];
		}

		$storedDigest = (string)($subscription['confirmTokenHash'] ?? '');
		if ($storedDigest === '' || hash_equals($storedDigest, $this->tokens->digest(nonce: $nonce)) === false) {
			return ['status' => 'invalid'];
		}

		if ($this->canTransition(from: (string)($subscription['state'] ?? ''), action: 'confirm') === false) {
			return ['status' => 'invalid'];
		}

		$now = $this->nowIso();
		$subscription['state'] = self::STATE_CONFIRMED;
		$subscription['confirmedAt'] = $now;
		$subscription['confirmIpHash'] = $this->tokens->hashAddress(address: $ipAddress);
		$subscription['confirmTokenHash'] = '';
		$subscription['lawfulBasis'] = 'consent';

		$saved = $this->save(payload: $subscription, id: $subscriptionId);
		if ($saved === null) {
			return ['status' => 'invalid'];
		}

		$listId = (string)($subscription['listId'] ?? '');
		$this->compliance->recordListConsent(
			contactId: $this->contactKeyFor(subscription: $subscription),
			listId: $listId,
			channel: self::CHANNEL,
			lawfulBasis: 'consent',
			consentSource: 'double-opt-in',
			evidence: [
				'mechanism' => 'double-opt-in',
				'reference' => $subscriptionId,
				'objectionOffered' => true,
				'objectionOfferedAt' => (string)($subscription['createdAt'] ?? $now),
				'ipHash' => $this->tokens->hashAddress(address: $ipAddress),
				'recordedAt' => $now,
			],
		);

		$list = $this->lists->getMailingListById(listId: $listId);
		if ($list === null) {
			return ['status' => 'confirmed'];
		}

		return ['status' => 'confirmed', 'list' => $this->lists->publicProjection(list: $list)];
	}               

	   
                                                                       
   
                                                                        
                                                                         
   
                                                      
   
                                                                           
                                                    
   
                                                                                                           
    
	public function peekUnsubscribeToken(string $token): ?array {
		$subscription = $this->subscriptionForUnsubscribeToken(token: $token);
		if ($subscription === null) {
			return null;
		}

		$list = $this->lists->getMailingListById(listId: (string)($subscription['listId'] ?? ''));
		if ($list === null) {
			return null;
		}

		return [
			'list' => $this->lists->publicProjection(list: $list),
			'state' => (string)($subscription['state'] ?? ''),
		];
	}                            

	   
                               
   
                                                                         
                                                                     
                                                                       
                                            
   
                                                      
                                                            
                                                                 
   
                                                                            
                                                
   
                                                                                                           
   
                                                                               
                                                                           
                                                               
    
	public function unsubscribeByToken(string $token, bool $global = false, string $reason = ''): array {
		$subscription = $this->subscriptionForUnsubscribeToken(token: $token);
		if ($subscription === null) {
			return ['status' => 'invalid', 'count' => 0];
		}

		$contactId = (string)($subscription['contactId'] ?? '');
		if ($global === true && $contactId !== '') {
			return ['status' => 'unsubscribed', 'count' => $this->globalUnsubscribe(contactId: $contactId, reason: $reason)];
		}

		$closed = $this->closeSubscription(subscription: $subscription, reason: $reason);
		if ($closed === false) {
			return ['status' => 'invalid', 'count' => 0];
		}

		return ['status' => 'unsubscribed', 'count' => 1];
	}                          

	   
                                               
   
                                                   
                                                  
                                                                   
   
                                                   
   
                                                                                                           
    
	public function unsubscribeFromList(string $listId, string $contactId, string $reason = ''): bool {
		foreach ($this->loadSubscriptions(filters: ['listId' => $listId, 'contactId' => $contactId]) as $subscription) {
			if ($this->closeSubscription(subscription: $subscription, reason: $reason) === true) {
				return true;
			}
		}

		return false;
	}                           

	   
                                             
   
                                                  
                                                                 
   
                                                 
   
                                                                                                           
    
	public function globalUnsubscribe(string $contactId, string $reason = ''): int {
		if ($contactId === '') {
			return 0;
		}

		$closed = 0;
		foreach ($this->loadSubscriptions(filters: ['contactId' => $contactId]) as $subscription) {
			if ($this->closeSubscription(subscription: $subscription, reason: $reason) === true) {
				$closed++;
			}
		}

		return $closed;
	}                         

	   
                                                   
   
                                                                         
                                                                     
                                                                       
                                          
   
                                                   
                                                  
                                                
                                                                       
                                                                         
                                                                      
   
                                                                          
                            
   
                                                                                                                     
    
	public function importSoftOptIn(string $listId, string $contactId, string $email, array $evidence): array {
		$list = $this->lists->getMailingListById(listId: $listId);
		if ($list === null) {
			return ['status' => 'refused', 'error' => 'Not found'];
		}

		if ((string)($list['optInMode'] ?? '') !== MailingListService::OPT_IN_SOFT) {
			return [
				'status' => 'refused',
				'error' => 'This list uses double opt-in, so a subscriber has to confirm the link themselves',
			];
		}

		$email = strtolower(trim($email));
		if ($this->mailer->validateMailAddress($email) === false) {
			return ['status' => 'refused', 'error' => 'That is not a valid email address'];
		}

		if ((bool)($evidence['objectionOffered'] ?? false) === false) {
			return [
				'status' => 'refused',
				'error' => 'A soft opt-in needs a record that the customer was offered the chance to object',
			];
		}

		$now = $this->nowIso();
		$existing = $this->findSubscription(listId: $listId, email: $email);
		$record = ($existing ?? []);
		$record['listId'] = $listId;
		$record['contactId'] = $contactId;
		$record['email'] = $email;
		$record['state'] = self::STATE_CONFIRMED;
		$record['source'] = 'soft-opt-in-import';
		$record['lawfulBasis'] = 'soft-opt-in';
		$record['confirmedAt'] = $now;
		$record['confirmTokenHash'] = '';
		if (isset($record['createdAt']) === false) {
			$record['createdAt'] = $now;
		}

		$saved = $this->save(payload: $record, id: $this->idOf(payload: $existing));
		if ($saved === null) {
			return ['status' => 'refused', 'error' => 'The subscription could not be saved'];
		}

		$this->compliance->recordListConsent(
			contactId: $contactId,
			listId: $listId,
			channel: self::CHANNEL,
			lawfulBasis: 'soft-opt-in',
			consentSource: 'soft-opt-in-import',
			evidence: array_merge(
				['mechanism' => 'soft-opt-in-import', 'recordedAt' => $now],
				$evidence,
			),
		);

		return ['status' => 'imported'];
	}                       

	   
                                                                  
   
                                                                        
                                                                          
                                                                       
                                                                      
                                                                     
   
                                                   
                                                  
                                                                           
                                                           
   
                                                                 
   
                                                                                                                      
    
	public function confirmForContact(
		string $listId,
		string $contactId,
		string $email,
		string $source = 'preference-centre',
	): bool {
		$list = $this->lists->getMailingListById(listId: $listId);
		if ($list === null || (string)($list['status'] ?? 'active') !== 'active') {
			return false;
		}

		$subscription = null;
		foreach ($this->loadSubscriptions(filters: ['listId' => $listId, 'contactId' => $contactId]) as $row) {
			$subscription = $row;
			break;
		}

		if ($subscription === null && $email === '') {
			                                                              
			                                                                
			                                                 
			return false;
		}

		$now = $this->nowIso();
		$record = ($subscription ?? ['createdAt' => $now, 'email' => $email]);
		if ((string)($record['state'] ?? '') === self::STATE_CONFIRMED) {
			return false;
		}

		$record['listId'] = $listId;
		$record['contactId'] = $contactId;
		$record['state'] = self::STATE_CONFIRMED;
		$record['source'] = $source;
		$record['lawfulBasis'] = 'consent';
		$record['confirmedAt'] = $now;
		$record['confirmTokenHash'] = '';

		if ($this->save(payload: $record, id: $this->idOf(payload: $subscription)) === null) {
			return false;
		}

		$this->compliance->recordListConsent(
			contactId: $contactId,
			listId: $listId,
			channel: self::CHANNEL,
			lawfulBasis: 'consent',
			consentSource: $source,
			evidence: [
				'mechanism' => $source,
				'objectionOffered' => true,
				'objectionOfferedAt' => $now,
				'recordedAt' => $now,
			],
		);

		return true;
	}                         










	   
                                                         
   
                               
   
                                                                                                                  
    
	public function getSubscriptionSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'subscription_schema',
			default: self::DEFAULT_SUBSCRIPTION_SCHEMA_SLUG,
		);
	}                                 

	   
                                                                 
   
                                          
                                              
   
                                                      
    
	private function canTransition(string $from, string $action): bool {
		$allowed = (self::TRANSITIONS[$action] ?? []);
		return in_array($from, $allowed, true);
	}                     

	   
                                                             
   
                                                                          
                                                                          
          
   
                                                                               
                                                            
                                                   
                                                  
                                                                         
                                                     
   
                
    
	private function startConfirmation(
		?array $existing,
		array $list,
		string $listId,
		string $email,
		?string $contactId,
		string $source,
	): void {
		$nonce = $this->tokens->mintNonce();
		$now = $this->nowIso();

		$record = ($existing ?? []);
		$record['listId'] = $listId;
		$record['email'] = $email;
		$record['state'] = self::STATE_PENDING;
		$record['source'] = $source;
		$record['lawfulBasis'] = 'consent';
		$record['confirmTokenHash'] = $this->tokens->digest(nonce: $nonce);
		if ($contactId !== null && $contactId !== '') {
			$record['contactId'] = $contactId;
		}

		if (isset($record['createdAt']) === false) {
			$record['createdAt'] = $now;
		}

		$saved = $this->save(payload: $record, id: $this->idOf(payload: $existing));
		if ($saved === null) {
			$this->logger->warning(
				'SubscriptionService.startConfirmation: subscription could not be saved',
				['listId' => $listId, 'emailHash' => $this->tokens->hashAddress(address: $email)]
			);
			return;
		}

		$subscriptionId = $this->idOf(payload: $saved);
		if ($subscriptionId === '') {
			return;
		}

		$this->sendConfirmationMail(
			list: $list,
			email: $email,
			token: $this->tokens->signConfirmToken(subscriptionId: $subscriptionId, nonce: $nonce),
		);
	}                         

	   
                                                        
   
                                                                         
                                                                    
                                                     
   
                                                            
                                       
                                                       
   
                
    
	private function sendConfirmationMail(array $list, string $email, string $token): void {
		$listName = (string)($list['name'] ?? '');
		$subject = $this->l10n->t('Confirm your subscription to %s', [$listName]);
		$template = "You asked to receive %1\$s.\n\n"
			. "Open this link to confirm. If you did not ask for this, "
			. "ignore this message and nothing happens.\n\n%2\$s\n\n%3\$s";
		$body = $this->l10n->t(
			$template,
			[$listName, $this->tokens->confirmUrl(token: $token), (string)($list['footerAddress'] ?? '')]
		);

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject($subject);
			$message->setPlainBody($body);

			$sender = trim((string)($list['senderEmail'] ?? ''));
			if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
				$message->setFrom([$sender => (string)($list['senderName'] ?? $sender)]);
			}

			$replyTo = trim((string)($list['replyTo'] ?? ''));
			if ($replyTo !== '' && $this->mailer->validateMailAddress($replyTo) === true) {
				$message->setReplyTo([$replyTo]);
			}

			$this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SubscriptionService.sendConfirmationMail: dispatch failed',
				['listName' => $listName, 'exception' => $e->getMessage()]
			);
		}         
	}                            


	   
                                                   
   
                                                                      
                                                                 
   
                                                                
    
	private function closeSubscription(array $subscription, string $reason): bool {
		$state = (string)($subscription['state'] ?? '');
		if ($state === self::STATE_UNSUBSCRIBED) {
			                                                               
			                                      
			return true;
		}

		if ($this->canTransition(from: $state, action: 'unsubscribe') === false) {
			return false;
		}

		$subscription['state'] = self::TRANSITION_TARGETS['unsubscribe'];
		$subscription['unsubscribedAt'] = $this->nowIso();
		$subscription['confirmTokenHash'] = '';
		if ($reason !== '') {
			$subscription['reason'] = $reason;
		}

		if ($this->save(payload: $subscription, id: $this->idOf(payload: $subscription)) === null) {
			return false;
		}

		$this->compliance->recordConsentWithdrawal(
			contactId: $this->contactKeyFor(subscription: $subscription),
			channel: self::CHANNEL,
			reason: 'user-unsubscribed',
			sourceBlastId: null,
			listId: (string)($subscription['listId'] ?? ''),
		);

		return true;
	}                         

	   
                                                            
   
                                                      
   
                                                              
    
	private function subscriptionForUnsubscribeToken(string $token): ?array {
		$payload = $this->tokens->verify(token: $token, purpose: ListTokenService::PURPOSE_UNSUBSCRIBE);
		if ($payload === null) {
			return null;
		}

		return $this->loadSubscription(id: (string)($payload['s'] ?? ''));
	}                                       


	   
                                                                 
   
                                                                        
                                                                    
                                                                       
                 
   
                                                             
   
                                   
    
	private function contactKeyFor(array $subscription): string {
		$contactId = (string)($subscription['contactId'] ?? '');
		if ($contactId !== '') {
			return $contactId;
		}

		return (string)($subscription['email'] ?? '');
	}                     

	   
                                            
   
                                                   
                                     
   
                                                              
    
	private function findSubscription(string $listId, string $email): ?array {
		foreach ($this->loadSubscriptions(filters: ['listId' => $listId]) as $subscription) {
			if (strtolower((string)($subscription['email'] ?? '')) === $email) {
				return $subscription;
			}
		}

		return null;
	}                        

	   
                                        
   
                                                
   
                                                              
    
	private function loadSubscription(string $id): ?array {
		return $this->store->find(schemaSlug: $this->getSubscriptionSchemaSlug(), id: $id);
	}                        

	   
                                           
   
                                                                             
                                                                       
                                 
   
                                                            
   
                                                            
    
	private function loadSubscriptions(array $filters): array {
		return $this->store->findAll(schemaSlug: $this->getSubscriptionSchemaSlug(), filters: $filters);
	}                         

	   
                                               
   
                                                              
                                                     
   
                                                                    
    
	private function save(array $payload, ?string $id = null): ?array {
		return $this->store->save(
			schemaSlug: $this->getSubscriptionSchemaSlug(),
			payload: $payload,
			id: $id,
		);
	}            



	   
                                                            
   
                                      
    
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}              


	   
                                                    
   
                                                             
   
                                                  
    
	private function idOf(?array $payload): string {
		return $this->store->idOf(payload: $payload);
	}            
}           
