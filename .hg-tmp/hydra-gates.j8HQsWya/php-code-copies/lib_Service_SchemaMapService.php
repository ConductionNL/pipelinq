<?php

   
                             
  
                                                    
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                 
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;

   
                                                    
  
                                                                                     
                                                
   
class SchemaMapService {
	   
                                             
   
                              
    
	private const SCHEMA_MAPPING = [
		'client_schema' => 'client',
		'contact_schema' => 'contact',
		'lead_schema' => 'lead',
		                                                                          
		                                                                          
		                                                                       
		'ticket_schema' => 'ticket',
		'pipeline_schema' => 'pipeline',
		'skill_schema' => 'skill',
		'agentProfile_schema' => 'agentProfile',
		                                                                            
		                                                                         
		                                                                     
		                                                                          
		                                                                 
		'timeEntry_schema' => 'billingTimeEntry',
		'task_schema' => 'crmTask',
		'posTransaction_schema' => 'posTransaction',
		                                                                
		'posRole_schema' => 'posRole',
		'posStaff_schema' => 'posStaff',
		                                               
		'loyaltyProgramme_schema' => 'loyaltyProgramme',
		'pointsRule_schema' => 'pointsRule',
		'tierRule_schema' => 'tierRule',
		                                                                            
		                                                                          
		                                                        
		'klantLoyaltyAccount_schema' => 'customerLoyaltyAccount',
		'pointsLedgerEntry_schema' => 'pointsLedgerEntry',
		'redemptionOption_schema' => 'redemptionOption',
		'redemption_schema' => 'redemption',
		'giftCard_schema' => 'giftCard',
		'giftCardTransaction_schema' => 'giftCardTransaction',
		                                                                                    
		'expense_schema' => 'billableExpense',
		                                                                   
		'billingCategory_schema' => 'billingCategory',
	];

	   
                      
   
               
    
	private ?array $schemaMap = null;

	   
                
   
                                                                 
                                              
    
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                                          
   
                                                      
   
                                            
                                                                            
    
	public function resolveEntityType(?string $schemaId): ?string {
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		if ($this->schemaMap === null) {
			$this->buildSchemaMap();
		}

		return $this->schemaMap[$schemaId] ?? null;
	}                         

	   
                                                         
   
                
    
	private function buildSchemaMap(): void {
		$this->schemaMap = [];

		try {
			$settings = $this->settingsService->getSettings();

			foreach (self::SCHEMA_MAPPING as $configKey => $entityType) {
				$schemaId = $settings[$configKey] ?? '';
				if ($schemaId !== '') {
					$this->schemaMap[$schemaId] = $entityType;
				}
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to build Pipelinq schema map',
				[
					'exception' => $e->getMessage(),
				]
			);
		}         
	}                      
}           
