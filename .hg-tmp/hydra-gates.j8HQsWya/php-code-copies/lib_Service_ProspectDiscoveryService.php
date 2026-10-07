<?php

   
                                     
  
                                                                        
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                         
  
                            
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                       
  
                                                                                                   
   
class ProspectDiscoveryService {
	   
                                                            
   
                                                                
   
            
    
	private const DEFAULT_CACHE_TTL = 3600;

	   
                                                                             
   
               
    
	private const CACHE_PREFIX = 'pipelinq_prospects_v2_';

	   
                
   
                                                              
                                                      
                                                                       
                                                               
                                                          
                                              
                                                          
                                                   
    
	public function __construct(
		private IcpConfigService $icpConfig,
		private KvkApiClient $kvkClient,
		private OpenCorporatesApiClient $ocClient,
		private ProspectScoringService $scoring,
		private SettingsService $settings,
		private LoggerInterface $logger,
		private ContainerInterface $container,
		private IAppManager $appManager,
	) {
	}                   

	   
                                               
   
                                                 
                                                                                                
   
                                        
   
                                                                                            
                                                                                              
                                                                                              
                                                                                                                  
    
	public function discover(bool $refresh = false, int $limit = 10): array {
		if ($this->icpConfig->isConfigured() === false) {
			return [
				'error' => 'no_icp_configured',
				'message' => 'Configure your Ideal Customer Profile in admin settings first',
			];
		}

		$icpHash = $this->icpConfig->getIcpHash();
		$cacheKey = self::CACHE_PREFIX . $icpHash;

		               
		if ($refresh === false) {
			$cached = $this->getFromCache(key: $cacheKey);
			if ($cached !== null) {
				                                                                    
				$cached['prospects'] = $this->excludeExistingClients(prospects: $cached['prospects'] ?? []);
				$cached['total'] = count($cached['prospects']);
				return $this->limitResult(result: $cached, limit: $limit);
			}
		}

		$criteria = $this->icpConfig->getCriteria();
		$apiKey = $this->icpConfig->getKvkApiKey();

		$prospects = [];

		                  
		try {
			$kvkResults = $this->kvkClient->search(apiKey: $apiKey, criteria: $criteria);
			foreach ($kvkResults as $result) {
				$prospects[$result['kvkNumber']] = $result;
			}
		} catch (\Exception $e) {
			$this->logger->error(
				message: 'KVK API search failed',
				context: ['error' => $e->getMessage()]
			);
		}         

		                                          
		try {
			$ocEnabled = $this->icpConfig->getSettings()['openCorporatesEnabled'] ?? false;
			if ($ocEnabled === true) {
				$ocResults = $this->ocClient->search(criteria: $criteria);
				foreach ($ocResults as $result) {
					if (isset($prospects[$result['kvkNumber']]) === false) {
						$prospects[$result['kvkNumber']] = $result;
					}
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: 'OpenCorporates search failed',
				context: ['error' => $e->getMessage()]
			);
		}         

		                            
		$prospects = $this->excludeExistingClients(prospects: array_values(array: $prospects));

		                  
		$prospects = $this->scoring->scoreAll(prospects: $prospects, criteria: $criteria);

		                                 
		if (($criteria['excludeInactive'] ?? true) === true) {
			$prospects = array_values(
				array: array_filter(
					array: $prospects,
					callback: fn (array $prospect): bool => ($prospect['isActive'] ?? true) === true
				)
			);
		}

		                                                                           
		$result = [
			'prospects' => $prospects,
			'total' => count($prospects),
			'cachedAt' => date(format: 'c'),
			'icpHash' => $icpHash,
		];

		$this->setInCache(key: $cacheKey, data: $result);

		return $this->limitResult(result: $result, limit: $limit);
	}                

	   
                                                             
   
                                                                              
                                                                         
   
                                                                                
    
	private function limitResult(array $result, int $limit): array {
		$prospects = $result['prospects'] ?? [];
		if ($limit > 0) {
			$prospects = array_slice(array: $prospects, offset: 0, length: $limit);
		}

		$result['prospects'] = $prospects;
		$result['displayed'] = count($prospects);

		return $result;
	}                   

	   
                                                                             
   
                                                    
   
                                         
    
	private function excludeExistingClients(array $prospects): array {
		$clientNames = $this->getExistingClientNames();

		if (count($clientNames) === 0) {
			return $prospects;
		}

		return array_values(
			array: array_filter(
				array: $prospects,
				callback: function (array $prospect) use ($clientNames): bool {
					$tradeName = strtolower(string: trim(string: $prospect['tradeName'] ?? ''));
					if ($tradeName === '') {
						return true;
					}

					                                                                  
					                                                                      
					return in_array($tradeName, $clientNames, true) === false;
				}
			)
		);
	}                              

	   
                                                                 
   
                                   
    
	private function getExistingClientNames(): array {
		try {
			$register = $this->settings->getConfigValue(key: 'register');
			$schema = $this->settings->getConfigValue(key: 'client_schema');

			if ($register === '' || $schema === '') {
				return [];
			}

			if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
				return [];
			}

			  
                                                                 
      

			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

			$clients = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);

			$names = [];
			foreach ($clients as $client) {
				                                                                          
				if (is_object($client) === true && method_exists($client, 'jsonSerialize') === true) {
					$client = $client->jsonSerialize();
				}

				$client = (array)$client;
				$name   = (string)($client['name'] ?? $client['tradeName'] ?? '');
				if ($name !== '') {
					$names[] = strtolower(trim($name));
				}
			}

			return $names;
		} catch (Throwable $e) {
			$this->logger->warning(
				message: 'Failed to fetch existing clients for exclusion',
				context: ['error' => $e->getMessage()]
			);
			return [];
		}         
	}                              

	   
                       
   
                                     
   
                                               
                                                                          
    
	protected function getFromCache(string $key): ?array {
		if (function_exists(function: 'apcu_fetch') === false) {
			return null;
		}

		$success = false;
		$data = apcu_fetch(key: $key, success: $success);

		if ($success === true && is_array(value: $data) === true) {
			return $data;
		}

		return null;
	}                    

	   
                           
   
                                     
                                         
   
                
                                                                          
    
	protected function setInCache(string $key, array $data): void {
		if (function_exists(function: 'apcu_store') === true) {
			$ttl = $this->settings->getIntValue(
				'prospect_discovery.cache_ttl_seconds',
				self::DEFAULT_CACHE_TTL
			);
			apcu_store($key, $data, $ttl);
		}
	}                  
}           
