<?php

   
                                
  
                                                                                
                                                                                
                                                                           
                                                                             
                                                                               
                                                                    
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                          
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal;

use OCA\Pipelinq\Util\ContrastRatioCalculator;
use OCP\AppFramework\Http;

   
                                                     
  
                                                                                        
                                                                           
                                                                           
                              
   
class PortalTenantService {
	   
                                  
   
               
    
	private const SCHEMA = 'portalTenantConfig';

	   
                                                      
   
               
    
	public const DEFAULT_TENANT = 'default';

	   
                                                                      
   
                           
    
	private const PUBLIC_FIELDS = [
		'tenantId',
		'displayName',
		'logoFileId',
		'faviconFileId',
		'brandPrimaryColor',
		'brandSecondaryColor',
		'brandBackgroundColor',
		'enabledFeatures',
		'b2bEnabled',
		'b2cEnabled',
		'supportEmail',
		'supportPhone',
		'termsVersion',
		'privacyVersion',
	];

	   
                
   
                                                                           
                                                                     
    
	public function __construct(
		private PortalObjectRepository $repository,
		private ContrastRatioCalculator $contrast,
	) {
	}                   

	   
                                                                         
   
                                                                        
                                                                             
                                                                
   
                                                  
                                                                                    
   
                                          
                                                                                         
                                                                            
                                                                            
                               
    
	public function resolveTenantId(?string $host, ?string $widgetTenant): string {
		$host = strtolower(trim((string)$host));

		if ($host !== '') {
			$byDomain = $this->repository->findOneBy(self::SCHEMA, ['customDomain' => $host]);
			if ($byDomain !== null) {
				return (string)($byDomain['tenantId'] ?? self::DEFAULT_TENANT);
			}

			$subdomain = explode('.', $host)[0];
			if ($subdomain !== '' && $subdomain !== $host) {
				$bySubdomain = $this->repository->findOneBy(self::SCHEMA, ['subdomain' => $subdomain]);
				if ($bySubdomain !== null) {
					return (string)($bySubdomain['tenantId'] ?? self::DEFAULT_TENANT);
				}
			}
		}

		if ($widgetTenant !== null && trim($widgetTenant) !== '') {
			return trim($widgetTenant);
		}

		return self::DEFAULT_TENANT;
	}                       

	   
                                                                       
   
                                          
   
                                                          
                                                                                         
                                                                            
                                                                            
                               
    
	public function getConfig(string $tenantId): ?array {
		return $this->repository->findOneBy(self::SCHEMA, ['tenantId' => $tenantId]);
	}                 

	   
                                                                       
                                                          
   
                                                                     
                                                                      
                                           
   
                                          
   
                                                                                                      
                                                                                         
                                                                            
                                                                            
                               
    
	public function getAdminConfig(string $tenantId): array {
		$stored = $this->getConfig(tenantId: $tenantId);
		if ($stored === null) {
			return ['config' => $this->defaults(tenantId: $tenantId), 'configured' => false];
		}

		return ['config' => $stored, 'configured' => true];
	}                      

	   
                                                                            
   
                                          
   
                                                                                
                                                                                         
                                                                            
                                                                            
                               
    
	public function getPublicConfig(string $tenantId): array {
		$config = ($this->getConfig(tenantId: $tenantId) ?? $this->defaults(tenantId: $tenantId));
		$public = [];
		foreach (self::PUBLIC_FIELDS as $field) {
			if (array_key_exists($field, $config) === true) {
				$public[$field] = $config[$field];
			}
		}

		$public['tenantId'] = $tenantId;
		return $public;
	}                       

	   
                                                                          
   
                                          
                                                                             
   
                                                  
   
                                                      
                                                                                         
                                                                            
                                                                            
                               
    
	public function saveConfig(string $tenantId, array $config): array {
		$config['tenantId'] = $tenantId;
		$this->assertContrast(config: $config);

		$existing = $this->getConfig(tenantId: $tenantId);
		$id = null;
		if ($existing !== null) {
			$id = $this->repository->idOf(object: $existing);
		}

		return $this->repository->save(self::SCHEMA, $config, $id);
	}                  

	   
                                              
   
                                          
                                                           
   
                                   
                                                                                         
                                                                            
                                                                            
                               
    
	public function isFeatureEnabled(string $tenantId, string $feature): bool {
		$config = ($this->getConfig(tenantId: $tenantId) ?? $this->defaults(tenantId: $tenantId));
		$features = ($config['enabledFeatures'] ?? []);
		return is_array($features) === true && in_array($feature, $features, true) === true;
	}                        

	   
                                                                 
   
                                          
                                           
   
                
   
                                                         
                                                                                         
                                                                            
                                                                            
                               
    
	public function requireFeature(string $tenantId, string $feature): void {
		if ($this->isFeatureEnabled(tenantId: $tenantId, feature: $feature) === false) {
			throw new PortalException(
				Http::STATUS_NOT_FOUND,
				'featureNotEnabled',
				'Deze functie is niet beschikbaar.'
			);
		}
	}                      

	   
                                        
   
                                          
   
                                    
                                                                                         
                                                                            
                                                                            
                               
    
	public function mfaEnforced(string $tenantId): bool {
		$config = $this->getConfig(tenantId: $tenantId);
		return $config !== null && ($config['mfaEnforced'] ?? false) === true;
	}                   

	   
                                         
   
                                          
   
                                 
                                                                                         
                                                                            
                                                                            
                               
    
	public function sessionTtlHours(string $tenantId): int {
		$config = $this->getConfig(tenantId: $tenantId);
		$ttl = (int)($config['sessionTtlHours'] ?? PortalSessionManager::DEFAULT_TTL_HOURS);
		if ($ttl > 0) {
			return $ttl;
		}

		return PortalSessionManager::DEFAULT_TTL_HOURS;
	}                       

	   
                                                                         
   
                                          
                                                         
   
                                                                        
                                                                                         
                                                                            
                                                                            
                               
    
	public function isWidgetOriginAllowed(string $tenantId, ?string $origin): bool {
		$config = $this->getConfig(tenantId: $tenantId);
		if ($config === null || ($config['widgetEmbedAllowed'] ?? false) !== true) {
			return false;
		}

		if ($origin === null || trim($origin) === '') {
			return false;
		}

		$allowed = ($config['widgetAllowedOrigins'] ?? []);
		return is_array($allowed) === true && in_array(
			rtrim(trim($origin), '/'),
			array_map(
				static fn ($value): string => rtrim((string)$value, '/'),
				$allowed
			),
			true
		) === true;
	}                             

	   
                                                
   
                                                                      
                                                            
                                                                          
                                                                    
                                                                         
                                                
   
                                                                      
                                                                    
                                                                         
                                                                       
                                                                         
                                                                          
                                                                          
                                                                  
   
                                          
   
                                                  
   
                                                                                                    
                                                                        
                                                                           
                            
   
                                                                                         
                                                                            
                                                                            
                               
    
	public function isSelfSignupAllowed(string $tenantId): bool {
		$config = $this->getConfig(tenantId: $tenantId);
		return $config !== null && ($config['selfSignupAllowed'] ?? false) === true;
	}                           

	   
                                                            
   
                                                               
   
                
   
                                                          
    
	private function assertContrast(array $config): void {
		$primary = ($config['brandPrimaryColor'] ?? null);
		$background = ($config['brandBackgroundColor'] ?? '#FFFFFF');
		if ($primary === null || $primary === '') {
			return;
		}

		try {
			$ratio = $this->contrast->calculate(color1: (string)$primary, color2: (string)$background);
		} catch (\InvalidArgumentException $e) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'invalidColor',
				'Ongeldige kleurwaarde. Gebruik een hex-kleur zoals #21468B.'
			);
		}

		if ($this->contrast->meetsAaStandard(ratio: $ratio) === false) {
			throw new PortalException(
				Http::STATUS_UNPROCESSABLE_ENTITY,
				'contrastRatioBelowMinimum',
				sprintf(
					'Kleurcontrast is onvoldoende (%s:1). Minimaal vereist: 4.5:1. Kies een donkerder of lichter kleur.',
					$ratio
				),
				['contrastRatio' => $ratio, 'requiredMinimum' => ContrastRatioCalculator::AA_MINIMUM]
			);
		}
	}                      

	   
                                              
   
                                          
   
                                              
    
	private function defaults(string $tenantId): array {
		return [
			'tenantId' => $tenantId,
			'displayName' => 'Klantportaal',
			'brandPrimaryColor' => '#21468B',
			'brandSecondaryColor' => '#FFFFFF',
			'brandBackgroundColor' => '#FFFFFF',
			'enabledFeatures' => ['invoices', 'contracts', 'orders', 'requests', 'documents', 'profile'],
			'b2bEnabled' => true,
			'b2cEnabled' => true,
		];
	}                
}           
