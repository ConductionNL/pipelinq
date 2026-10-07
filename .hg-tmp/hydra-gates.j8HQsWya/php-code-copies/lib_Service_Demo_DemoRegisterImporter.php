<?php

   
                                 
  
                                                                               
                                                                               
                                                                            
                                                                         
                                                                            
                                       
  
                                                                              
                                                                              
                                                                              
                                                   
  
                    
                                      
  
                                                              
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                                                 
  
                                                                                                                                               
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Demo;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

   
                                                      
  
                                                                                                                                               
   
class DemoRegisterImporter {
	   
                                                        
   
               
    
	private const DESCRIPTOR = '/lib/Settings/pipelinq_example_register.json';

	   
                                                                    
   
               
    
	public const CONFIG_APP_ID = Application::APP_ID . '.demo';

	   
                                                                         
   
                           
    
	private const LABEL_FIELDS = ['name', 'title', 'label', 'displayName', 'reference', 'subject', 'code'];

	   
                
   
                                                                           
                                                                                 
   
                                                                                           
                                                                                        
                                                                                                          
                                                                                       
                                                                                       
                                                                                           
                                                                                           
    
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly ConfigurationService $configurationService,
		private readonly SchemaMapper $schemaMapper,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
		private readonly DemoUserFields $userFields,
	) {
	}                   

	   
                                          
   
                                                                                             
   
                                                                                                                                                
    
	public function import(): array {
		$data = $this->loadDescriptor();
		if ($data === null) {
			return ['imported' => 0, 'skipped' => 0];
		}

		$data['components']['objects'] = $this->userFields->withRealUsers(objects: $data['components']['objects']);

		$result = $this->configurationService->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		$skipped = (array)($result['skipped'] ?? []);
		$summary = [
			'imported' => count((array)($result['objects'] ?? [])),
			'skipped'  => (int)($skipped['objects'] ?? 0) + (int)($skipped['seedObjects'] ?? 0),
		];

		$this->logger->info('Pipelinq example records imported', $summary);

		return $summary;
	}              

	   
                                                                                
   
                                                                           
                                                                            
                                                                          
                                                                            
                                                                      
   
                                              
   
                                                                                                                                                
    
	public function remove(): array {
		$summary = ['removed' => 0, 'retained' => 0];

		if (method_exists($this->configurationService, 'softDeleteAppImports') === true) {
			$jobs = $this->configurationService->softDeleteAppImports(self::CONFIG_APP_ID);
			$summary['removed'] += (int)($jobs['softDeleted'] ?? 0);
		}

		$data = $this->loadDescriptor();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($data === null || $registerId === '') {
			return $summary;
		}

		$bySchema = [];
		foreach ($data['components']['objects'] as $object) {
			$bySchema[(string)$object['@self']['schema']][(string)$object['@self']['slug']] = $object;
		}

		foreach ($bySchema as $schemaSlug => $wanted) {
			$counts = $this->removeFromSchema(registerId: $registerId, schemaSlug: $schemaSlug, wanted: $wanted);
			$summary['removed'] += $counts['removed'];
			$summary['retained'] += $counts['retained'];
		}

		$this->logger->info('Pipelinq example records removed', $summary);

		return $summary;
	}              

	   
                                                                              
   
                                                                                    
                                                                                            
                                                                                            
   
                                              
    
	private function removeFromSchema(string $registerId, string $schemaSlug, array $wanted): array {
		$counts = ['removed' => 0, 'retained' => 0];

		try {
			$schema = $this->schemaMapper->find($schemaSlug, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			                                                                
			return $counts;
		}

		$rows = $this->objectService->findAll(
			[
				'filters' => ['register' => $registerId, 'schema' => (string)$schema->getId()],
				'limit'   => 5000,
			]
		);

		foreach ((array)$rows as $row) {
			$data = $row;
			if ($row instanceof \JsonSerializable === true) {
				$data = $row->jsonSerialize();
			}

			$slug = (string)($data['@self']['slug'] ?? '');
			if (isset($wanted[$slug]) === false || $this->sameLabel(found: $data, wanted: $wanted[$slug]) === false) {
				continue;
			}

			try {
				$this->objectService->deleteObject((string)$data['id'], $registerId, (string)$schema->getId());
				$counts['removed']++;
			} catch (\Throwable $e) {
				if (str_contains($e->getMessage(), 'SCHEMA_ARCHIVAL_IMMUTABLE') === false) {
					throw $e;
				}

				                                                                  
				$counts['retained']++;
			}
		}             

		return $counts;
	}                        

	   
                                                               
   
                                                            
   
                                                          
                                                              
   
                                                                                   
    
	private function sameLabel(array $found, array $wanted): bool {
		foreach (self::LABEL_FIELDS as $field) {
			if (isset($wanted[$field]) === true && is_scalar($wanted[$field]) === true) {
				return (string)($found[$field] ?? '') === (string)$wanted[$field];
			}
		}

		return true;
	}                 

	   
                                                                  
   
                                     
    
	private function loadDescriptor(): ?array {
		$path = $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
		if (is_readable($path) === false) {
			$this->logger->error('Pipelinq example records descriptor missing', ['path' => $path]);
			return null;
		}

		$data = json_decode((string)file_get_contents($path), true);
		if (is_array($data) === false || is_array($data['components']['objects'] ?? null) === false) {
			$this->logger->error('Pipelinq example records descriptor invalid', ['path' => $path]);
			return null;
		}

		return $data;
	}                      
}           
