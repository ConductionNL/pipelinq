<?php

   
                                
  
                                                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCA\OpenRegister\Service\ConfigurationService;

   
                                                            
  
                                                                            
   
class SettingsLoadService {
	   
                        
   
                 
    
	private const SCHEMA_SLUGS = [
		'client',
		'contact',
		'lead',
		                                                                        
		                                                                     
		                                                                    
		                                                                    
		'partyFieldSet',
		'partyIndicator',
		'partyIndicatorValue',
		                                                                  
		                                                                    
		                                                                    
		                                                
		'partyKind',
		'partyKindAcceptance',
		'partyLink',
		                                                                   
		                                                                    
		                                                                      
		                                                                        
		                                                                 
		                                                                  
		                         
		'enquiry',
		                                                                         
		                                                                    
		                                                           
		'ticket',
		'pipeline',
		'product',
		'productCategory',
		'billingCategory',
		'leadProduct',
		'crmTask',
		'relationship',
		'queue',
		'skill',
		'agentProfile',
		                                  
		'billingTimeEntry',
		'posTransaction',
		'posTransactionLine',
		                                                                       
		                                                                          
		                                                                    
		                                                                        
		                                                                  
		'posTenderType',
		'posTender',
		'receiptTemplate',
		'receiptPrintLog',
		'refundReason',
		'posRefund',
		'posRefundLine',
		                                         
		'cashShift',
		'cashDrop',
		'posCashCount',
		'cashDiff',
		                                                                         
		'posRole',
		'posStaff',
		'posZReport',
		                                                             
		'kassakoppelingAuditLog',
		                                                                           
		'crmPortalAccount',
		'crmPortalSession',
		'portalDelegation',
		'portalAuditEvent',
		'portalTenantConfig',
		                                           
		'exportDestination',
		'exportJob',
		'exportRun',
		'exportSchemaSnapshot',
		                                               
		'loyaltyProgramme',
		'pointsRule',
		'tierRule',
		'customerLoyaltyAccount',
		'pointsLedgerEntry',
		'redemptionOption',
		'redemption',
		'giftCard',
		'giftCardTransaction',
		                                                                          
		'ctiAdapterConfig',
		'ctiEventLog',
		'ctiAgentPresence',
		                                                                  
		'slaPolicy',
		'slaBreachEvent',
		                                                                    
		                                                                 
		                                                
		                                                                            
		                                                                          
		                                                           
		'masterEntity',
		'sourceRecord',
		'trustConfiguration',
		'masterMergeOperation',
		                                                                       
		                                                                       
		                                                                     
		                                                                        
		'supplier',
		                                                                              
		                                                             
		'salesContract',
		                                             
		'appointmentService',
		'appointmentResource',
		'appointmentBooking',
		'walkInTicket',
		'availabilityCache',
		                                                                   
		'billableExpense',
		                                                                 
		'blast',
		                                                 
		'berichtenboxMessage',
		'berichtenboxReply',
		'berichtenboxTemplate',
		'mailboxResolution',
		'deliveryAuditLog',
		                                                                          
		                                                   
		                                                                  
		                                                                   
		                                                                      
		                                                                  
		'satisfactionSurvey',
		'satisfactionSurveyInvitation',
		'surveyResponse',
		                                                                         
		                                                                  
		                                                                     
		                                      
		'deliveryProgramme',
		'programmeTask',
		'programmeTeamMember',
		'programmeWorkItem',
		'estimationScale',
		'programmeEstimate',
		'programmeCycle',
		                                                                         
		                                                                     
		                                                                   
		                                                                   
		                                                                    
		  
		                                                       
		'article',
		'attributionLink',
		'blastDelivery',
		'campaign',
		'campaignTemplate',
		'journey',
		'journeyRun',
		'segment',
		'subscription',
		'touchpoint',
		'weeklyReview',
		                                             
		'competitor',
		'competitorWatch',
		'keywordTarget',
		'searchQueryDaily',
		'socialConnection',
		'socialPublication',
		'watchEvent',
		                              
		'mailTransport',
		                                                                       
		                                                                      
		                                                                 
		'channelConversation',
		'channelMessage',
		'channelProvider',
		'messageSendBudget',
		'messageTemplate',
		'messagingConsentRecord',
		                                       
		'brpLookupVerzoek',
		'brpPersoon',
		'bsnAuditRecord',
		'optOutVlag',
		                         
		'forecastOverride',
		'forecastSnapshot',
		'salesQuota',
	];

	   
                                                               
   
               
    
	private const PORTAL_REGISTER_SLUG = 'pipelinq-portal';

	   
                                                  
   
               
    
	private const SLA_REGISTER_SLUG = 'sla';

	   
                
   
                                                
                                                   
                                                          
                                                               
                                                                                            
    
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private SettingsMapBuilder $mapBuilder,
		private ConfigFileLoaderService $fileLoader,
		private readonly ConfigurationService $configurationService,
	) {
	}                   

	   
                                                                          
   
                                                  
   
                                    
   
                                                                                      
   
                                                                                                 
    
	public function loadSettings(bool $force = false): array {
		$data = $this->fileLoader->loadConfigurationFile();
		$data = $this->fileLoader->ensureSourceType(data: $data);

		$configurationService = $this->getConfigurationService();
		$currentAppVersion = $this->appManager->getAppVersion(Application::APP_ID);

		$result = $configurationService->importFromApp(
			appId: Application::APP_ID,
			data: $data,
			version: $currentAppVersion,
			force: $force
		);

		$this->updateObjectTypeConfiguration(importResult: $result);

		return $result;
	}                    

	   
                                                            
   
                                                                           
   
                
    
	private function updateObjectTypeConfiguration(array $importResult): void {
		$schemaMap = $this->mapBuilder->buildSchemaSlugMap(
			schemas: ($importResult['schemas'] ?? [])
		);

		$this->applyRegisterConfig(importResult: $importResult);
		$this->applySchemaConfig(schemaMap: $schemaMap);

		$defaultViewId = $this->mapBuilder->findDefaultViewId(
			views: ($importResult['views'] ?? [])
		);

		if ($defaultViewId !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'default_view', (string)$defaultViewId);
		}
	}                                     

	   
                                                                  
   
                                                                           
   
                
    
	private function applyRegisterConfig(array $importResult): void {
		$registerId = $this->mapBuilder->findRegisterIdBySlug(
			registers: ($importResult['registers'] ?? [])
		);

		if ($registerId !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'register', (string)$registerId);
		}

		$portalRegisterId = $this->resolveRegisterIdBySlug(
			registers: ($importResult['registers'] ?? []),
			slug: self::PORTAL_REGISTER_SLUG
		);
		if ($portalRegisterId !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'portal_register', (string)$portalRegisterId);
		}

		$slaRegisterId = $this->resolveRegisterIdBySlug(
			registers: ($importResult['registers'] ?? []),
			slug: self::SLA_REGISTER_SLUG
		);
		if ($slaRegisterId !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'sla_register', (string)$slaRegisterId);
		}
	}                           

	   
                                                                            
   
                                                                             
                                                                           
                                                                               
                                                                                
                                                                             
                                                               
   
                                                                  
   
                                                                                
                                                           
                                                                                 
                                                                                 
                                                                              
                                                                             
                                                                       
   
                                                                            
                                                                          
                                                                      
                    
   
                              
    
	public const SCHEMA_CONFIG_KEYS = [
		'slaPolicy' => 'sla_policy_schema',
		'slaBreachEvent' => 'sla_breach_event_schema',
		'customerLoyaltyAccount' => 'klantLoyaltyAccount_schema',
		                                                                        
		                                                                       
		                                                                      
		                                                                   
		'posCashCount' => 'cashCount_schema',
		                                                                      
		                                                                      
		                                                                
		'salesContract' => 'contract_schema',
		                                                                        
		                                                                     
		                                                                    
		                                                                   
		                                                                   
		                                    
		'crmPortalAccount' => 'portalAccount_schema',
		'crmPortalSession' => 'portalSession_schema',
		                                                                        
		                                                                        
		                                                                         
		                                                            
		'appointmentResource' => 'resource_schema',
		                                                                 
		'appointmentBooking' => 'booking_schema',
		                                                             
		'appointmentService' => 'service_schema',
		                                                                 
		'crmTask' => 'task_schema',
		                                                        
		'billableExpense' => 'expense_schema',
		'masterMergeOperation' => 'mergeOperation_schema',
		                                                                      
		                                                                    
		                                                                    
		                                                                       
		                                                                    
		'deliveryProgramme' => 'programme_schema',
		                                                             
		                                                                     
		                                                              
		                                                          
		                                                                    
		                                                                      
		                                                                       
		'billingTimeEntry' => 'timeEntry_schema',
		                                                                   
		                                                           
		                                                                     
		                                                                  
		'channelConversation' => 'conversation_schema',
		'channelMessage' => 'message_schema',
		                                                                   
		                                                                        
		                                                                       
		                                                                      
		                                                                      
		'satisfactionSurvey' => 'survey_schema',
		'satisfactionSurveyInvitation' => 'surveyInvitation_schema',
	];

	   
                                                
   
                                                                              
                                         
   
                                                      
   
                
    
	private function applySchemaConfig(array $schemaMap): void {
		foreach (self::SCHEMA_SLUGS as $slug) {
			                                                                   
			if (isset($schemaMap[$slug]) === false) {
				continue;
			}

			$key = (self::SCHEMA_CONFIG_KEYS[$slug] ?? "{$slug}_schema");
			$this->appConfig->setValueString(Application::APP_ID, $key, (string)$schemaMap[$slug]);
		}
	}                         

	   
                                                
   
                                                                        
                                                                          
                                                                          
   
                                                                           
                                                   
   
                                                                  
    
	private function resolveRegisterIdBySlug(array $registers, string $slug): ?string {
		foreach ($registers as $register) {
			$registerArray = $register;
			if (is_object($register) === true && method_exists($register, 'jsonSerialize') === true) {
				$serialized = $register->jsonSerialize();
				if (is_array($serialized) === true) {
					$registerArray = $serialized;
				}
			}

			if (is_array($registerArray) === false) {
				continue;
			}

			if (($registerArray['slug'] ?? null) !== $slug) {
				continue;
			}

			$id = ($registerArray['id'] ?? $registerArray['uuid'] ?? null);
			if ($id !== null) {
				return (string)$id;
			}
		}             

		return null;
	}                               

	   
                                                                
   
                                             
    
	private function getConfigurationService(): object {
		return $this->configurationService;
	}                               
}           
