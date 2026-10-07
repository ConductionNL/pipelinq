<?php

   
                            
  
                                                                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                              
                                              
                                              
                                             
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Matomo\MatomoReportService;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

   
                                          
  
                                                                            
  
                                                                                 
                                                                         
                                                                           
                                                                           
   
class SettingsService {
	private const CONFIG_KEYS = [
		'register',
		'currency',
		'client_schema',
		'contact_schema',
		'lead_schema',
		                                                                           
		                                                                            
		                                                               
		'ticket_schema',
		'pipeline_schema',
		'product_schema',
		'productCategory_schema',
		'leadProduct_schema',
		'task_schema',
		'relationship_schema',
		'skill_schema',
		'agentProfile_schema',
		'timeEntry_schema',
		'posTransaction_schema',
		'posTransactionLine_schema',
		'receiptTemplate_schema',
		'receiptPrintLog_schema',
		'refundReason_schema',
		'posRefund_schema',
		'posRefundLine_schema',
		'cashShift_schema',
		'cashDrop_schema',
		'cashCount_schema',
		'cashDiff_schema',
		                                                                
		'posRole_schema',
		'posStaff_schema',
		                                                                              
		                                                                                
		                                                                               
		                                                                  
		'posZReport_schema',
		                                                                                     
		'posTenderType_schema',
		'posTender_schema',
		                                                                     
		'kassakoppelingAuditLog_schema',
		'exportDestination_schema',
		'exportJob_schema',
		'exportRun_schema',
		'exportSchemaSnapshot_schema',
		                                                                    
		                                                                 
		                                                                                
		                                                                          
		                                                                         
		'complaint_sla_service',
		'complaint_sla_product',
		'complaint_sla_communication',
		'complaint_sla_billing',
		'complaint_sla_other',
		                                                                      
		'portal_register',
		'portalAccount_schema',
		'portalSession_schema',
		'portalDelegation_schema',
		'portalAuditEvent_schema',
		'portalTenantConfig_schema',
		                                               
		'loyaltyProgramme_schema',
		'pointsRule_schema',
		'tierRule_schema',
		'klantLoyaltyAccount_schema',
		'pointsLedgerEntry_schema',
		'redemptionOption_schema',
		'redemption_schema',
		'giftCard_schema',
		'giftCardTransaction_schema',
		                                                                       
		'expense_schema',
		                                                                   
		'billingCategory_schema',
		                                                            
		'service_schema',
		'resource_schema',
		'booking_schema',
		'walkInTicket_schema',
		'availabilityCache_schema',
		                                                           
		'berichtenboxMessage_schema',
		'berichtenboxReply_schema',
		'berichtenboxTemplate_schema',
		'mailboxResolution_schema',
		'deliveryAuditLog_schema',
		                                                                 
		'blast_schema',
		                                                                         
		'sla_register',
		'sla_policy_schema',
		'sla_breach_event_schema',
		                                                                         
		                                                                                 
		'supplier_schema',
		                                                           
		'contract_schema',
		                                                     
		'renewal_default_lead_time_days',
		                                                  
		                                            
		'partyFieldSet_schema',
		'partyIndicator_schema',
		'partyIndicatorValue_schema',
		                                                                    
		                                                                 
		                                                                       
		'party_organisation_max_depth',
		                                                               
		                                        
		'partyKind_schema',
		'partyKindAcceptance_schema',
		'partyLink_schema',
		                                                                          
		'survey_schema',
		'surveyInvitation_schema',
		'surveyResponse_schema',
		                                                                       
		                     
		'survey_dispatch_rules',
		                                                                   
		'survey_detractor_rating_threshold',
		                                                                       
		                                                                     
		                            
		'survey_default_assignee',
		                                                               
		'programme_schema',
		'programmeTask_schema',
		'programmeTeamMember_schema',
		'programmeWorkItem_schema',
		'programmeCycle_schema',
		'programmeEstimate_schema',
		'estimationScale_schema',
		                                                                    
		                          
		'programme_progress_mode',
		                                                                   
		                                                
		                                                                           
		'menu_structure',
		'menu_modules',
	];

	   
                                         
   
                              
    
	private const USER_SETTING_DEFAULTS = [
		'notify_assignments' => 'true',
		'notify_stage_status' => 'true',
		'notify_notes' => 'true',
	];

	   
                                                                      
                                                                   
                                                          
   
                                                                         
                                                                     
   
                              
    
	public const TUNABLE_DEFAULTS = [
		'task_expiry.poll_interval_seconds' => '900',
		'task_expiry.escalation_threshold_seconds' => '14400',
		'task_expiry.in_progress_grace_seconds' => '86400',
		'task_escalation.threshold_hours' => '4',
		'task.business_hour_start' => '8',
		'task.business_hour_end' => '17',
		'prospect_discovery.cache_ttl_seconds' => '3600',
		'kvk.api_base_url' => 'https://api.kvk.nl/api/v1',
		'opencorporates.api_base_url' => 'https://api.opencorporates.com/v0.4',
		'receipt_company_name' => 'Conduction B.V.',
		'receipt_company_address' => '',
		'receipt_company_phone' => '',
		'receipt_company_vat' => '',
		'receipt_company_kvk' => '',
		                                                      
		                                                                      
		                                                                  
		'receipt_company_street' => '',
		'receipt_company_postcode' => '',
		'receipt_company_city' => '',
		'receipt_company_country' => '',
		'receipt_company_email' => '',
		'receipt_company_website' => '',
		                                                                                   
		                                                                     
		'vat_rates' => '',
		'receipt_email_sender' => '',
		'receipt_printer_host' => '',
		'receipt_printer_port' => '9100',
		'receipt_default_template' => '',
		                                                                        
		                                                                      
		                                                                         
		                                                                             
		                                                                          
		                                                                         
		                                                                          
		'shillinq_journal_webhook_url' => '',
		                                                                        
		                                                                        
		                                                                                   
		                                                      
		'shillinq_time_intake_enabled' => 'false',
		'billing_handoff_manager_group' => '',
		                                                                             
		                                                                               
		                                                                                   
		'lead_stale_threshold_days' => '14',
		                                                                                             
		'export.retention_days' => '365',
		'export.default_compression' => 'none',
		'export.failure_notification_email' => '',
		'export.at_risk_warning_hours' => '24',
		                                                                                   
		                                                                                      
		                                                                           
		                                        
		'pos_eod.z_report_time' => '23:59',
		'pos_eod.alert_email' => '',
		'pos_eod.max_retry_attempts' => '5',
		                                                                         
		                                                                   
		'xwiki_default_space' => '',
		'xwiki_cache_ttl' => '300',
		                                                           
		                                                           
		'sla_sweep_interval_seconds' => '300',
		'sla_business_hours_start' => '09:00',
		'sla_business_hours_end' => '17:00',
		'sla_default_holiday_calendar' => 'nl-feestdagen-rijksoverheid',
		'sla_tenant_holiday_overrides' => '',
		'sla_bevrijdingsdag_yearly' => 'false',
		'sla_actor_fallback' => '',
		'sla_actor_assignee' => '',
		'sla_actor_team-lead' => '',
		'sla_actor_manager' => '',
		'sla_actor_director' => '',
		'sla_resolved_statuses' => 'resolved,completed,closed,afgehandeld',
		                                                     
		'avg_dpia_threshold' => '10',
		'avg_evidence_retention_days' => '30',
		'avg_download_validity_days' => '30',
		'avg_pki_cert_path' => '',
		'avg_evidence_sources' => '',
		'avg_dpia_auto_procest' => 'no',
		'avg_handler_group' => '',
		'avg_teamlead_group' => '',
		'avg_dpo_group' => '',
		                                                                     
		                                                                     
		                                                                    
		                                              
		'crm_group' => '',
		                                                  
		                                                                    
		                                                                
		                                                                  
		                                                        
		                                                                         
		                                                          
		'blast.first_party_tracking' => 'false',
		'blast.tracking_token_ttl_days' => '90',
		                                                                     
		                                                              
		                                                                   
		                                                            
		                                                                       
		                                                 
		'blast.traffic_portal' => '',
		                                                                       
		                                                                     
		                                                                        
		'blast.utm_auto' => 'true',
		                                                                      
		                                                                        
		                                                     
		'search.gsc.properties' => '',
		                                                                     
		                                                 
		'search.gsc.last_import_at' => '',
		                                                                 
		                                                               
		                                                                
		                                                                    
		                                                             
		                                             
		'campaign.utm_sources' => '',
		'campaign.utm_mediums' => '',
		                                                                     
		                                                                     
		        
		'marketing.landing_portal' => '',
		                                                                 
		                                                                     
		                                                                   
		                                                                    
		'search.crawl_source' => '',
		                                                               
		                                                                    
		                                     
		'search.striking_min_impressions' => '',
		                                                                     
		                                                               
		                                                               
		'matomo.base_url' => '',
		'matomo.site_id' => '',
		'matomo.source_id' => '',
		                                                                   
		                                                                      
		                                                                     
		                                                             
		                       
		'matomo.credential_ref' => '',
		                                                                   
		                                                                     
		                                                                      
		                                                                
		'competitor.egress_source' => '',
		'competitor.user_agent' => '',
		'competitor.relevance' => 'false',
		'competitor.relevance_context' => '',
	];

	   
                                                                        
                                                                    
                                                                       
                                                                       
                                                
   
                           
    
	private const SECRET_KEYS = [
		'search.gsc.service_account_key',
	];

	   
                
   
                                                
                                                   
                                                                              
                                                                                
                                                                       
                                              
    
	public function __construct(
		private IAppConfig $appConfig,
		private IConfig $config,
		private SettingsLoadService $settingsLoadService,
		private DefaultPipelineService $pipelineService,
		private DefaultSkillService $skillService,
		private LoggerInterface $logger,
	) {
	}                   

	   
                              
   
                                                  
   
                                               
    
	public function getSettings(): array {
		$config = [];
		foreach (self::CONFIG_KEYS as $key) {
			$config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		}

		foreach (self::TUNABLE_DEFAULTS as $key => $default) {
			$config[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		}

		foreach (self::SECRET_KEYS as $key) {
			$config[$key . '_set'] = 'false';
			if ($this->appConfig->getValueString(Application::APP_ID, $key, '') !== '') {
				$config[$key . '_set'] = 'true';
			}
		}

		return $config;
	}                   

	   
                                                 
   
                                                   
   
                                       
   
                                               
    
	public function updateSettings(array $data): array {
		$this->refuseAMatomoToken(data: $data);

		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
			}
		}

		foreach (array_keys(array: self::TUNABLE_DEFAULTS) as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
			}
		}

		foreach (self::SECRET_KEYS as $key) {
			if ((string)($data[$key . '_clear'] ?? '') === 'true') {
				$this->appConfig->deleteKey(Application::APP_ID, $key);
				continue;
			}

			$value = trim((string)($data[$key] ?? ''));
			if ($value !== '') {
				$this->appConfig->setValueString(Application::APP_ID, $key, $value, false, true);
			}
		}

		$this->logger->info('Pipelinq settings updated', ['keys' => array_keys($data)]);

		return $this->getSettings();
	}                      

	   
                                                                          
   
                                                                         
                                                                         
                                                                          
                                                                          
                                                                   
                                                                          
                                                  
   
                                                       
   
                
   
                                                                              
   
                                                                                                                                                                                          
    
	private function refuseAMatomoToken(array $data): void {
		$value = trim((string)($data[MatomoReportService::CREDENTIAL_KEY] ?? ''));
		if ($value === '' || MatomoReportService::looksLikeAToken(value: $value) === false) {
			return;
		}

		throw new UnexpectedValueException(
			'That looks like a Matomo token rather than a credential reference. '
			. 'Store the token in the credential broker and put the credential\'s id here, '
			. 'so Pipelinq never holds the secret.'
		);
	}                          

	   
                                                                          
                                     
   
                                                  
   
                                    
   
                                                                                      
                                                                                                                 
    
	public function loadSettings(bool $force = false): array {
		return $this->settingsLoadService->loadSettings(force: $force);
	}                    

	   
                                           
                                        
   
                
   
                                               
    
	public function createDefaultPipelines(): void {
		$this->pipelineService->createDefaultPipelines();
	}                              

	   
                                        
                                     
   
                
   
                                              
    
	public function createDefaultSkills(): void {
		$this->skillService->createDefaultSkills();
	}                           

	   
                                         
   
                                      
   
                                                         
                                                                            
    
	public function getUserSettings(string $userId): array {
		$settings = [];
		foreach (self::USER_SETTING_DEFAULTS as $key => $default) {
			$settings[$key] = $this->config->getUserValue(
				userId: $userId,
				appName: Application::APP_ID,
				key: $key,
				default: $default
			) === 'true';
		}

		return $settings;
	}                       

	   
                                            
   
                                      
                                                   
   
                                            
                                                                            
    
	public function updateUserSettings(string $userId, array $data): array {
		foreach (array_keys(array: self::USER_SETTING_DEFAULTS) as $key) {
			if (array_key_exists(key: $key, array: $data) === true) {
				$value = 'false';
				if ($data[$key] === true) {
					$value = 'true';
				}

				$this->config->setUserValue(
					userId: $userId,
					appName: Application::APP_ID,
					key: $key,
					value: $value
				);
			}
		}

		return $this->getUserSettings(userId: $userId);
	}                          

	   
                              
   
                                      
                                             
   
                                    
   
                                                                                     
    
	public function getConfigValue(string $key, string $default = ''): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
	}                      

	   
                              
   
                                      
                                          
   
                
   
                                                                                     
    
	public function setConfigValue(string $key, string $value): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value);
	}                      

	   
                                             
   
                                                                          
                                                                     
                                                                
                                                  
   
                                      
                                                                    
   
                                                              
   
                                                     
    
	public function getIntValue(string $key, int $default): int {
		return $this->appConfig->getValueInt(Application::APP_ID, $key, $default);
	}                   

	   
                                                           
   
                                                                       
                                                                        
                                                                   
                                                               
   
                                      
                                                                       
   
                                                                 
   
                                                     
    
	public function getStringValue(string $key, string $default): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
	}                      
}           
