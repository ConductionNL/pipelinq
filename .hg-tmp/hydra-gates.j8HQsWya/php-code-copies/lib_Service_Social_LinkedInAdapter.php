<?php

   
                            
  
                                                                               
                                                                           
                                                                              
                                                                             
                       
  
                                                                              
                                                                             
                                                                         
                                                                          
                                                                               
                                                                            
                                         
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                                                                   
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

   
                                     
  
                                                                                                                                               
   
class LinkedInAdapter extends AbstractSocialAdapter {
	   
                                                                          
                                                                      
   
               
    
	public const API_VERSION = '202506';

	   
                                               
   
                                    
   
                                                                                                                                                
    
	public function network(): string {
		return 'linkedin';
	}               

	   
                        
   
                                           
   
                                                                                                                                                  
    
	public function brokerProvider(): string {
		return 'linkedin';
	}                      

	   
                                                  
   
                                    
   
                                                                                                                         
    
	public function bodyLimit(): int {
		return 3000;
	}                 

	   
                                                                       
                 
   
                                                           
   
                                          
   
                                                                                                                                                
    
	public function publishCall(SocialPublishRequest $request): SocialAdapterCall {
		return new SocialAdapterCall(
			method: 'POST',
			path: '/rest/posts',
			payload: [
				'author' => $this->authorUrn(request: $request),
				'commentary' => $request->bodyWithLink(),
				'visibility' => 'PUBLIC',
				'distribution' => [
					'feedDistribution' => 'MAIN_FEED',
					'targetEntities' => [],
					'thirdPartyDistributionChannels' => [],
				],
				'lifecycleState' => 'PUBLISHED',
				'isReshareDisabledByAuthor' => false,
			],
			headers: [
				'LinkedIn-Version' => self::API_VERSION,
				'X-Restli-Protocol-Version' => '2.0.0',
			],
		);
	}                   

	   
                                                                         
                                       
   
                                                           
   
                                  
   
                                                                                                                                                
    
	public function authorUrn(SocialPublishRequest $request): string {
		$id = trim($request->externalAccountId);
		if (str_starts_with($id, 'urn:li:') === true) {
			return $id;
		}

		if ($request->accountKind === 'person') {
			return 'urn:li:person:' . $id;
		}

		return 'urn:li:organization:' . $id;
	}                 

	   
                                                                         
                                                                         
                                            
   
                                                           
   
                                
   
                                                                                                                                                
    
	protected function readPublishedId(array $payload): string {
		foreach (['id', 'urn'] as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}                       

	   
                                                     
   
                                                           
                                                               
   
                                                          
   
                                                                        
                                
   
                                                                                                                                                
    
	protected function readPublishedUrl(array $payload, SocialPublishRequest $request): string {
		$urn = $this->readPublishedId(payload: $payload);
		if ($urn === '') {
			return '';
		}

		return 'https://www.linkedin.com/feed/update/' . rawurlencode($urn) . '/';
	}                        

	   
                                                                          
                       
   
                                           
                                                                      
   
                                                                      
   
                                                                                                                                                
   
                                                                     
                                     
    
	public function metricsCall(string $externalId, array $account): ?SocialAdapterCall {
		if (trim($externalId) === '') {
			return null;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/rest/socialActions/' . rawurlencode($externalId),
			headers: ['LinkedIn-Version' => self::API_VERSION, 'X-Restli-Protocol-Version' => '2.0.0'],
		);
	}                   

	   
                                                                    
                                                                            
                                                                           
                        
   
                                                                   
                                                                          
   
                                                                                                          
   
                                                                                                                                                
   
                                                                 
    
	public function normaliseMetrics(array $payload, string $externalId = ''): array {
		return [
			'views' => 0,
			'likes' => $this->readInt(payload: $payload, path: ['likesSummary', 'totalLikes']),
			'comments' => $this->readInt(payload: $payload, path: ['commentsSummary', 'totalFirstLevelComments']),
			'shares' => 0,
			'clicks' => 0,
		];
	}                        

	   
                                                                       
                                                                         
                                                    
   
                                                         
   
                                                                     
   
                                                                                                                                                
    
	public function followersCall(array $account): ?SocialAdapterCall {
		$id = trim((string)($account['externalAccountId'] ?? ''));
		if ($id === '' || (string)($account['kind'] ?? '') !== 'organisation') {
			return null;
		}

		$urn = $id;
		if (str_starts_with($urn, 'urn:li:') === false) {
			$urn = 'urn:li:organization:' . $urn;
		}

		return new SocialAdapterCall(
			method: 'GET',
			path: '/rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity='
				. rawurlencode($urn),
			headers: ['LinkedIn-Version' => self::API_VERSION, 'X-Restli-Protocol-Version' => '2.0.0'],
		);
	}                     

	   
                                                         
   
                                                                
   
                                   
   
                                                                                                                                                
    
	public function readFollowers(array $payload): int {
		$elements = ($payload['elements'] ?? []);
		if (is_array($elements) === false || $elements === []) {
			return 0;
		}

		$total = 0;
		foreach ($elements as $element) {
			if (is_array($element) === false) {
				continue;
			}

			                                                                 
			                                                              
			                                                                  
			                                                                 
			                                                     
			$total += $this->readInt(payload: $element, path: ['followerCounts', 'organicFollowerCount']);

			$byType = ($element['followerCountsByAssociationType'] ?? []);
			if (is_array($byType) === false) {
				continue;
			}

			foreach ($byType as $entry) {
				if (is_array($entry) === true) {
					$total += $this->readInt(payload: $entry, path: ['followerCounts', 'organicFollowerCount']);
				}
			}
		}

		return $total;
	}                     

	   
                                
   
                                                           
   
                                                      
   
                                                                                                                                                 
    
	public function composerUrl(SocialPublishRequest $request): string {
		if ($request->link !== '') {
			return 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode($request->link);
		}

		return 'https://www.linkedin.com/feed/?shareActive=true';
	}                   
}           
