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

   
                                      
  
                                                                          
  
                                                                                
                                                                                   
                                                    
   
class OptOutService {
	   
                
   
                                            
                                          
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                     
   
                                  
   
                                                          
   
                                                                              
    
	public function hasOptOut(string $rawBsn): bool {
		return $this->getOptOut(rawBsn: $rawBsn) !== null;
	}                 

	   
                                                                         
   
                                  
   
                                                                                             
   
                                                                              
    
	public function getOptOut(string $rawBsn): ?array {
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

			$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			foreach (($results ?? []) as $object) {
				$arr = self::toArray(object: $object);
				if ($this->isActive(arr: $arr, today: $today) === true) {
					return $arr;
				}
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'OptOut lookup failed',
				['error' => $e->getMessage()]
			);
		}         

		return null;
	}                 

	   
                                                                              
   
                                                                    
   
                                                           
                                                                                               
   
                                                                       
   
                                                                              
    
	public function recordFromBrpResponse(string $rawBsn, string $indicationSecret): bool {
		if ($indicationSecret !== '1') {
			return false;
		}

		if ($this->hasOptOut(rawBsn: $rawBsn) === true) {
			return false;
		}

		try {
			[$register, $schema] = $this->config();
			$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$object = [
				'bsnHash' => BsnValidationService::hash($rawBsn),
				'type' => 'confidentiality-municipality',
				'source' => 'BRP',
				'effectiveDate' => $today->format('Y-m-d'),
				'limited' => ['commerciele-derden', 'kerkgenootschappen', 'derdeportalen'],
			];
			$this->getObjectService()->saveObject(
				object: $object,
				extend: [],
				register: $register,
				schema: $schema,
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'OptOut record from BRP response failed',
				['error' => $e->getMessage()]
			);
			return false;
		}         
	}                             

	   
                                                                                  
   
                                  
                                                        
                                                            
   
                                 
   
                                                                              
    
	public function recordLocalOptOut(string $rawBsn, string $actor, ?string $note = null): bool {
		try {
			[$register, $schema] = $this->config();
			$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$object = [
				'bsnHash' => BsnValidationService::hash($rawBsn),
				'type' => 'local-contact-opt-out',
				'source' => 'local',
				'effectiveDate' => $today->format('Y-m-d'),
				'limited' => ['commerciele-derden'],
				'localEnteredBy' => $actor,
				'note' => $note,
			];
			$object = array_filter($object, static fn ($v) => $v !== null);
			$this->getObjectService()->saveObject(
				object: $object,
				extend: [],
				register: $register,
				schema: $schema,
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->error(
				'OptOut local record failed',
				['error' => $e->getMessage()]
			);
			return false;
		}         
	}                         

	   
                                                                                               
   
                                                            
                                                     
   
                
    
	private function isActive(array $arr, DateTimeImmutable $today): bool {
		$ingang = (string)($arr['effectiveDate'] ?? '');
		$endDate = $arr['endDate'] ?? null;
		if ($ingang === '') {
			return false;
		}

		try {
			$ingangDt = new DateTimeImmutable($ingang, new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return false;
		}

		if ($ingangDt > $today) {
			return false;
		}

		if ($endDate !== null && $endDate !== '') {
			try {
				$endDt = new DateTimeImmutable((string)$endDate, new DateTimeZone('UTC'));
			} catch (Throwable $e) {
				return true;
			}

			if ($today > $endDt) {
				return false;
			}
		}

		return true;
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
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'optOutVlag_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('optOutVlag register/schema not configured.');
		}

		return [$register, $schema];
	}              

	   
                                 
   
                  
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
