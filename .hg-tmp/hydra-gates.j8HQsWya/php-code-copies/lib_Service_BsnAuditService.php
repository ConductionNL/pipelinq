<?php

   
                            
  
                                                                                             
                                                                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Util\EntityAccessorTrait;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                       
  
                                                                                
                                                                                     
                                                                                   
                                                                                 
                               
  
                                                                          
   
class BsnAuditService {
	use EntityAccessorTrait;

	   
                                                                     
    
	private const RETENTION_YEARS = 5;

	   
                
   
                                                                           
                                                                  
                                                                           
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private IRequest $request,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                                                        
   
                                                                                    
                                                                                     
                          
   
                                        
                                                                            
                                                                      
                                                                       
                                                           
                                                                              
                                                                                
                                                                           
                                                                      
                                                                          
                                                            
                                                                                
                                                                                
                                                                      
   
                                                                                        
   
                                                                              
   
                                                                                                                                          
                                                                                                                                           
                                                                                                                                           
                                                                                                              
    
	public function recordLookup(
		string $actor,
		string $rawBsn,
		string $verzoekreden,
		string $purposeBinding,
		string $outcome,
		string $action = 'brp-lookup-executed',
		?int $responseCode = null,
		?string $haalcentraalCorrelationId = null,
		?string $linkedRequest = null,
		?string $actorRole = null,
		bool $vogScreening = false,
		?string $bsnHash = null,
	): string {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$retainUntil = $now->modify('+' . self::RETENTION_YEARS . ' years');

		$subjectHash = $this->resolveSubjectHash(rawBsn: $rawBsn, bsnHash: $bsnHash);

		$record = [
			'action' => $action,
			                                                                    
			                                                                       
			                                                                      
			                                                               
			                                                                   
			                                                      
			'bsnHash' => $subjectHash,
			'actor' => $actor,
			'actorRole' => $actorRole,
			'moment' => $now->format(DATE_ATOM),
			'verzoekreden' => $verzoekreden,
			'purposeBinding' => $purposeBinding,
			'outcome' => $outcome,
			'responseCode' => $responseCode,
			'ipAddress' => self::anonymiseIp(ipAddress: $this->request->getRemoteAddress()),
			'userAgent' => 'Pipelinq/' . (Application::APP_ID) . ' (Nextcloud)',
			'haalcentraalCorrelationId' => $haalcentraalCorrelationId,
			'linkedRequest' => $linkedRequest,
			'vogScreening' => $vogScreening,
			'retainUntil' => $retainUntil->format(DATE_ATOM),
		];

		                                              
		$record = array_filter($record, static fn ($v) => $v !== null);

		                                                               
		$maskedBsn = BsnValidationService::mask($rawBsn);

		try {
			[$register, $schema] = $this->config();
			$saved = $this->getObjectService()->saveObject(
				object: $record,
				extend: [],
				register: $register,
				schema: $schema,
			);

			$uuid = '';
			if (is_array($saved) === true) {
				$uuid = (string)($saved['@self']['id'] ?? $saved['id'] ?? '');
			} elseif (is_object($saved) === true) {
				                                                                    
				                                                                     
				                                                         
				                                                    
				$uuid = $this->readEntityValue(entity: $saved, getter: 'getUuid');
			}

			$this->logger->info(
				'BSN audit record written',
				[
					'action' => $action,
					'actor' => $actor,
					'bsn' => $maskedBsn,
					'outcome' => $outcome,
				]
			);
			return $uuid;
		} catch (Throwable $e) {
			                                                                          
			                                                                           
			                                          
			$this->logger->error(
				'BSN audit record write failed',
				[
					'action' => $action,
					'actor' => $actor,
					'bsn' => $maskedBsn,
					'error' => $e->getMessage(),
				]
			);
			return '';
		}         
	}                    

	   
                                                                 
   
                                                                     
                                                                        
                                                                         
                                                                     
                                                                          
                                                 
   
                                                                       
                 
   
                                                      
                                                                                  
   
                                             
   
                                                                                                          
                                                                                      
    
	private function resolveSubjectHash(string $rawBsn, ?string $bsnHash): string {
		if ($bsnHash !== null && $bsnHash !== '') {
			return $bsnHash;
		}

		return BsnValidationService::hash($rawBsn);
	}                          

	   
                                                                   
   
                                                                                      
                                                                                     
                                                                              
   
                                                                    
   
                                                                 
   
                                                
   
                                                                              
   
                                                                                                
    
	public function pseudonymise(string $rawBsn): int {
		try {
			[$register, $schema] = $this->config();
			$oldHash = BsnValidationService::hash($rawBsn);
			                                                                     
			$secret = $this->appConfig->getValueString(
				Application::APP_ID,
				'brp.pseudonym_secret',
				''
			);
			if ($secret === '') {
				$this->logger->warning(
					'No pseudonym secret configured; RTBF pseudonymise skipped',
					['actor' => 'system']
				);
				return 0;
			}

			$newHash = hash_hmac('sha256', $rawBsn, $secret);

			$records = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'bsnHash' => $oldHash,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);

			$count = 0;
			foreach (($records ?? []) as $record) {
				$arr = [];
				if (is_array($record) === true) {
					$arr = $record;
				} elseif (method_exists($record, 'jsonSerialize') === true) {
					$arr = (array)$record->jsonSerialize();
				}

				$uuid = (string)($arr['@self']['id'] ?? $arr['id'] ?? '');
				if ($uuid === '') {
					continue;
				}

				                                                                       
				$arr['bsnHash'] = $newHash;
				$arr['action'] = 'brp-rtbf-pseudonymised';
				$arr['outcome'] = 'pseudonymised';
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
				'BSN audit pseudonymise failed',
				['error' => $e->getMessage()]
			);
			return 0;
		}         
	}                    

	   
                                                                                      
   
                                                       
   
                                                            
   
                                                                              
    
	public static function anonymiseIp(string $ipAddress): string {
		if ($ipAddress === '') {
			return '';
		}

		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			$parts = explode('.', $ipAddress);
			$parts[3] = '0';
			return implode('.', $parts);
		}

		                                                       
		if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			$bin = inet_pton($ipAddress);
			if ($bin === false) {
				return '';
			}

			$masked = substr($bin, 0, 6) . str_repeat("\0", 10);
			$out = inet_ntop($masked);
			if ($out === false) {
				return '';
			}

			return $out;
		}

		return '';
	}                   

	   
                                                           
   
                                       
   
                                                         
    
	private function config(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'bsnAuditRecord_schema', '');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('bsnAuditRecord register/schema not configured.');
		}

		return [$register, $schema];
	}              

	   
                                                                                 
   
                                        
    
	private function getObjectService(): object {
		                                                                 
		                                                              
		return $this->objectService;
	}                        
}           
