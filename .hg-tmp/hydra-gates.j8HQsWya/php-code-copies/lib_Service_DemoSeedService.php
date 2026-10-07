<?php

   
                            
  
                                                                               
                                                                               
                                                                           
                                                                            
                                                                        
                                                                            
                                            
  
                                                                              
                                                                      
                                                                              
                                                                             
                                                           
  
                                                                             
                                                                        
                                                                               
                                                                                
                                                                           
                                                                          
                                                                          
                                                                             
           
  
                    
                                 
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                      
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Demo\DemoMarketingSeeder;
use OCA\Pipelinq\Service\Demo\DemoSeedValues;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

   
                                                               
  
                                                                                                      
                                                                                                                               
   
class DemoSeedService {
	   
                                                                
   
               
    
	public const DEMO_PREFIX = DemoSeedValues::DEMO_PREFIX;

	   
                                                        
   
               
    
	private const TICKET_SCHEMA_KEY = 'ticket_schema';

	   
                                                                   
   
                                                                       
                                                                           
                                                                           
                                                                         
                                                                         
    
	private const RELINK_FIELDS = ['pipeline'];

	   
                                                                                         
   
                                                                           
                                                                          
   
                                                                      
                            
   
                                                                     
                                                                      
                                                                              
                                                                            
                                                                          
                                                                              
                                                                            
                                                                             
                                                                            
   
                                                                            
                                                                             
                                                                  
   
                                                                   
    
	private const SECTIONS = [
		'clients' => ['client_schema', 'name', null],
		'contacts' => ['contact_schema', 'name', null],
		'pipelines' => ['pipeline_schema', 'title', null],
		'products' => ['product_schema', 'name', null],
		'leads' => ['lead_schema', 'title', null],
		'requests' => [self::TICKET_SCHEMA_KEY, 'title', TicketService::TYPE_REQUEST],
		'complaints' => [self::TICKET_SCHEMA_KEY, 'title', TicketService::TYPE_COMPLAINT],
		'contactmomenten' => [self::TICKET_SCHEMA_KEY, 'title', TicketService::TYPE_CONTACTMOMENT],
		'tasks' => ['task_schema', 'subject', null],
		'contracts' => ['contract_schema', 'title', null],
	];

	   
                                                                                   
   
                                                                             
                                                                            
                                                                  
                                                                              
                                                                    
   
                              
    
	private const CONTACT_FIRST_SECTIONS = [
		'clients' => 'client',
		'contacts' => 'contact',
	];

	   
                                                                      
   
                                                                          
                                                                   
   
                                             
    
	private const TICKET_FIELD_MAP = [
		TicketService::TYPE_REQUEST => ['requestedAt' => 'occurredAt'],
		TicketService::TYPE_CONTACTMOMENT => [
			'subject' => 'title',
			'summary' => 'description',
			'contactedAt' => 'occurredAt',
			'agent' => 'assignee',
		],
	];

	   
                                                       
   
                                                                             
                                                                              
                                                                             
                                         
   
                              
    
	private const OUTCOME_STATUS = [
		'handled' => 'resolved',
		'resolved' => 'resolved',
		'transferred' => 'closed',
		'referred' => 'closed',
		'callbackRequest' => 'in_progress',
		'followUpAction' => 'in_progress',
	];

	   
                
   
                                                                  
                                                                                      
                                                                                                             
                                                                             
                                                                                              
                                                                       
                                          
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly ContactVcardService $contactVcardService,
		private readonly TicketService $ticketService,
		private readonly DemoMarketingSeeder $marketingSeeder,
		private readonly DemoSeedValues $values,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                         
   
                                                                             
                                                                            
                             
   
               
    
	public const NONE_DATASET = 'none';

	   
                                         
   
               
    
	public const DEMO_DATASET = 'demo';

	   
                                                                        
   
                                                                         
                                                                         
                                                   
   
                                                                         
                                                                             
                                             
   
                                                                                                                              
   
                                                                                        
    
	public function listChoices(): array {
		return [
			[
				'id'          => self::NONE_DATASET,
				'label'       => 'None, I will set this up myself',
				'description' => 'Nothing is seeded. You start with an empty app and add your own data.',
				'objectCount' => 0,
				'icon'        => 'CloseCircleOutline',
			],
			[
				'id'    => self::DEMO_DATASET,
				'label' => 'Example data',
				                                                           
				                                                           
				                                              
				'description' => (
					'A worked CRM: clients and contacts, pipelines, products and leads, requests, '
					. 'complaints and contact moments, tasks and contracts, point of sale and '
					. 'bookings, marketing journeys that are not switched on, published social '
					. 'posts with their numbers, and search queries for the keyword pages. '
					. 'It shows the lists, '
					. 'detail pages and dashboards working. Safe to run more than once, and '
					. '`occ pipelinq:demo:seed --remove` takes it away again.'
				),
				'objectCount' => 0,
				'icon'        => 'DatabaseOutline',
			],
		];

	}                   

	   
                                                                         
   
                                                                                                                                           
   
                                                                                                       
    
	public function seed(): array {
		$context = $this->resolveContext();
		if ($context === null) {
			return [
				'success' => false,
				'message' => 'Pipelinq register or schemas are not provisioned — run the provisioning step first.',
				'created' => [],
				'skipped' => [],
			];
		}

		[$objectService, $registerId, $schemaIds, $definitions] = $context;

		$created = [];
		$skipped = [];
		$relinked = [];
		                                                                      
		$uuids = [];

		foreach (self::SECTIONS as $section => [$schemaKey, $lookupField, $ticketType]) {
			$created[$section] = 0;
			$skipped[$section] = 0;
			$relinked[$section] = 0;

			                                                                   
			                                                      
			$rows = [];
			$index = $this->buildLookupIndex(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $schemaIds[$schemaKey],
				lookupField: $lookupField,
				ticketType: $ticketType,
				rows: $rows,
			);

			foreach (($definitions[$section] ?? []) as $definition) {
				$data = $this->values->resolvePlaceholders(data: $definition['data']);
				$data = $this->linkReferences(
					data: $data,
					definition: $definition,
					uuids: $uuids,
					ticketType: $ticketType,
				);

				$existingUuid = $index[(string)$data[$lookupField]][0] ?? null;

				if ($existingUuid !== null) {
					$uuids[$section . ':' . $definition['key']] = $existingUuid;
					$skipped[$section]++;
					$didRelink = $this->relinkExisting(
						objectService: $objectService,
						target: ['register' => $registerId, 'schema' => $schemaIds[$schemaKey], 'ticketType' => $ticketType],
						existing: ($rows[$existingUuid] ?? []),
						data: $data,
						uuid: $existingUuid,
					);
					if ($didRelink === true) {
						$relinked[$section]++;
					}

					continue;
				}

				$identityType = (self::CONTACT_FIRST_SECTIONS[$section] ?? null);
				if ($identityType !== null) {
					$data = $this->withProvisionedIdentity(data: $data, objectType: $identityType);
					if ($data === null) {
						return [
							'success' => false,
							'message' => 'Nextcloud Contacts is unavailable — cannot provision demo ' . $identityType . ' identities.',
							'created' => $created,
							'skipped' => $skipped,
						];
					}
				}

				$saved = $this->saveSeedObject(
					objectService: $objectService,
					registerId: $registerId,
					schemaId: $schemaIds[$schemaKey],
					data: $data,
					ticketType: $ticketType,
				);

				$uuids[$section . ':' . $definition['key']] = (string)$saved->getUuid();
				$created[$section]++;
			}             
		}             

		$marketing = $this->marketingSeeder->seed(definitions: $definitions, uuids: $uuids);

		return [
			'success' => true,
			'created' => array_merge($created, $marketing['created']),
			'skipped' => array_merge($skipped, $marketing['skipped']),
			'relinked' => $relinked,
		];
	}            

	   
                                                                        
   
                                                                           
                                                                          
                               
   
                                                                 
                                                                                                           
                                                                        
                                                                
                                                 
   
                                                     
   
                                                                                                                                                      
    
	private function relinkExisting(object $objectService, array $target, array $existing, array $data, string $uuid): bool {
		$patch = [];
		foreach (self::RELINK_FIELDS as $field) {
			$wanted = (string)($data[$field] ?? '');
			if ($wanted !== '' && (string)($existing[$field] ?? '') !== $wanted) {
				$patch[$field] = $wanted;
			}
		}

		if ($patch === [] || $existing === []) {
			return false;
		}

		$payload = $existing;
		unset($payload['@self']);
		$payload = array_merge($payload, $patch);

		if ($target['ticketType'] !== null) {
			$this->ticketService->save(ticketType: $target['ticketType'], payload: $payload, uuid: $uuid);
			return true;
		}

		$objectService->saveObject($payload, [], $target['register'], $target['schema'], $uuid);
		return true;
	}                      

	   
                                                                                         
   
                                                                              
                                                                        
                                       
   
                                                                                                             
   
                                                                                                       
    
	public function remove(): array {
		$context = $this->resolveContext();
		if ($context === null) {
			return [
				'success' => false,
				'message' => 'Pipelinq register or schemas are not provisioned — nothing to remove.',
				'removed' => [],
				'retained' => [],
			];
		}

		[$objectService, $registerId, $schemaIds, $definitions] = $context;

		$removed = $this->marketingSeeder->remove(definitions: $definitions);
		$retained = [];
		$sections = array_reverse(self::SECTIONS, true);

		foreach ($sections as $section => [$schemaKey, $lookupField, $ticketType]) {
			$removed[$section] = 0;
			$retained[$section] = 0;

			                                                                  
			                                                         
			$index = $this->buildLookupIndex(
				objectService: $objectService,
				registerId: $registerId,
				schemaId: $schemaIds[$schemaKey],
				lookupField: $lookupField,
				ticketType: $ticketType,
			);

			foreach (($definitions[$section] ?? []) as $definition) {
				$lookupValue = (string)($definition['data'][$lookupField] ?? '');
				                                                            
				if (str_starts_with($lookupValue, self::DEMO_PREFIX) === false) {
					continue;
				}

				foreach (($index[$lookupValue] ?? []) as $uuid) {
					try {
						$objectService->deleteObject($uuid, $registerId, $schemaIds[$schemaKey]);
						$removed[$section]++;
					} catch (\Throwable $e) {
						if (str_contains($e->getMessage(), 'SCHEMA_ARCHIVAL_IMMUTABLE') === true) {
							                                                      
							$retained[$section]++;
							break;
						}

						throw $e;
					}
				}
			}             
		}             

		return ['success' => true, 'removed' => $removed, 'retained' => $retained];
	}              

	   
                                                               
   
                                                                         
                                                                            
                                                                      
   
                                                                 
                                                         
                                                        
                                                         
                                                                                       
   
                                    
   
                                                                                                       
    
	private function saveSeedObject(
		object $objectService,
		string $registerId,
		string $schemaId,
		array $data,
		?string $ticketType,
	): object {
		if ($ticketType !== null) {
			return $this->ticketService->save(
				ticketType: $ticketType,
				payload: $data,
			);
		}

		return $objectService->saveObject(
			$data,
			[],
			$registerId,
			$schemaId,
			null
		);
	}                      

	   
                                                                            
   
                                                                                               
                                                                           
                               
    
	private function resolveContext(): ?array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			return null;
		}

		$schemaIds = [];
		foreach (self::SECTIONS as [$schemaKey]) {
			if (isset($schemaIds[$schemaKey]) === true) {
				continue;
			}

			$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
			if ($schemaKey === self::TICKET_SCHEMA_KEY) {
				                                                              
				$schemaId = $this->ticketService->getSchemaId();
			}

			if ($schemaId === '') {
				return null;
			}

			$schemaIds[$schemaKey] = $schemaId;
		}

		$definitions = $this->loadDefinitions();
		if ($definitions === null) {
			return null;
		}

		$definitions = $this->mapDefinitionsToTickets(definitions: $definitions);

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->error('Pipelinq demo seed: OpenRegister ObjectService unavailable', ['exception' => $e->getMessage()]);
			return null;
		}

		return [$objectService, $registerId, $schemaIds, $definitions];
	}                      

	   
                                                     
   
                                                                                     
                                                                                           
    
	private function loadDefinitions(): ?array {
		$path = dirname(__DIR__) . '/Settings/demo_seed_data.json';
		if (is_readable($path) === false) {
			$this->logger->error('Pipelinq demo seed: demo_seed_data.json missing', ['path' => $path]);
			return null;
		}

		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false || $decoded === []) {
			$this->logger->error('Pipelinq demo seed: demo_seed_data.json invalid JSON', ['path' => $path]);
			return null;
		}

		return $decoded;
	}                       

	   
                                                                
   
                                                                        
                                                                    
                                                                          
                                                                          
                                                                       
   
                                                                
                                          
                                      
                                                                        
                                                                                                
                                                                                            
   
                                                                           
    
	private function buildLookupIndex(
		object $objectService,
		string $registerId,
		string $schemaId,
		string $lookupField,
		?string $ticketType = null,
		?array &$rows = null,
	): array {
		$existing = $objectService->findAll(
			[
				'filters' => [
					'register' => $registerId,
					'schema' => $schemaId,
				],
				'limit' => 2000,
			]
		);

		if (is_array($existing) === false) {
			return [];
		}

		$index = [];
		foreach ($existing as $row) {
			$data = $this->rowToArray(row: $row);
			if ($data === null) {
				continue;
			}

			if ($ticketType !== null && ($data['ticketType'] ?? null) !== $ticketType) {
				continue;
			}

			$value = (string)($data[$lookupField] ?? '');
			$uuid = (string)($data['id'] ?? ($data['uuid'] ?? ''));
			if ($value === '' || $uuid === '') {
				continue;
			}

			$index[$value][] = $uuid;
			$rows[$uuid] = $data;
		}

		return $index;
	}                        

	   
                                                                           
   
                                                                             
                                                                             
                                                                             
                                                              
   
                                                                                             
   
                                                                                      
    
	private function mapDefinitionsToTickets(array $definitions): array {
		foreach (self::SECTIONS as $section => [, , $ticketType]) {
			if ($ticketType === null || isset($definitions[$section]) === false) {
				continue;
			}

			foreach ($definitions[$section] as $i => $definition) {
				$definitions[$section][$i]['data'] = $this->toTicketData(
					data: ($definition['data'] ?? []),
					ticketType: $ticketType,
				);
			}
		}

		return $definitions;
	}                               

	   
                                                                 
   
                                                                               
                                                                     
   
                                                       
    
	private function toTicketData(array $data, string $ticketType): array {
		foreach ((self::TICKET_FIELD_MAP[$ticketType] ?? []) as $legacy => $field) {
			if (array_key_exists($legacy, $data) === false) {
				continue;
			}

			$data[$field] = $data[$legacy];
			unset($data[$legacy]);
		}

		                                                                     
		if ($ticketType === TicketService::TYPE_CONTACTMOMENT && isset($data['status']) === false) {
			$outcome = (string)($data['outcome'] ?? '');
			$data['status'] = (self::OUTCOME_STATUS[$outcome] ?? 'new');
		}

		$data['ticketType'] = $ticketType;

		return $data;
	}                    

	   
                                                                         
   
                              
   
                                                                                 
    
	private function rowToArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if ($row instanceof \JsonSerializable === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}                  

	   
                                                                    
   
                                                                             
                                                                           
                                                                         
                                                                             
                                                                        
                                                                              
                                
   
                                                                       
                                                                              
                            
   
                                                                    
                                                    
   
                                                                            
                                                                     
                                                                        
                                               
   
                                                                                                       
    
	private function withProvisionedIdentity(array $data, string $objectType): ?array {
		$provision = $this->contactVcardService->provisionContactFromForm(
			form: $data,
			objectType: $objectType
		);

		if ($provision === null) {
			return null;
		}

		$data['contactsUid'] = $provision['contactsUid'];

		return $data;
	}                               

	   
                                                                          
                         
   
                                                                     
                                       
   
                                                      
                                                                                                                    
                                                                                      
                                                                                   
   
                                                               
    
	private function linkReferences(array $data, array $definition, array $uuids, ?string $ticketType = null): array {
		                                                                     
		                                                                      
		                                                                    
		                                                                       
		                                                                     
		                       
		$data = $this->values->linkReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'clientKey',
			section: 'clients',
			field: ($definition['clientField'] ?? 'client'),
		);

		$data = $this->values->linkReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'contactKey',
			section: 'contacts',
			field: 'contact',
		);

		                                                                    
		                                                                      
		                                                                      
		                                                                       
		$data = $this->values->linkReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'pipelineKey',
			section: 'pipelines',
			field: 'pipeline',
		);

		                                                                    
		                                      
		$requestField = 'request';
		if ($ticketType !== null) {
			$requestField = 'parentTicket';
		}

		$data = $this->values->linkReference(
			data: $data,
			definition: $definition,
			uuids: $uuids,
			keyName: 'requestKey',
			section: 'requests',
			field: $requestField,
		);

		return $data;
	}                      
}           
