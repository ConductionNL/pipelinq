<?php

   
                                     
  
                                                                        
                                                                             
                                                                   
                                                                              
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                              
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                            
  
                                                                                                                                                              
   
class IntegriqMarketingConsent {

	   
                                                                                  
   
                          
    
	public const BOUNCE_REASONS = ['bounce-hard', 'bounce-soft-x5'];

	   
                                    
   
                             
    
	private const STATE_SUFFIX = ['opted-in' => 'in', 'opted-out' => 'out'];

	   
                
   
                                                             
                                                                         
    
	public function __construct(
		private readonly IntegriqConsentClient $integriq,
		private readonly ContactAddressLookup $addresses,
	) {
	}                   

	   
                                 
   
                                                                         
   
                                                                                                                                                                   
    
	public function isActive(): bool {
		return $this->integriq->isCutover();
	}                

	   
                                                    
   
                                                     
   
                                                                
   
                                                                                                                                                               
    
	public function categoryFor(string $intent): array {
		if ($intent === ComplianceService::INTENT_SERVICE) {
			return [IntegriqConsentClient::CATEGORY_SERVICE, false];
		}

		return [IntegriqConsentClient::CATEGORY_MARKETING, true];
	}                   

	   
                                   
   
                                                                                        
                                                         
                                                     
                                                                     
                                                                  
   
                                                                                                         
   
                                                                                              
   
                                                                                                                                                               
    
	public function decide(string $contactId, string $channel, string $category, bool $requiresConsent, ?string $listId = null): array {
		return $this->integriq->decideOne(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			address: $this->addresses->addressFor(contactId: $contactId, channel: $channel),
			contactRef: $this->contactRefOf(contactId: $contactId),
			listRef: (string)$listId,
		);
	}              

	   
                                                                  
   
                                                                                                             
                                                                     
                                                                 
                                                                                        
   
                                                                                                                        
   
                                                                                              
   
                                                                                                                                                               
    
	public function decideMembers(array $members, string $channel, string $category, bool $requiresConsent): array {
		$recipients = [];
		$byAddress = [];
		foreach ($members as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '') {
				continue;
			}

			$address = '';
			if ($channel === 'email') {
				$address = trim((string)($member['email'] ?? ''));
			}

			if ($address === '') {
				$address = $this->addresses->addressFor(contactId: $contactId, channel: $channel);
			}

			if ($address === '') {
				continue;
			}

			$recipients[] = ['address' => $address, 'contactRef' => $this->contactRefOf(contactId: $contactId)];
			$byAddress[$address][] = $contactId;
		}             

		$out = [];
		$decisions = $this->integriq->decide(channel: $channel, category: $category, requiresConsent: $requiresConsent, recipients: $recipients);
		foreach ($decisions as $address => $decision) {
			foreach (($byAddress[$address] ?? []) as $contactId) {
				$out[$contactId] = $decision;
			}
		}

		return $out;
	}                     

	   
                                 
   
                                                                              
                                                     
                                                        
                                                                    
                                                                      
                                                                    
   
                                                                        
   
                                                                                                                                               
    
	public function recordListConsent(
		string $contactId,
		string $listId,
		string $channel,
		string $lawfulBasis,
		string $source,
		array $evidence,
	): bool {
		$outcome = $this->integriq->record(
			request: [
				'address' => $this->addresses->addressFor(contactId: $contactId, channel: $channel),
				'state' => 'opted-in',
				'scope' => 'list',
				'channel' => $channel,
				'ref' => $listId,
				'contactRef' => $this->contactRefOf(contactId: $contactId),
				'lawfulBasis' => $lawfulBasis,
				'evidence' => $evidence,
				'source' => $source,
				'purpose' => IntegriqConsentClient::CATEGORY_MARKETING,
			]
		);

		return $outcome['recorded'];
	}                         

	   
                                                                                
   
                                                                    
                                              
                                                        
                                                                    
   
                                                                        
   
                                                                                                                                               
    
	public function recordWithdrawal(string $contactId, string $channel, string $reason, ?string $listId = null): bool {
		if (in_array($reason, self::BOUNCE_REASONS, true) === true) {
			return false;
		}

		$scope = 'channel';
		if ($listId !== null && $listId !== '') {
			$scope = 'list';
		}

		$outcome = $this->integriq->record(
			request: [
				'address' => $this->addresses->addressFor(contactId: $contactId, channel: $channel),
				'state' => 'opted-out',
				'scope' => $scope,
				'channel' => $channel,
				'ref' => (string)$listId,
				'contactRef' => $this->contactRefOf(contactId: $contactId),
				'source' => $reason,
				'purpose' => IntegriqConsentClient::CATEGORY_MARKETING,
			]
		);

		return $outcome['recorded'];
	}                        

	   
                                                                              
   
                                                                        
                                                                           
                    
   
                                                      
                                             
   
                                                                       
   
                                                                                                                                                                   
    
	public function changeRequestFor(array $row, string $id): ?array {
		$withdrawnAt = trim((string)($row['withdrawnAt'] ?? ''));
		$reason = (string)($row['withdrawnReason'] ?? '');
		if ($withdrawnAt !== '' && in_array($reason, self::BOUNCE_REASONS, true) === true) {
			return null;
		}

		$contactId = (string)($row['contactId'] ?? '');
		$channel = strtolower((string)($row['channel'] ?? ''));
		$listId = trim((string)($row['listId'] ?? ''));
		$state = 'opted-in';
		if ($withdrawnAt !== '') {
			$state = 'opted-out';
		}

		$scope = 'channel';
		if ($listId !== '') {
			$scope = 'list';
		}

		$evidence = ($row['evidence'] ?? []);
		if (is_array($evidence) === false) {
			$evidence = ['text' => (string)$evidence];
		}

		$request = [
			'address' => $this->addresses->addressFor(contactId: $contactId, channel: $channel),
			'state' => $state,
			'scope' => $scope,
			'channel' => $channel,
			'ref' => $listId,
			'contactRef' => $this->contactRefOf(contactId: $contactId),
			'evidence' => $evidence,
			'source' => (string)($row['consentSource'] ?? ''),
			'purpose' => IntegriqConsentClient::CATEGORY_MARKETING,
			                                                                    
			                                                                  
			'legacyRef' => $id.':'.self::STATE_SUFFIX[$state],
		];
		if ($state === 'opted-in') {
			$request['lawfulBasis'] = (string)($row['lawfulBasis'] ?? '');
		}

		if ($state === 'opted-out') {
			$request['source'] = $reason;
		}

		return $request;
	}                        

	   
                                              
   
                                                    
   
                                                                                         
   
                                                                                                                                                                   
    
	public function record(array $request): array {
		return $this->integriq->record(request: $request);
	}              

	   
                                                             
   
                                                     
   
                                                                            
    
	private function contactRefOf(string $contactId): string {
		if (str_contains($contactId, '@') === true) {
			return '';
		}

		return $contactId;
	}                    
}           
