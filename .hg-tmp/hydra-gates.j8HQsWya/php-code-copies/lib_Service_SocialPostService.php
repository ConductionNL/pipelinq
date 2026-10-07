<?php

   
                              
  
                                                                               
                                                               
  
                                                       
  
                                                                            
                                                                            
                                                                 
                                                                               
                                                                              
                          
  
                                                                             
                                                                         
                                                                            
                                                           
  
                                                                             
                                                                              
                                                                           
                                                                               
  
                                                                              
                                                                               
                                                                               
                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                        
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\Social\SocialPublishOutcome;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use OCP\IAppConfig;

   
                                                              
  
                                                                                                                        
  
                                                                          
                                                                            
                                                                              
                                                          
                                                                        
                                                                             
                                                                              
                                                                         
                                                                          
                                                                               
                                                                            
                                                                         
                                                                             
                                                                               
                                                                             
                                                                              
                                                         
   
class SocialPostService {
	   
                                                         
   
               
    
	public const SCHEMA = 'socialPost';

	   
                                                     
   
               
    
	public const SCHEMA_CONFIG_KEY = 'social_post_schema';

	   
                  
   
               
    
	public const STATUS_DRAFT = 'draft';

	   
                                   
   
               
    
	public const STATUS_APPROVAL = 'approval';

	   
                                     
   
               
    
	public const STATUS_SCHEDULED = 'scheduled';

	   
                                
   
               
    
	public const STATUS_PUBLISHED = 'published';

	   
                                              
   
               
    
	public const STATUS_FAILED = 'failed';

	   
                                                                          
                                                                            
                                                                            
                                                                   
   
                           
    
	public const SETTLED_STATUSES = [
		SocialPublicationStore::PUBLISHED,
		SocialPublicationStore::SHARED,
		SocialPublicationStore::AWAITING_SHARE,
	];

	   
                                                                    
                                                                         
                                                                         
   
                           
    
	public const CLIENT_WRITABLE = [
		'title',
		'articleId',
		'campaignId',
		'body',
		'media',
		'link',
		'accountIds',
		'variants',
		'scheduledFor',
	];

	   
                
   
                                                                      
                                                                                 
                                                                
                                                                            
                                                                   
                                                                                            
                                                                                              
                                                             
                                                               
   
                
    
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly SocialAccountService $accounts,
		private readonly SocialAdapterRegistry $registry,
		private readonly SocialPublicationStore $publications,
		private readonly SocialAdvocacyService $advocacy,
		private readonly CampaignLinkDecorator $links,
		private readonly CampaignService $campaigns,
		private readonly BudgetService $budget,
		private readonly IAppConfig $appConfig,
	) {
	}                   

	   
                                                      
   
                                   
   
                                                                                                                         
    
	public function schema(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA);
	}              

	   
                                              
   
                                                                            
   
                                                                    
   
                                                                                                                         
    
	public function listPosts(string $status = ''): array {
		$filters = [];
		if ($status !== '') {
			$filters['status'] = $status;
		}

		return ['data' => $this->store->findAll(schemaSlug: $this->schema(), filters: $filters)];
	}                 

	   
                      
   
                                   
   
                                                        
   
                                                                                                                         
    
	public function getPost(string $postId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $postId);
	}               

	   
                             
   
                                                             
                                                            
                                                                                
   
                                                                                     
   
                                                                                                                                        
    
	public function createPost(array $payload, string $uid, string $agent = ''): array {
		if (trim((string)($payload['body'] ?? '')) === '') {
			return ['error' => 'A post needs something to say.'];
		}

		$post = $this->clientFields(payload: $payload);
		$post['status'] = self::STATUS_DRAFT;
		$post['approvals'] = [];
		$post['createdBy'] = $uid;
		                                                                       
		                                                                        
		                     
		$post['agentAuthored'] = ($agent !== '');
		$post['agentAuthoredBy'] = $agent;

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post);
		if ($saved === null) {
			return ['error' => 'The post could not be saved.'];
		}

		return ['post' => $saved];
	}                  

	   
                                                                       
                                             
   
                                   
                                                             
                                                                      
   
                                                                                     
   
                                                                                                                                        
    
	public function updatePost(string $postId, array $payload, string $uid): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_DRAFT) {
			return ['error' => 'Only a draft can be edited. Return this post to draft first.'];
		}

		$update = array_merge($post, $this->clientFields(payload: $payload));
		if ((bool)($post['agentAuthored'] ?? false) === true) {
			                                                            
			$update['agentAuthored'] = false;
			$update['agentAuthoredBy'] = '';
			$update['createdBy'] = $uid;
		}

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $update, id: $postId);
		if ($saved === null) {
			return ['error' => 'The post could not be saved.'];
		}

		return ['post' => $saved];
	}                  

	   
                                                                         
                                                 
   
                                               
                                       
   
                                                                                                           
   
                                                                                                                         
    
	public function resolveVariant(array $post, string $network): array {
		$media = ($post['media'] ?? []);
		if (is_array($media) === false) {
			$media = [];
		}

		$resolved = [
			'body' => (string)($post['body'] ?? ''),
			'link' => (string)($post['link'] ?? ''),
			'media' => $media,
		];

		$variants = ($post['variants'] ?? []);
		$variant = null;
		if (is_array($variants) === true) {
			$variant = ($variants[$network] ?? null);
		}

		if (is_array($variant) === false) {
			return $resolved;
		}

		foreach (['body', 'link'] as $field) {
			$value = trim((string)($variant[$field] ?? ''));
			if ($value !== '') {
				$resolved[$field] = $value;
			}
		}

		$variantMedia = ($variant['media'] ?? null);
		if (is_array($variantMedia) === true && $variantMedia !== []) {
			$resolved['media'] = $variantMedia;
		}

		return $resolved;
	}                      

	   
                                                                          
   
                                                                       
                                                                
   
                                               
   
                                                                               
   
                                                                                                                         
    
	public function overlongVariants(array $post): array {
		$problems = [];
		foreach ($this->networksOf(post: $post) as $network) {
			$adapter = $this->registry->forNetwork(network: $network);
			if ($adapter === null) {
				continue;
			}

			$length = mb_strlen($this->resolveVariant(post: $post, network: $network)['body']);
			if ($length <= $adapter->bodyLimit()) {
				continue;
			}

			$problems[] = sprintf(
				'The text for %s is %d characters and %s accepts %d.',
				$network,
				$length,
				$network,
				$adapter->bodyLimit(),
			);
		}

		return $problems;
	}                        

	   
                                                                      
   
                                   
   
                                                                                     
   
                                                                                                                                        
    
	public function submitForApproval(string $postId): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_DRAFT) {
			return ['error' => 'Only a draft can be submitted for approval.'];
		}

		if ($this->networksOf(post: $post) === []) {
			return ['error' => 'Pick at least one account for this post to go to.'];
		}

		$problems = $this->overlongVariants(post: $post);
		if ($problems !== []) {
			return ['error' => implode(' ', $problems)];
		}

		$post['status'] = self::STATUS_APPROVAL;
		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post, id: $postId);
		if ($saved === null) {
			return ['error' => 'The post could not be saved.'];
		}

		return ['post' => $saved];
	}                         

	   
                                                  
   
                                   
                                                            
                                                        
   
                                                                                     
   
                                                                                                                                        
    
	public function approve(string $postId, string $uid, string $note = ''): array {
		return $this->decide(
			postId: $postId,
			uid: $uid,
			decision: 'approved',
			note: $note,
			nextStatus: self::STATUS_SCHEDULED,
		);
	}               

	   
                                                   
   
                                   
                                                            
                            
   
                                                                                     
   
                                                                                                                                        
    
	public function reject(string $postId, string $uid, string $note = ''): array {
		return $this->decide(
			postId: $postId,
			uid: $uid,
			decision: 'rejected',
			note: $note,
			nextStatus: self::STATUS_DRAFT,
		);
	}              

	   
                                                         
   
                                                                                      
   
                                              
   
                                                                                                                                        
    
	public function publishDuePosts(?string $now = null): int {
		$moment = ($now ?? gmdate('Y-m-d\TH:i:s\Z'));
		$attempted = 0;

		foreach ($this->store->findAll(schemaSlug: $this->schema(), filters: ['status' => self::STATUS_SCHEDULED]) as $post) {
			$due = trim((string)($post['scheduledFor'] ?? ''));
			if ($due !== '' && strcmp($due, $moment) > 0) {
				continue;
			}

			$this->publishPost(postId: $this->store->idOf(payload: $post));
			$attempted++;
		}

		return $attempted;
	}                       

	   
                                                                
   
                                   
   
                                                                                                               
                                                               
   
                                                                                                                                        
    
	public function publishPost(string $postId): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_SCHEDULED) {
			                                                                     
			return ['error' => 'Only an approved post is published.'];
		}

		$accountIds = ($post['accountIds'] ?? []);
		if (is_array($accountIds) === false) {
			$accountIds = [];
		}

		$rows = [];
		foreach ($accountIds as $accountId) {
			$rows[] = $this->publishToAccount(post: $post, accountId: (string)$accountId);
		}

		return ['post' => $this->settle(postId: $postId, post: $post), 'publications' => array_filter($rows)];
	}                   

	   
                                     
   
                                                                      
                                                                            
        
   
                                                 
                                  
   
                                                                                           
   
                                                                                                                                        
    
	public function retryPublication(string $publicationId, string $uid): array {
		$publication = $this->publications->find(publicationId: $publicationId);
		if ($publication === null) {
			return ['error' => 'That publication does not exist.'];
		}

		$account = $this->accounts->getAccount(accountId: (string)($publication['accountId'] ?? ''));
		if ($account === null) {
			return ['error' => 'That publication has no account.'];
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not publish to this account.'];
		}

		$code = (string)($publication['failureCode'] ?? '');
		if (in_array($code, SocialGatewayResult::RETRYABLE, true) === false) {
			return ['error' => (string)($publication['failureReason'] ?? 'This failure cannot be fixed by trying again.')];
		}

		$postId = (string)($publication['postId'] ?? '');
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That publication has no post.'];
		}

		$row = $this->publishToAccount(post: $post, accountId: (string)($publication['accountId'] ?? ''));
		$this->settle(postId: $postId, post: $post);

		return ['publication' => ($row ?? $publication)];
	}                        

	   
                                                                 
   
                                               
                                         
   
                                                                                                
   
                                                                                                                                        
    
	private function publishToAccount(array $post, string $accountId): ?array {
		$account = $this->accounts->getAccount(accountId: $accountId);
		$network = '';
		if ($account !== null) {
			$network = (string)($account['network'] ?? '');
		}

		$publication = $this->publications->open(
			postId: $this->store->idOf(payload: $post),
			accountId: $accountId,
			network: $network,
		);

		if ($publication === null) {
			return null;
		}

		if ($account === null) {
			return $this->publications->record(
				publication: $publication,
				outcome: SocialPublishOutcome::refused(
					code: SocialGatewayResult::NOT_CONFIGURED,
					reason: 'This post names an account that no longer exists.',
				),
			);
		}

		$request = $this->requestFor(post: $post, account: $account);

		                                                                  
		if ((string)($account['publishMode'] ?? 'api') === 'share') {
			return $this->advocacy->requestShare(
				publication: $publication,
				account: $account,
				post: $post,
				request: $request,
			);
		}

		$adapter = $this->registry->forNetwork(network: $network);
		if ($adapter === null) {
			return $this->publications->record(
				publication: $publication,
				outcome: SocialPublishOutcome::refused(
					code: SocialGatewayResult::NOT_CONFIGURED,
					reason: 'Pipelinq cannot publish to ' . $network . ' yet.',
				),
			);
		}

		$stop = $this->budgetRefusal(adapter: $adapter);
		if ($stop !== null) {
			return $this->publications->record(publication: $publication, outcome: $stop);
		}

		$outcome = $adapter->publish(request: $request);
		$recorded = $this->publications->record(publication: $publication, outcome: $outcome);
		$this->afterAttempt(accountId: $accountId, adapter: $adapter, outcome: $outcome);

		return $recorded;
	}                        

	   
                                                                       
                                                               
   
                                               
                                                     
   
                                             
   
                                                                                                                     
    
	private function requestFor(array $post, array $account): SocialPublishRequest {
		$network = (string)($account['network'] ?? '');
		$resolved = $this->resolveVariant(post: $post, network: $network);

		                                                                        
		                                                                      
		                                
		$owner = trim((string)($account['ownerUserId'] ?? ''));
		$acting = null;
		if ($owner !== '') {
			$acting = $owner;
		}

		return new SocialPublishRequest(
			network: $network,
			body: $resolved['body'],
			link: $this->decoratedLink(post: $post, network: $network, link: $resolved['link']),
			media: $resolved['media'],
			credentialRef: (string)($account['credentialRef'] ?? ''),
			externalAccountId: (string)($account['externalAccountId'] ?? ''),
			accountKind: (string)($account['kind'] ?? 'organisation'),
			handle: (string)($account['handle'] ?? ''),
			actingUserId: $acting,
		);
	}                  

	   
                                                                         
   
                                                                          
                                           
   
                                               
                                                                 
                                          
   
                                       
   
                                                                                                                     
    
	private function decoratedLink(array $post, string $network, string $link): string {
		$campaignId = trim((string)($post['campaignId'] ?? ''));
		if ($link === '' || $campaignId === '' || $this->links->isEnabled() === false) {
			return $link;
		}

		                                                                     
		                                                                     
		                                                                        
		                                                                       
		                                                                    
		                                                                     
		$campaign = CampaignLinkDecorator::slugify(
			value: (string)($this->campaigns->find(id: $campaignId)['utmCampaign'] ?? $campaignId),
		);
		if ($campaign === '') {
			return $link;
		}

		$decorated = $this->links->decorateUrl(
			url: $link,
			utm: [
				'utm_source' => 'social',
				'utm_medium' => $network,
				'utm_campaign' => $campaign,
				'utm_content' => $this->store->idOf(payload: $post),
			],
		);

		return ($decorated ?? $link);
	}                     

	   
                                                                         
   
                                                                               
   
                                                           
   
                                                                                                                                     
    
	private function budgetRefusal(SocialNetworkAdapter $adapter): ?SocialPublishOutcome {
		$cost = $adapter->costPerPost();
		if ($cost <= 0.0) {
			return null;
		}

		$allowed = $this->budget->canSend(
			tenantId: $this->tenantId(),
			providerId: $adapter->network(),
			estimatedCostEur: $cost,
		);
		if ($allowed === true) {
			return null;
		}

		return SocialPublishOutcome::refused(
			code: SocialGatewayResult::BUDGET_EXHAUSTED,
			reason: 'The spend budget for ' . $adapter->network() . ' is reached, so nothing was sent.',
		);
	}                     

	   
                                                                         
                                                   
   
                                         
                                                     
                                                       
   
                
   
                                                                                                                                        
    
	private function afterAttempt(string $accountId, SocialNetworkAdapter $adapter, SocialPublishOutcome $outcome): void {
		if ($outcome->accepted === true && $outcome->cost > 0.0) {
			$this->budget->recordSend(
				tenantId: $this->tenantId(),
				providerId: $adapter->network(),
				costEur: $outcome->cost,
			);
		}

		if ($outcome->failureCode === SocialGatewayResult::RELINK_NEEDED) {
			$this->accounts->markRelinkNeeded(accountId: $accountId, reason: $outcome->failureReason);
		}
	}                    

	   
                                                                
   
                                                                        
                                                    
                               
   
                                   
                                                       
   
                                                  
   
                                                                                                                                        
    
	private function settle(string $postId, array $post): array {
		$rows = $this->publications->forPost(postId: $postId);
		$failures = [];
		$done = 0;

		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if ($status === SocialPublicationStore::FAILED) {
				$failures[] = (string)($row['network'] ?? '') . ': ' . (string)($row['failureReason'] ?? '');
				continue;
			}

			if (in_array($status, self::SETTLED_STATUSES, true) === true) {
				$done++;
			}
		}

		$post['status'] = self::STATUS_SCHEDULED;
		$post['failureReason'] = '';
		if ($failures !== []) {
			$post['status'] = self::STATUS_FAILED;
			$post['failureReason'] = implode(' ', $failures);
		} elseif ($rows !== [] && $done === count($rows)) {
			$post['status'] = self::STATUS_PUBLISHED;
			if (trim((string)($post['publishedAt'] ?? '')) === '') {
				$post['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
			}
		}

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post, id: $postId);

		return ($saved ?? $post);
	}              

	   
                                                
   
                                   
                                           
                                                     
                                    
                                                  
   
                                                                                     
    
	private function decide(string $postId, string $uid, string $decision, string $note, string $nextStatus): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_APPROVAL) {
			return ['error' => 'This post is not waiting for approval.'];
		}

		$approvals = ($post['approvals'] ?? []);
		if (is_array($approvals) === false) {
			$approvals = [];
		}

		                                                                      
		                                                                    
		                                
		$approvals[] = [
			'userId' => $uid,
			'decision' => $decision,
			'decidedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'note' => $note,
		];

		$post['approvals'] = $approvals;
		$post['status'] = $nextStatus;

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post, id: $postId);
		if ($saved === null) {
			return ['error' => 'The decision could not be saved.'];
		}

		return ['post' => $saved];
	}              

	   
                                                                    
   
                                               
   
                                                     
    
	private function networksOf(array $post): array {
		$accountIds = ($post['accountIds'] ?? []);
		if (is_array($accountIds) === false) {
			return [];
		}

		$networks = [];
		foreach ($accountIds as $accountId) {
			$account = $this->accounts->getAccount(accountId: (string)$accountId);
			$network = trim((string)($account['network'] ?? ''));
			if ($network !== '' && in_array($network, $networks, true) === false) {
				$networks[] = $network;
			}
		}

		return $networks;
	}                  

	   
                                          
   
                                                              
   
                                                     
    
	private function clientFields(array $payload): array {
		$out = [];
		foreach (self::CLIENT_WRITABLE as $field) {
			if (array_key_exists($field, $payload) === true) {
				$out[$field] = $payload[$field];
			}
		}

		return $out;
	}                    

	   
                                                                          
                                                                      
   
                                 
    
	private function tenantId(): string {
		$tenantId = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}                
}           
