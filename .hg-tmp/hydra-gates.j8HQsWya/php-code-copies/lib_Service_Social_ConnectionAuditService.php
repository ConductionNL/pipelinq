<?php

   
                                   
  
                                                                            
                                                                            
  
                                                                              
                                                                               
                                                                              
                                                                               
                                                                               
                                                                              
                 
  
                                                                            
                                                                               
                                                                             
                           
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                       
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCA\Pipelinq\Service\Competitor\FediverseWatchReader;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;

   
                                                               
  
                                                                                                                                                                       
  
                                                                          
                                                                          
                                                                          
                                                                         
                                                                        
                                   
   
class ConnectionAuditService {

	   
                                       
   
               
    
	public const SCHEMA_SLUG = 'socialConnection';

	   
                                                                
   
                           
    
	public const ANSWERABLE = ['mastodon', 'bluesky'];

	   
                                                                        
                                                                        
   
                              
    
	public const UNANSWERABLE = [
		'linkedin' => 'LinkedIn exposes follower counts, not a list an audit can search.',
		'x' => 'X puts follower lookup behind a paid API tier this instance does not use.',
		'facebook' => 'Meta exposes no follower list for a page.',
		'instagram' => 'Meta exposes no follower list for a business account.',
		'threads' => 'Threads exposes no follower list.',
	];

	   
                                           
   
            
    
	public const MAX_FOLLOWS = 200;

	   
                
   
                                                                
                                                            
                                                               
   
                                                                                                                                                                        
    
	public function __construct(
		private ListObjectStore $store,
		private ConnectorEgress $egress,
		private ITimeFactory $time,
	) {
	}                   

	   
                          
   
                                                      
   
                                                                                                                                                                                        
    
	public function rows(): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug());
	}            

	   
                                                              
   
                                                          
   
                                                                                                                                                                        
    
	public function run(): array {
		$accounts = $this->accountsByNetwork();
		$summary = ['pairs' => 0, 'answered' => 0, 'unknown' => 0];
		foreach ($this->store->findAll(schemaSlug: 'client') as $client) {
			foreach ($this->handlesOf(client: $client) as $profile) {
				$network = (string)$profile['network'];
				$account = ($accounts[$network] ?? null);
				if ($account === null) {
					continue;
				}

				$row = $this->audit(account: $account, client: $client, profile: $profile);
				$this->persist(row: $row);
				$summary['pairs']++;
				if ($row['weFollowThem'] === 'unknown' && $row['theyFollowUs'] === 'unknown') {
					$summary['unknown']++;
					continue;
				}

				$summary['answered']++;
			}
		}

		return $summary;
	}           

	   
                   
   
                                                               
                                                   
                                                                                      
   
                                         
   
                                                                                                                                                                        
    
	public function audit(array $account, array $client, array $profile): array {
		$network = $profile['network'];
		$row = [
			'accountId' => $this->store->idOf(payload: $account),
			'network' => $network,
			'clientId' => $this->store->idOf(payload: $client),
			'counterpartHandle' => $profile['handle'],
			'counterpartUrl' => $profile['url'],
			'weFollowThem' => 'unknown',
			'theyFollowUs' => 'unknown',
			'reason' => (self::UNANSWERABLE[$network] ?? 'This network does not publish a follower list.'),
			'seenAt' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
		];

		if (in_array($network, self::ANSWERABLE, true) === false) {
			return $row;
		}

		$following = $this->listOf(network: $network, account: $account, direction: 'following');
		$followers = $this->listOf(network: $network, account: $account, direction: 'followers');
		if ($following['readable'] === false && $followers['readable'] === false) {
			$row['reason'] = $following['reason'];
			return $row;
		}

		$handle = mb_strtolower(ltrim($profile['handle'], '@'), 'UTF-8');
		$row['reason'] = '';
		$row['weFollowThem'] = $this->verdict(list: $following, handle: $handle);
		$row['theyFollowUs'] = $this->verdict(list: $followers, handle: $handle);
		if ($row['weFollowThem'] === 'unknown') {
			$row['reason'] = $following['reason'];
		}

		if ($row['theyFollowUs'] === 'unknown' && $row['reason'] === '') {
			$row['reason'] = $followers['reason'];
		}

		return $row;
	}             

	   
                                                                    
   
                                                                                             
                                                                                    
   
                                            
    
	private function verdict(array $list, string $handle): string {
		if ($list['readable'] === false) {
			return 'unknown';
		}

		if (in_array($handle, $list['handles'], true) === true) {
			return 'yes';
		}

		return 'no';
	}               

	   
                                              
   
                                                   
                                                     
                                                        
   
                                                                              
    
	private function listOf(string $network, array $account, string $direction): array {
		if ($network === 'bluesky') {
			return $this->blueskyList(account: $account, direction: $direction);
		}

		return $this->mastodonList(account: $account, direction: $direction);
	}              

	   
                                                                      
   
                                                     
                                                        
   
                                                                              
    
	private function mastodonList(array $account, string $direction): array {
		$handle = ltrim(trim((string)($account['handle'] ?? '')), '@');
		if (str_contains($handle, '@') === false) {
			return ['readable' => false, 'handles' => [], 'reason' => 'The connected Mastodon handle does not name an instance.'];
		}

		[$user, $instance] = explode('@', $handle, 2);
		$base = ('https://' . $instance);
		$lookup = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/lookup'),
			config: ['query' => ['acct' => ($user . '@' . $instance)]]
		);
		$id = trim((string)(($lookup->json() ?? [])['id'] ?? ''));
		if ($lookup->succeeded === false || $id === '') {
			return ['readable' => false, 'handles' => [], 'reason' => 'The instance ' . $instance . ' did not answer the account lookup.'];
		}

		$list = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: ($base . '/api/v1/accounts/' . rawurlencode($id) . '/' . $direction),
			config: ['query' => ['limit' => (string)self::MAX_FOLLOWS]]
		);
		$rows = $list->json();
		if ($list->succeeded === false || $rows === null) {
			return ['readable' => false, 'handles' => [], 'reason' => 'The instance ' . $instance . ' does not publish this list.'];
		}

		$handles = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$acct = mb_strtolower(trim((string)($row['acct'] ?? '')), 'UTF-8');
			if ($acct !== '') {
				$handles[] = $acct;
			}
		}

		return ['readable' => true, 'handles' => $handles, 'reason' => ''];
	}                    

	   
                                                                     
   
                                                     
                                                        
   
                                                                              
    
	private function blueskyList(array $account, string $direction): array {
		$handle = ltrim(trim((string)($account['handle'] ?? '')), '@');
		if ($handle === '') {
			return ['readable' => false, 'handles' => [], 'reason' => 'The connected Bluesky account has no handle.'];
		}

		$method = 'app.bsky.graph.getFollows';
		$key = 'follows';
		if ($direction === 'followers') {
			$method = 'app.bsky.graph.getFollowers';
			$key = 'followers';
		}

		$list = $this->egress->readUrl(
			configKey: CompetitorWatchService::SOURCE_KEY,
			url: (FediverseWatchReader::BLUESKY_APPVIEW . '/xrpc/' . $method),
			config: ['query' => ['actor' => $handle, 'limit' => (string)self::MAX_FOLLOWS]]
		);
		$decoded = $list->json();
		if ($list->succeeded === false || is_array($decoded[$key] ?? null) === false) {
			return ['readable' => false, 'handles' => [], 'reason' => 'The Bluesky AppView did not answer the ' . $direction . ' list.'];
		}

		$handles = [];
		foreach ((array)$decoded[$key] as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$actor = mb_strtolower(trim((string)($row['handle'] ?? '')), 'UTF-8');
			if ($actor !== '') {
				$handles[] = $actor;
			}
		}

		return ['readable' => true, 'handles' => $handles, 'reason' => ''];
	}                   

	   
                                                                         
                                                                        
                                                                    
   
                                               
    
	private function accountsByNetwork(): array {
		$out = [];
		foreach ($this->store->findAll(schemaSlug: 'socialAccount') as $account) {
			$network = trim((string)($account['network'] ?? ''));
			if ($network !== '' && array_key_exists($network, $out) === false) {
				$out[$network] = $account;
			}
		}

		return $out;
	}                         

	   
                                        
   
                                                   
   
                                                                           
    
	private function handlesOf(array $client): array {
		$profiles = ($client['socialProfiles'] ?? []);
		if (is_array($profiles) === false) {
			return [];
		}

		$out = [];
		foreach ($profiles as $profile) {
			if (is_array($profile) === false) {
				continue;
			}

			$handle = trim((string)($profile['handle'] ?? ''));
			$network = trim((string)($profile['network'] ?? ''));
			if ($handle === '' || $network === '') {
				continue;
			}

			$out[] = ['network' => $network, 'handle' => $handle, 'url' => trim((string)($profile['url'] ?? ''))];
		}

		return $out;
	}                 

	   
                                                                   
   
                                             
   
                
    
	private function persist(array $row): void {
		$existing = $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['network' => (string)$row['network'], 'counterpartHandle' => (string)$row['counterpartHandle']]
		);
		$id = null;
		foreach ($existing as $found) {
			$id = $this->store->idOf(payload: $found);
			break;
		}

		$this->store->save(schemaSlug: $this->schemaSlug(), payload: $row, id: $id);
	}               

	   
                           
   
                  
   
                                                                                                                                                                        
    
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: 'socialConnection_schema', default: self::SCHEMA_SLUG);
	}                  
}           
