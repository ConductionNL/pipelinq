<?php

   
                                 
  
                                                                               
  
                                                                          
                                                                            
                                                                             
                                                                             
                                                                         
                                                                               
                         
  
                                                                            
                                                                              
                                                                         
                                                               
  
                                                                      
                                                                              
                                                                               
                                                                               
                                                                         
                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Social\SocialAdapterCall;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCP\IAppConfig;

   
                                                                        
  
                                                                                                                                               
  
                                                                            
                                                                              
                                                          
  
                                                                  
                                                                    
                                                                        
                                                                           
                                                                          
   
class SocialMetricsService {
	   
                
   
                                                                            
                                                                 
                                                                
                                                                 
                                                                                                   
                                                               
   
                
    
	public function __construct(
		private readonly SocialPublicationStore $publications,
		private readonly SocialAccountService $accounts,
		private readonly SocialAdapterRegistry $registry,
		private readonly SocialBrokerGateway $gateway,
		private readonly BudgetService $budget,
		private readonly IAppConfig $appConfig,
	) {
	}                   

	   
                                                                            
          
   
                                                                            
                                                                             
                                                                   
   
                                                                                  
   
                                                                                                                                                
    
	public function pullAll(): array {
		$updated = 0;
		$failed = 0;

		foreach ($this->publications->findAll(filters: ['status' => SocialPublicationStore::PUBLISHED]) as $publication) {
			if ($this->pullOne(publication: $publication) === true) {
				$updated++;
				continue;
			}

			$failed++;
		}

		return [
			'publications' => $updated,
			'accounts' => $this->refreshFollowerCounts(),
			'failed' => $failed,
		];
	}               

	   
                                                       
   
                                                                 
   
                                                     
   
                                                                                                                                                
    
	public function pullOne(array $publication): bool {
		$externalId = trim((string)($publication['externalId'] ?? ''));
		$accountId = (string)($publication['accountId'] ?? '');
		$account = $this->accounts->getAccount(accountId: $accountId);
		if ($externalId === '' || $account === null) {
			return false;
		}

		$adapter = $this->registry->forNetwork(network: (string)($account['network'] ?? ''));
		if ($adapter === null) {
			return false;
		}

		$call = $adapter->metricsCall(externalId: $externalId, account: $account);
		if ($call === null) {
			                                                                 
			                                                            
			return false;
		}

		$result = $this->read(call: $call, adapter: $adapter, account: $account);
		if ($result->accepted === false) {
			if ($result->failureCode === SocialGatewayResult::RELINK_NEEDED) {
				$this->accounts->markRelinkNeeded(accountId: $accountId, reason: $result->failureReason);
			}

			                                                                  
			                                                                
			return false;
		}

		$publication['metrics'] = $adapter->normaliseMetrics(payload: $result->body, externalId: $externalId);
		$publication['rawMetrics'] = $result->body;
		$publication['metricsAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$publication['cost'] = ((float)($publication['cost'] ?? 0) + $adapter->costPerRead());

		return ($this->publications->save(publication: $publication) !== null);
	}               

	   
                                                                              
   
                                               
   
                                                                                                                                                
    
	public function refreshFollowerCounts(): int {
		$updated = 0;

		foreach ($this->accounts->activeAccounts() as $account) {
			$adapter = $this->registry->forNetwork(network: (string)($account['network'] ?? ''));
			$call = $adapter?->followersCall(account: $account);
			if ($adapter === null || $call === null) {
				continue;
			}

			$result = $this->read(call: $call, adapter: $adapter, account: $account);
			if ($result->accepted === false) {
				continue;
			}

			$this->accounts->recordFollowerCount(
				accountId: (string)($account['id'] ?? $account['uuid'] ?? ''),
				followers: $adapter->readFollowers(payload: $result->body),
			);
			$updated++;
		}

		return $updated;
	}                             

	   
                                                          
   
                                                                           
                                                                        
                                           
   
                                                                                         
                                              
   
                                                             
   
                                                                                                                                     
    
	public function ranking(string $network = '', int $limit = 50): array {
		$followers = [];
		foreach ($this->accounts->listAccounts()['data'] as $account) {
			$id = (string)($account['id'] ?? $account['uuid'] ?? '');
			$followers[$id] = (int)($account['followerCount'] ?? 0);
		}

		$rows = [];
		foreach ($this->publications->findAll() as $publication) {
			if ($network !== '' && (string)($publication['network'] ?? '') !== $network) {
				continue;
			}

			$rows[] = $this->rankRow(publication: $publication, followers: $followers);
		}

		usort(
			$rows,
			static function (array $left, array $right): int {
				                                                              
				                                                 
				$leftRate = ($left['engagementRate'] ?? -1.0);
				$rightRate = ($right['engagementRate'] ?? -1.0);

				return ($rightRate <=> $leftRate);
			}
		);

		return array_slice($rows, 0, max(1, $limit));
	}               

	   
                                                                  
   
                                                                 
                                                                       
   
                                                 
   
                                                                                                                                     
    
	public function rankRow(array $publication, array $followers): array {
		$metrics = ($publication['metrics'] ?? []);
		if (is_array($metrics) === false) {
			$metrics = [];
		}

		$engagement = (
			(int)($metrics['likes'] ?? 0)
			+ (int)($metrics['comments'] ?? 0)
			+ (int)($metrics['shares'] ?? 0)
		);
		$accountId = (string)($publication['accountId'] ?? '');
		$audience = (int)($followers[$accountId] ?? 0);

		                                                                        
		                                                                 
		$rate = null;
		if ($audience > 0) {
			$rate = round(($engagement / $audience), 6);
		}

		return [
			'publicationId' => $this->publications->idOf(publication: $publication),
			'postId' => (string)($publication['postId'] ?? ''),
			'accountId' => $accountId,
			'network' => (string)($publication['network'] ?? ''),
			'url' => (string)($publication['url'] ?? ''),
			'publishedAt' => (string)($publication['publishedAt'] ?? ''),
			'metrics' => $metrics,
			'engagement' => $engagement,
			'followerCount' => $audience,
			'engagementRate' => $rate,
		];
	}               

	   
                                             
   
                                                                             
                                                                             
                             
   
                                               
                                                                        
                                                                                   
   
                                               
   
                                                                                                                                     
    
	private function read(SocialAdapterCall $call, SocialNetworkAdapter $adapter, array $account): SocialGatewayResult {
		$cost = $adapter->costPerRead();
		if ($cost > 0.0) {
			$allowed = $this->budget->canSend(
				tenantId: $this->tenantId(),
				providerId: $adapter->network(),
				estimatedCostEur: $cost,
			);
			if ($allowed === false) {
				return SocialGatewayResult::failed(
					code: SocialGatewayResult::BUDGET_EXHAUSTED,
					reason: 'The spend budget for ' . $adapter->network() . ' is reached, so nothing was read.',
				);
			}
		}

		$owner = trim((string)($account['ownerUserId'] ?? ''));
		$acting = null;
		if ($owner !== '') {
			$acting = $owner;
		}

		$result = $this->gateway->request(
			credentialRef: (string)($account['credentialRef'] ?? ''),
			method: $call->method,
			path: $call->path,
			headers: $call->requestHeaders(),
			body: $call->body(),
			actingUserId: $acting,
		);

		if ($cost > 0.0 && $result->accepted === true) {
			$this->budget->recordSend(
				tenantId: $this->tenantId(),
				providerId: $adapter->network(),
				costEur: $cost,
			);
		}

		return $result;
	}            

	   
                                                                         
                        
   
                                 
    
	private function tenantId(): string {
		$tenantId = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}                
}           
