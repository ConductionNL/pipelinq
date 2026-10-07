<?php

   
                               
  
                                                                           
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

   
                                                                       
  
                                                                             
   
class ContactDataBuilder {
	   
                                                                      
                                                                     
                                                                        
                                                                        
                                                                      
                                        
   
                              
    
	private const VCARD_TYPE_TO_KIND = [
		'WORK' => 'work',
		'HOME' => 'private',
		'CELL' => 'mobile',
		'MOBILE' => 'mobile',
		'IPHONE' => 'mobile',
	];

	   
                                                                       
                                          
   
                 
    
	private const SOCIAL_NETWORKS = [
		'linkedin',
		'x',
		'mastodon',
		'bluesky',
		'facebook',
		'instagram',
		'threads',
		'tiktok',
		'youtube',
	];

	   
                                               
   
                                                       
                                       
   
                                                   
   
                                              
                                                                                                                                               
    
	public function buildClientImportData(array $ncContact, string $uid): array {
		$name = $this->extractFirstValue(value: ($ncContact['FN'] ?? 'Unknown'));
		$org = $this->extractFirstValue(value: ($ncContact['ORG'] ?? ''));

		$clientType = $this->determineClientType(name: $name, org: $org);

		if ($name === '' && $org !== '') {
			$name = $org;
		}

		$industry = '';
		if ($clientType === 'person') {
			$industry = $org;
		}

		$emails = $this->extractTypedEntries(value: ($ncContact['EMAIL'] ?? null));
		$phones = $this->extractTypedEntries(value: ($ncContact['TEL'] ?? null));

		$data = [
			'name' => $name,
			'type' => $clientType,
			'email' => $emails[0]['value'] ?? '',
			'phone' => $phones[0]['value'] ?? '',
			'emails' => $emails,
			'phones' => $phones,
			'socialProfiles' => $this->extractSocialProfiles(value: ($ncContact['X-SOCIALPROFILE'] ?? null)),
			'website' => $this->extractFirstValue(value: ($ncContact['URL'] ?? '')),
			'contactsUid' => $uid,
		];

		                                                                
		                                                             
		                                                              
		                                                           
		               
		$data = array_filter($data, fn ($v) => $v !== '');
		$data['name'] = $name;
		$data['type'] = $clientType;

		                                                                        
		                                             
		if ($industry !== '') {
			$data['industry'] = [$industry];
		}

		return $data;
	}                             

	   
                                                       
   
                                                       
                                       
                                                    
   
                                                    
   
                                              
                                                                                                                                               
    
	public function buildContactImportData(array $ncContact, string $uid, ?string $clientId): array {
		$name = $this->extractFirstValue(value: ($ncContact['FN'] ?? 'Unknown'));

		$emails = $this->extractTypedEntries(value: ($ncContact['EMAIL'] ?? null));
		$phones = $this->extractTypedEntries(value: ($ncContact['TEL'] ?? null));

		$data = [
			'name' => $name,
			'email' => $emails[0]['value'] ?? '',
			'phone' => $phones[0]['value'] ?? '',
			'emails' => $emails,
			'phones' => $phones,
			'socialProfiles' => $this->extractSocialProfiles(value: ($ncContact['X-SOCIALPROFILE'] ?? null)),
			'role' => $this->extractFirstValue(value: ($ncContact['ROLE'] ?? $ncContact['TITLE'] ?? '')),
			'contactsUid' => $uid,
		];

		if ($clientId !== null && $clientId !== '') {
			$data['client'] = $clientId;
		}

		                                                                 
		                                    
		$data = array_filter($data, fn ($v) => $v !== '');
		$data['name'] = $name;

		return $data;
	}                              

	   
                                                                 
                                                                     
                                                                     
                                                                     
                                                                    
                                                                       
                                        
   
                                                                
   
                                                                                  
   
                                                                                                                                               
    
	private function extractTypedEntries(mixed $value): array {
		$items = $this->normaliseToList(value: $value);

		$entries = [];
		foreach ($items as $item) {
			$raw = (string)$item;
			if (is_array($item) === true) {
				$raw = (string)($item['value'] ?? '');
			}

			if ($raw === '') {
				continue;
			}

			$type = '';
			if (is_array($item) === true) {
				$type = (string)($item['type'] ?? '');
			}

			$entries[] = [
				'kind' => $this->mapVcardTypeToKind(type: $type),
				'value' => $raw,
				'primary' => ($entries === []),
				'verified' => false,
			];
		}

		return $entries;
	}                           

	   
                                                                      
                                                                       
                                                               
                                                                
            
   
                                                                 
   
                                                                                                                     
   
                                                                                                                                               
    
	private function extractSocialProfiles(mixed $value): array {
		$items = $this->normaliseToList(value: $value);

		$profiles = [];
		foreach ($items as $item) {
			$raw = (string)$item;
			if (is_array($item) === true) {
				$raw = (string)($item['value'] ?? '');
			}

			if ($raw === '') {
				continue;
			}

			$typeRaw = '';
			if (is_array($item) === true) {
				$typeRaw = (string)($item['type'] ?? '');
			}

			$typeRaw = strtolower($typeRaw);

			$network = 'other';
			if (in_array($typeRaw, self::SOCIAL_NETWORKS, true) === true) {
				$network = $typeRaw;
			}

			$isUrl = (str_starts_with($raw, 'http://') === true || str_starts_with($raw, 'https://') === true);

			$handle = $raw;
			$url = '';
			if ($isUrl === true) {
				$handle = '';
				$url = $raw;
			}

			$profiles[] = [
				'network' => $network,
				'handle' => $handle,
				'url' => $url,
				'verified' => false,
				'followedByUs' => false,
				'followsUs' => false,
			];
		}

		return $profiles;
	}                             

	   
                                                                   
                                                                     
                         
   
                                                     
   
                                                                             
    
	private function normaliseToList(mixed $value): array {
		if ($value === null || $value === '') {
			return [];
		}

		if (is_array($value) === false) {
			return [$value];
		}

		                                                                   
		                                                                  
		                                                     
		if (array_key_exists('value', $value) === true && array_key_exists(0, $value) === false) {
			return [$value];
		}

		return $value;
	}                       

	   
                                                                    
                                                                     
                                                                        
   
                                                     
   
                                                 
    
	private function mapVcardTypeToKind(string $type): string {
		foreach (explode(',', $type) as $part) {
			$kind = self::VCARD_TYPE_TO_KIND[strtoupper(trim($part))] ?? null;
			if ($kind !== null) {
				return $kind;
			}
		}

		return 'other';
	}                          

	   
                                                           
   
                                         
                                             
   
                                                            
    
	private function determineClientType(string $name, string $org): string {
		if ($org !== '' && ($org === $name || $name === '')) {
			return 'organization';
		}

		return 'person';
	}                           

	   
                                                                             
   
                                                  
   
                                              
    
	private function extractFirstValue(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value[0] ?? '');
		}

		return (string)$value;
	}                         
}           
