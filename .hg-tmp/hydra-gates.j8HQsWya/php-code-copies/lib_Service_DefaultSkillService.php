<?php

   
                                
  
                                                                             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

   
                                                 
  
                                             
   
class DefaultSkillService {
	   
                              
   
                                                                       
                                                                             
                                                 
   
                                         
    
	private const DEFAULT_SKILLS = [
		[
			'title' => 'Algemene Dienstverlening',
			'description' => 'General public service',
			'categories' => ['algemeen'],
			'isActive' => true,
		],
		[
			'title' => 'Belastingen',
			'description' => 'Municipal taxes',
			'categories' => ['belastingen'],
			'isActive' => true,
		],
		[
			'title' => 'Klachten',
			'description' => 'Complaint handling',
			'categories' => ['klachten'],
			'isActive' => true,
		],
	];

	   
                
   
                                                
                                              
                                                                           
                                                                                         
    
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private RegisterResolverService $registerResolver,
		private readonly ObjectServiceInterface $objectService,
	) {
	}                   

	   
                                        
   
                
   
                                              
    
	public function createDefaultSkills(): void {
		$registerId = $this->registerResolver->resolve('skill');
		$skillSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'skill_schema', '');

		if ($registerId === '' || $skillSchemaId === '') {
			$this->logger->warning(
				'Pipelinq: Cannot create default skills -- register or skill schema not configured'
			);
			return;
		}

		try {
			$objectService = $this->getObjectService();

			$existing = $objectService->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $skillSchemaId,
					],
					'limit' => 1,
				]
			);

			if (empty($existing) === false) {
				$this->logger->info('Pipelinq: Default skills already exist, skipping creation');
				return;
			}

			foreach (self::DEFAULT_SKILLS as $skillData) {
				$objectService->saveObject(
					$skillData,
					[],
					$registerId,
					$skillSchemaId,
					null
				);
				$this->logger->info("Pipelinq: Created default skill '{$skillData['title']}'");
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'Pipelinq: Failed to create default skills',
				['exception' => $e->getMessage()]
			);
		}         
	}                           

	   
                                                         
   
                                      
    
	private function getObjectService(): object {
		return $this->objectService;
	}                        
}           
