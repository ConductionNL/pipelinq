<?php

   
                            
  
                                                                          
                                                                         
                                                                         
                            
  
                                                                      
                                         
  
                                                                           
                                                                        
                                                                     
                                                                       
                                                                         
                                       
                                                                        
                                                                         
                                                   
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\IAppConfig;

   
                                                              
  
                                                                                                                                                         
   
class CampaignService {

	   
                                                   
   
               
    
	public const SCHEMA_SLUG = 'campaign';

	   
                                                           
   
               
    
	public const SCHEMA_CONFIG_KEY = 'campaign_schema';

	   
                                                           
   
               
    
	public const SOURCES_CONFIG_KEY = 'campaign.utm_sources';

	   
                                                           
   
               
    
	public const MEDIUMS_CONFIG_KEY = 'campaign.utm_mediums';

	   
                                            
   
                           
    
	public const DEFAULT_SOURCES = [
		'email',
		'nieuwsbrief',
		'linkedin',
		'mastodon',
		'bluesky',
		'x',
		'facebook',
		'instagram',
		'google',
		'website',
		'print',
		'beurs',
	];

	   
                                                                         
   
                           
    
	public const DEFAULT_MEDIUMS = ['email', 'social', 'cpc', 'organic', 'referral', 'display', 'print', 'event'];

	   
                
   
                                                                
                                                     
                                                                                               
   
                                                                                                                                                          
    
	public function __construct(
		private ListObjectStore $store,
		private IAppConfig $appConfig,
		private CampaignLinkDecorator $decorator,
	) {
	}                   

	   
                                                               
   
                            
   
                                                                                                                                                          
    
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}                  

	   
                               
   
                                            
   
                                                            
   
                                                                                                                                                          
    
	public function find(string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		return $this->store->find(schemaSlug: $this->schemaSlug(), id: trim($id));
	}            

	   
                                         
   
                                                            
   
                                                           
   
                                                                                                                                                          
    
	public function all(array $filters = []): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug(), filters: $filters);
	}           

	   
                                                                      
                                                                    
                                                                      
                             
   
                                                     
   
                                                                 
   
                                                                                                                                                         
    
	public function forBlast(array $blast): array {
		$campaignId = trim((string)($blast['campaignId'] ?? ''));
		if ($campaignId === '') {
			return [];
		}

		return ($this->find(id: $campaignId) ?? []);
	}                

	   
                                         
   
                                                                    
                                                                      
                                                                   
   
                                                                           
                                                    
   
                                                                                                                                                          
    
	public function vocabularies(): array {
		return [
			'sources' => $this->vocabulary(configKey: self::SOURCES_CONFIG_KEY, defaults: self::DEFAULT_SOURCES),
			'mediums' => $this->vocabulary(configKey: self::MEDIUMS_CONFIG_KEY, defaults: self::DEFAULT_MEDIUMS),
		];
	}                    

	   
                                      
   
                                                                   
                                                                  
   
                                          
   
                                                         
   
                                                                                                                                                          
    
	public function mint(string $name): string {
		return $this->decorator->slug(value: $name);
	}            

	   
                                
   
                                                                  
                                                                      
                                                                      
                                                               
                                                                  
                             
   
                                                             
                                                              
                                                  
   
                                                                                                                 
                                     
   
                                                                                                                                                          
    
	public function save(array $payload, ?string $id = null, string $uid = ''): array {
		$existing = null;
		if ($id !== null && trim($id) !== '') {
			$existing = $this->find(id: $id);
			if ($existing === null) {
				return $this->failure(error: 'not_found', value: (string)$id, allowed: []);
			}
		}

		$name = trim((string)($payload['name'] ?? ($existing['name'] ?? '')));
		if ($name === '') {
			return $this->failure(error: 'name_required', value: '', allowed: []);
		}

		$check = $this->checkVocabulary(payload: $payload);
		if ($check['error'] !== '') {
			return $check;
		}

		$record = array_merge(($existing ?? []), $payload);
		$record['name'] = $name;
		$record['utmCampaign'] = $this->frozenSlug(existing: $existing, name: $name);
		if ($existing === null) {
			$record['createdAt'] = gmdate('Y-m-d\TH:i:sP');
			$record['createdBy'] = $uid;
		}

		unset($record['@self'], $record['id'], $record['uuid'], $record['slug']);

		$saved = $this->store->save(schemaSlug: $this->schemaSlug(), payload: $record, id: $id);
		if ($saved === null) {
			return $this->failure(error: 'write_failed', value: $name, allowed: []);
		}

		return ['error' => '', 'value' => '', 'allowed' => [], 'campaign' => $saved];
	}            

	   
                                                                      
   
                                      
                                                                                                   
                                                
   
                                                                                        
   
                                                                                                                                            
    
	public function recordLandingPage(string $id, array $landingPage, string $formId): ?array {
		$campaign = $this->find(id: $id);
		if ($campaign === null) {
			return null;
		}

		$campaign['landingPage'] = $landingPage;
		$campaign['formRef'] = $formId;
		unset($campaign['@self'], $campaign['id'], $campaign['uuid'], $campaign['slug']);

		return $this->store->save(schemaSlug: $this->schemaSlug(), payload: $campaign, id: $id);
	}                         

	   
                                       
   
                                                            
   
                                              
   
                                                                                                                                                          
    
	public function idOf(?array $campaign): string {
		return $this->store->idOf(payload: $campaign);
	}            

	   
                                                                    
                                 
   
                                                                   
                                          
   
                            
    
	private function frozenSlug(?array $existing, string $name): string {
		$stored = trim((string)($existing['utmCampaign'] ?? ''));
		if ($stored !== '') {
			return $stored;
		}

		return $this->mint(name: $name);
	}                  

	   
                                                                 
   
                                                             
   
                                                                                                                 
                                                   
    
	private function checkVocabulary(array $payload): array {
		$vocabularies = $this->vocabularies();
		$checks = [
			['key' => 'utmSource', 'error' => 'unknown_utm_source', 'allowed' => $vocabularies['sources']],
			['key' => 'utmMedium', 'error' => 'unknown_utm_medium', 'allowed' => $vocabularies['mediums']],
		];

		foreach ($checks as $check) {
			$value = trim((string)($payload[$check['key']] ?? ''));
			if ($value === '') {
				continue;
			}

			if (in_array($value, $check['allowed'], true) === false) {
				return $this->failure(error: $check['error'], value: $value, allowed: $check['allowed']);
			}
		}

		return ['error' => '', 'value' => '', 'allowed' => [], 'campaign' => null];
	}                       

	   
                                                                 
   
                                                     
                                             
                                                                         
   
                                                                                                                 
    
	private function failure(string $error, string $value, array $allowed): array {
		return ['error' => $error, 'value' => $value, 'allowed' => $allowed, 'campaign' => null];
	}               

	   
                                                      
   
                                                
                                                                         
   
                                                                    
    
	private function vocabulary(string $configKey, array $defaults): array {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, $configKey, ''));
		if ($raw === '') {
			return $defaults;
		}

		$values = [];
		foreach (explode(',', $raw) as $part) {
			$value = strtolower(trim($part));
			if ($value !== '' && in_array($value, $values, true) === false) {
				$values[] = $value;
			}
		}

		if ($values === []) {
			return $defaults;
		}

		return $values;
	}                  
}           
