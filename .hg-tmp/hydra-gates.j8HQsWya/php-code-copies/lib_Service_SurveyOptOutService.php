<?php

   
                                
  
                                                                    
  
                                                                                
                                                                       
                                                                            
                                                                             
                                                                         
  
                                                                               
                                                                             
                                                           
  
                                                                              
                                                                               
                                            
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                                    
                                                                    
  
                         
  
                            
  
                                                                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

   
                                             
  
                                                                                                                                                 
   
class SurveyOptOutService {
	   
                                                        
   
            
    
	private const LIMIT = 10;

	   
                
   
                                                                                     
                                                                               
                                              
    
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}                   

	   
                                                                  
   
                                              
   
                
   
                                                                                                                                                  
    
	public function record(string $contactRef): void {
		$contactRef = trim($contactRef);
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($contactRef === '' || $register === '' || $schema === '') {
			return;
		}

		foreach ($this->contactRows(register: $register, schema: $schema, contactRef: $contactRef) as $row) {
			$row['surveyOptOut'] = true;
			$this->write(register: $register, schema: $schema, row: $row);
		}
	}              

	   
                                                          
   
                                            
                                                
                                              
   
                                                                                  
    
	private function contactRows(string $register, string $schema, string $contactRef): array {
		try {
			$found = $this->objectService->findAll(
				[
					'filters' => ['register' => $register, 'schema' => $schema, 'contactsUid' => $contactRef],
					'limit' => self::LIMIT,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SurveyOptOutService: the opt-out could not be recorded',
				['exception' => $e->getMessage()]
			);

			return [];
		}

		$rows = [];
		foreach ($found as $row) {
			$data = $row;
			if ($row instanceof JsonSerializable) {
				$data = $row->jsonSerialize();
			}

			if (is_array($data) === true) {
				$rows[] = $data;
			}
		}

		return $rows;
	}                   

	   
                                                                  
   
                                            
                                                
                                                      
   
                
    
	private function write(string $register, string $schema, array $row): void {
		try {
			$this->objectService->saveObject(
				object: $row,
				register: $register,
				schema: $schema,
				uuid: (string)($row['id'] ?? $row['uuid'] ?? ''),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'SurveyOptOutService: the opt-out could not be saved',
				['exception' => $e->getMessage()]
			);
		}
	}             
}           
