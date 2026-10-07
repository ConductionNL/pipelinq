<?php

   
                                     
  
                                                                        
                                                                          
                            
  
                                                                       
                                                                           
                                                                        
                                                                           
                                        
  
                    
                                           
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\SubscriptionService;

   
                                                                      
  
                                                                                                         
   
class SubscriptionQueryService {
	   
                                        
   
               
    
	private const CHANNEL = 'email';

	   
                                                                     
   
               
    
	private const DEFAULT_SUBSCRIPTION_SCHEMA_SLUG = 'subscription';

	   
                
   
                                                                              
                                                            
                                                                             
   
                                                                                                          
    
	public function __construct(
		private ListObjectStore $store,
		private ComplianceService $compliance,
		private ListTokenService $tokens,
	) {
	}                   

	   
                                                                      
                
   
                                                                         
                                                                        
                                                                      
                                                                         
                                                 
   
                                                                         
                                                                         
                                                                       
   
                                                   
   
                  
                                                                                                                     
                                   
     
   
                                                                                                          
    
	public function getBlastAudienceForList(string $listId): array {
		if ($listId === '') {
			return ['members' => [], 'skipped' => []];
		}

		$members = [];
		$skipped = [];
		foreach ($this->rowsForList(listId: $listId) as $subscription) {
			$contactId = $this->contactKeyFor(subscription: $subscription);
			if ((string)($subscription['state'] ?? '') !== SubscriptionService::STATE_CONFIRMED) {
				$skipped[] = $contactId;
				continue;
			}

			$permitted = $this->compliance->hasConsentForList(
				contactId: $contactId,
				listId: $listId,
				channel: self::CHANNEL,
			);
			if ($permitted === false) {
				$skipped[] = $contactId;
				continue;
			}

			$subscriptionId = $this->store->idOf(payload: $subscription);
			$members[] = [
				'contactId' => $contactId,
				'email' => (string)($subscription['email'] ?? ''),
				'subscriptionId' => $subscriptionId,
				'unsubscribeUrl' => $this->unsubscribeUrlFor(
					subscriptionId: $subscriptionId,
					contactId: $contactId,
				),
			];
		}

		return ['members' => $members, 'skipped' => $skipped];
	}                               

	   
                                                        
   
                                                                      
                                                                        
                                                                          
                                  
   
                                                                         
                                                       
   
                                                            
                                                                        
                                                                        
   
                                                                     
                           
   
                                                                                                           
    
	public function unsubscribeUrlFor(string $subscriptionId, string $contactId): string {
		if ($subscriptionId === '' || $contactId === '') {
			return '';
		}

		return $this->tokens->unsubscribeUrl(
			token: $this->tokens->signUnsubscribeToken(
				subscriptionId: $subscriptionId,
				contactId: $contactId,
			),
		);
	}                         

	   
                                  
   
                                                   
   
                                                                                            
   
                                                                                                                           
    
	public function countsForList(string $listId): array {
		$counts = [
			SubscriptionService::STATE_PENDING => 0,
			SubscriptionService::STATE_CONFIRMED => 0,
			SubscriptionService::STATE_UNSUBSCRIBED => 0,
			SubscriptionService::STATE_BOUNCED => 0,
		];

		$total = 0;
		foreach ($this->rowsForList(listId: $listId) as $subscription) {
			$total++;
			$state = (string)($subscription['state'] ?? '');
			if (isset($counts[$state]) === true) {
				$counts[$state]++;
			}
		}

		$counts['total'] = $total;
		return $counts;
	}                     

	   
                                                            
   
                                                   
                                         
                                                 
   
                                                                                                                           
   
                                                                                                                           
    
	public function listSubscriptionsForList(string $listId, int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->rowsForList(listId: $listId);
		$total = count($all);

		$pages = 0;
		if ($total > 0) {
			$pages = (int)ceil($total / $limit);
		}

		return [
			'data' => array_slice($all, (($page - 1) * $limit), $limit),
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $pages,
			],
		];
	}                                

	   
                                       
   
                                                  
   
                                                                   
   
                                                                                                                      
    
	public function listSubscriptionsForContact(string $contactId): array {
		if ($contactId === '') {
			return [];
		}

		return $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['contactId' => $contactId],
		);
	}                                   

	   
                                 
   
                                                   
   
                                                                   
    
	private function rowsForList(string $listId): array {
		if ($listId === '') {
			return [];
		}

		return $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['listId' => $listId],
		);
	}                   

	   
                                                                 
   
                                                                     
                                                               
                                                                         
   
                                                             
   
                                   
    
	private function contactKeyFor(array $subscription): string {
		$contactId = (string)($subscription['contactId'] ?? '');
		if ($contactId !== '') {
			return $contactId;
		}

		return (string)($subscription['email'] ?? '');
	}                     

	   
                                         
   
                               
    
	private function schemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'subscription_schema',
			default: self::DEFAULT_SUBSCRIPTION_SCHEMA_SLUG,
		);
	}                  
}           
