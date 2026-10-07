<?php

   
                                  
  
                                                    
  
                                                                               
                                                                              
                                                                        
                                                                              
                                                                            
                                                                          
                                      
  
                                                                          
                                                                             
                                                                       
                                                                             
         
  
                                                                             
                                                                          
         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;

   
                                                                 
  
                                                                                                                                                
   
class SocialAdvocacyService {
	   
                                                        
   
               
    
	public const SUBJECT_SHARE_REQUESTED = 'social_share_requested';

	   
                
   
                                                                            
                                                                                        
                                                                                            
                                                                      
   
                
    
	public function __construct(
		private readonly SocialPublicationStore $publications,
		private readonly SocialAccountService $accounts,
		private readonly SocialAdapterRegistry $registry,
		private readonly NotificationService $notifications,
	) {
	}                   

	   
                                                 
   
                                                                 
                                                                   
                                                            
                                                                    
   
                                                                       
   
                                                                                                                                                 
    
	public function requestShare(
		array $publication,
		array $account,
		array $post,
		SocialPublishRequest $request,
	): ?array {
		$publication['status'] = SocialPublicationStore::AWAITING_SHARE;
		$publication['sharePromptedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		                                                                      
		                                                                     
		                              
		$publication['preparedBody'] = $request->bodyWithLink();
		$publication['preparedMedia'] = $request->media;
		$publication['failureCode'] = '';
		$publication['failureReason'] = '';
		$saved = $this->publications->save(publication: $publication);

		$owner = trim((string)($account['ownerUserId'] ?? ''));
		if ($owner === '') {
			return $saved;
		}

		$this->notifications->sendNotification(
			userId: $owner,
			subject: self::SUBJECT_SHARE_REQUESTED,
			parameters: [
				'handle' => (string)($account['handle'] ?? ''),
				'network' => (string)($account['network'] ?? ''),
				'title' => (string)($post['title'] ?? ''),
				'body' => $request->bodyWithLink(),
			],
			objectType: 'socialPublication',
			objectId: $this->publications->idOf(publication: $saved ?? $publication),
		);

		return $saved;
	}                    

	   
                                                                           
                                                
   
                                                 
                                  
   
                                                                                        
   
                                                                                                                                                 
    
	public function shareBundle(string $publicationId, string $uid): array {
		$context = $this->authorised(publicationId: $publicationId, uid: $uid);
		if (isset($context['error']) === true) {
			return ['error' => $context['error']];
		}

		$publication = $context['publication'];
		$account = $context['account'];
		$adapter = $this->registry->forNetwork(network: (string)($account['network'] ?? ''));

		$request = new SocialPublishRequest(
			network: (string)($account['network'] ?? ''),
			body: (string)($publication['preparedBody'] ?? ''),
			handle: (string)($account['handle'] ?? ''),
		);

		$media = ($publication['preparedMedia'] ?? []);
		if (is_array($media) === false) {
			$media = [];
		}

		return [
			'share' => [
				'publicationId' => $publicationId,
				'network' => (string)($account['network'] ?? ''),
				'handle' => (string)($account['handle'] ?? ''),
				'status' => (string)($publication['status'] ?? ''),
				'body' => $request->body,
				'media' => $media,
				'composerUrl' => ($adapter?->composerUrl(request: $request) ?? ''),
			],
		];
	}                   

	   
                                    
   
                                                 
                                                                                       
                                                       
   
                                                                                                   
   
                                                                                                                                                 
    
	public function confirmShare(string $publicationId, string $uid, string $url = ''): array {
		$context = $this->authorised(publicationId: $publicationId, uid: $uid);
		if (isset($context['error']) === true) {
			return ['error' => $context['error']];
		}

		$publication = $context['publication'];
		if ((string)($publication['status'] ?? '') !== SocialPublicationStore::AWAITING_SHARE) {
			return ['error' => 'This publication is not waiting for a share.'];
		}

		$publication['status'] = SocialPublicationStore::SHARED;
		$publication['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		if (trim($url) !== '') {
			$publication['url'] = trim($url);
		}

		$saved = $this->publications->save(publication: $publication);
		if ($saved === null) {
			return ['error' => 'The share could not be recorded.'];
		}

		return ['publication' => $saved];
	}                    

	   
                                                                            
                                   
   
                                                 
                                  
   
                                                                                   
    
	private function authorised(string $publicationId, string $uid): array {
		$publication = $this->publications->find(publicationId: $publicationId);
		if ($publication === null) {
			return ['error' => 'That publication does not exist.'];
		}

		$account = $this->accounts->getAccount(accountId: (string)($publication['accountId'] ?? ''));
		if ($account === null) {
			return ['error' => 'That publication has no account.'];
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'This share belongs to somebody else.'];
		}

		return ['publication' => $publication, 'account' => $account];
	}                  
}           
