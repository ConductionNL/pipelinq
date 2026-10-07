<?php

   
                            
  
                                                                                            
                                                                                          
                                         
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                              
  
                                                                          
   
class BrpCacheService {
	   
                                             
    
	private const DEFAULT_TTL_HOURS = 24;

	   
                
   
                                                                      
                                          
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                                     
   
                                                           
   
                                    
   
                                                                              
                                                                              
   
                                                                                                
    
	public function get(string $rawBsn): ?array {
		try {
			[$register, $schema] = $this->config();
			$hash = BsnValidationService::hash($rawBsn);
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'bsnHash' => $hash,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);

			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$latest = null;
			foreach (($results ?? []) as $object) {
				$arr = self::toArray(object: $object);
				$retentionTo = (string)($arr['retentionTo'] ?? '');
				if ($retentionTo === '') {
					continue;
				}

				try {
					$retentionDt = new DateTimeImmutable($retentionTo, new DateTimeZone('UTC'));
				} catch (Throwable $e) {
					continue;
				}

				if ($retentionDt <= $now) {
					continue;
				}

				if ($latest === null) {
					$latest = $arr;
					continue;
				}

				$latestFetched = (string)($latest['fetchedOn'] ?? '');
				$currentFetched = (string)($arr['fetchedOn'] ?? '');
				if ($currentFetched > $latestFetched) {
					$latest = $arr;
				}
			}             

			return $latest;
		} catch (Throwable $e) {
			$this->logger->error(
				'BRP cache lookup failed',
				['error' => $e->getMessage()]
			);
			return null;
		}         
	}           

	   
                                                                        
   
                                                                                         
                                                                          
   
                                                                               
   
                                                                              
    
	public function set(array $person, ?int $ttlHours = null): array {
		[$register, $schema] = $this->config();

		$ttl = $ttlHours ?? $this->getConfiguredTtlHours();
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$retention = $now->modify('+' . max(1, $ttl) . ' hours');

		$person['fetchedOn'] = $person['fetchedOn'] ?? $now->format(DATE_ATOM);
		$person['retentionTo'] = $retention->format(DATE_ATOM);

		$saved = $this->getObjectService()->saveObject(
			object: $person,
			extend: [],
			register: $register,
			schema: $schema,
		);
		return self::toArray(object: $saved);
	}           

	   
                                                                   
   
                                                
   
                                  
   
                                                    
   
                                                                              
   
                                                                                                
    
	public function invalidate(string $rawBsn): int {
		try {
			[$register, $schema] = $this->config();
			$hash = BsnValidationService::hash($rawBsn);
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'bsnHash' => $hash,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);

			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$expired = $now->modify('-1 second')->format(DATE_ATOM);
			$count = 0;
			foreach (($results ?? []) as $object) {
				$arr = self::toArray(object: $object);
				$uuid = (string)($arr['@self']['id'] ?? $arr['id'] ?? '');
				if ($uuid === '') {
					continue;
				}

				$arr['retentionTo'] = $expired;
				$this->getObjectService()->saveObject(
					object: $arr,
					extend: [],
					register: $register,
					schema: $schema,
					uuid: $uuid,
				);
				$count++;
			}

			return $count;
		} catch (Throwable $e) {
			$this->logger->error(
				'BRP cache invalidate failed',
				['error' => $e->getMessage()]
			);
			return 0;
		}         
	}                  

	   
                                         
   
                             
                                           
    
	public function getConfiguredTtlHours(): int {
		$value = (int)$this->appConfig->getValueString(
			Application::APP_ID,
			'brp.cache_ttl_hours',
			(string)self::DEFAULT_TTL_HOURS,
		);
		if ($value >= 1) {
			return $value;
		}

		return self::DEFAULT_TTL_HOURS;
	}                             

	   
                                                         
   
                                                                  
   
                               
    
	private static function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serial = $object->jsonSerialize();
			if (is_array($serial) === true) {
				return $serial;
			}

			return [];
		}

		return [];
	}               

	   
                               
   
                                     
   
                                              
    
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('brpPersoon register/schema not configured.');
		}

		return [$register, $schema];
	}              

	   
                          
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
