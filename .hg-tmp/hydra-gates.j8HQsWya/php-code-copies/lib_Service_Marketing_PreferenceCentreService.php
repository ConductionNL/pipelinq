<?php

   
                                    
  
                                                                            
                                                                           
                                  
  
                                                                            
                                                                     
                                                                            
                                                                            
                       
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                     
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\SubscriptionService;

   
                                                                       
  
                                                                                                                     
   
class PreferenceCentreService {
	   
                
   
                                                                         
                                                                  
                                                              
                                                                       
   
                                                                                                                      
    
	public function __construct(
		private ListTokenService $tokens,
		private MailingListService $lists,
		private SubscriptionQueryService $queries,
		private SubscriptionService $subscriptions,
	) {
	}                   

	   
                                                  
   
                                                                         
                                                
   
                                                  
   
                                
   
                                                                                                                      
    
	public function preferencesUrlFor(string $contactId): string {
		return $this->tokens->preferencesUrl(
			token: $this->tokens->signPreferencesToken(contactId: $contactId),
		);
	}                         

	   
                                                       
   
                                                                         
                                                                       
                                                                         
   
                                                      
   
                                                                                                   
                                            
   
                                                                                                                      
    
	public function preferencesForToken(string $token): ?array {
		$contactId = $this->contactIdFor(token: $token);
		if ($contactId === null) {
			return null;
		}

		$states = [];
		foreach ($this->queries->listSubscriptionsForContact(contactId: $contactId) as $subscription) {
			$states[(string)($subscription['listId'] ?? '')] = (string)($subscription['state'] ?? '');
		}

		$out = [];
		foreach ($this->lists->listMailingLists(page: 1, limit: 100)['data'] as $list) {
			if ((string)($list['status'] ?? 'active') === 'archived') {
				continue;
			}

			$projection = $this->lists->publicProjection(list: $list);
			$out[] = [
				'id' => $projection['id'],
				'name' => $projection['name'],
				'description' => $projection['description'],
				'subscribed' => (($states[$projection['id']] ?? '') === SubscriptionService::STATE_CONFIRMED),
			];
		}

		return $out;
	}                           

	   
                             
   
                                                                          
                                                                         
                                                    
   
                                                      
                                                                          
   
                                                                    
   
                                                                                                                      
    
	public function savePreferences(string $token, array $selectedListIds): array {
		$contactId = $this->contactIdFor(token: $token);
		if ($contactId === null) {
			return ['status' => 'invalid', 'confirmed' => 0, 'unsubscribed' => 0];
		}

		$existing = $this->queries->listSubscriptionsForContact(contactId: $contactId);
		$selected = array_flip(array_map('strval', $selectedListIds));

		                                                                     
		                                                                      
		                                                                     
		$email = '';
		foreach ($existing as $subscription) {
			$candidate = (string)($subscription['email'] ?? '');
			if ($candidate !== '') {
				$email = $candidate;
				break;
			}
		}

		$unsubscribed = 0;
		foreach ($existing as $subscription) {
			$listId = (string)($subscription['listId'] ?? '');
			if (isset($selected[$listId]) === true) {
				continue;
			}

			$closed = $this->subscriptions->unsubscribeFromList(
				listId: $listId,
				contactId: $contactId,
				reason: 'preference-centre',
			);
			if ($closed === true) {
				$unsubscribed++;
			}
		}

		$confirmed = 0;
		foreach (array_keys($selected) as $listId) {
			$done = $this->subscriptions->confirmForContact(
				listId: (string)$listId,
				contactId: $contactId,
				email: $email,
			);
			if ($done === true) {
				$confirmed++;
			}
		}

		return ['status' => 'saved', 'confirmed' => $confirmed, 'unsubscribed' => $unsubscribed];
	}                       

	   
                                                      
   
                                                      
   
                                                              
    
	private function contactIdFor(string $token): ?string {
		$payload = $this->tokens->verify(token: $token, purpose: ListTokenService::PURPOSE_PREFERENCES);
		if ($payload === null) {
			return null;
		}

		$contactId = (string)($payload['c'] ?? '');
		if ($contactId === '') {
			return null;
		}

		return $contactId;
	}                    
}           
