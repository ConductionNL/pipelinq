<?php

   
                                
  
                                                                         
                                                                                
                                                                               
                                                                 
  
                                                                        
                                                                       
                                                                           
                                                                           
                                                                             
                                                                               
                                           
  
                                                                               
                                                                            
                                                                            
  
                    
                                        
  
                                             
                                  
                                                                                    
  
                                                                    
                                    
  
                         
  
                                                 
  
                                                                                                                                                                                         
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Matomo;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CampaignService;
use OCA\Pipelinq\Service\Egress\ConnectorEgress;
use OCA\Pipelinq\Service\Egress\EgressResult;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCP\IAppConfig;

   
                                                     
  
                                                                                                                                                                                         
   
class MatomoReportService {

	   
                                                                       
   
               
    
	public const SOURCE_KEY = 'matomo.source_id';

	   
                                                                         
   
               
    
	public const CREDENTIAL_KEY = 'matomo.credential_ref';

	   
                                              
   
               
    
	public const SITE_KEY = 'matomo.site_id';

	   
                                                                  
   
               
    
	public const ENDPOINT = '/index.php';

	   
                                                     
   
                                                                           
                                                                      
                                                                          
                                                                  
   
                              
    
	public const REPORTS = [
		'campaigns' => 'Referrers.getCampaigns',
		'referrerTypes' => 'Referrers.getReferrerType',
		'goals' => 'Goals.get',
	];

	   
                
   
                                                            
                                                                                             
                                                                            
                                                     
   
                                                                                                                                                                                          
    
	public function __construct(
		private ConnectorEgress $egress,
		private BrokerCredentialReader $credentials,
		private CampaignService $campaigns,
		private IAppConfig $appConfig,
	) {
	}                   

	   
                                                                 
   
                                                  
                                                         
   
                                                                                                      
   
                                                                                                                                                                               
    
	public function report(string $from, string $to): array {
		$refusal = $this->refusal();
		if ($refusal !== null) {
			return ($refusal + ['from' => $from, 'to' => $to, 'campaigns' => [], 'referrerTypes' => [], 'goals' => []]);
		}

		$out = ['connected' => true, 'reason' => '', 'from' => $from, 'to' => $to];
		foreach (self::REPORTS as $name => $method) {
			$out[$name] = $this->fetch(method: $method, from: $from, to: $to);
		}

		$out['campaigns'] = $this->matchCampaigns(rows: (array)$out['campaigns']);

		return $out;
	}              

	   
                                                     
   
                                                                        
   
                                                                                                                                                                                          
    
	public function refusal(): ?array {
		if ($this->egress->isConfigured(configKey: self::SOURCE_KEY) === false) {
			return [
				'connected' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No Matomo source is configured under ' . self::SOURCE_KEY . '.',
			];
		}

		$credentialRef = $this->credentialRef();
		if ($credentialRef === '') {
			return [
				'connected' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'No credential is referenced under ' . self::CREDENTIAL_KEY . '.',
			];
		}

		$status = $this->credentials->status(credentialRef: $credentialRef);
		if ($status === '') {
			return [
				'connected' => false,
				'failure' => EgressResult::NOT_CONFIGURED,
				'reason' => 'The referenced credential could not be read from the broker.',
			];
		}

		if ($status !== 'active') {
			return [
				'connected' => false,
				'failure' => 'relink_needed',
				'reason' => 'The Matomo credential is ' . $status . '. Reconnect it in the credential broker.',
			];
		}

		return null;
	}               

	   
                                        
   
                                                
   
                                                                                                                                                                                          
    
	public function credentialRef(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::CREDENTIAL_KEY, ''));
	}                     

	   
                                                                          
                                                                           
                                                                      
   
                                                 
   
                                                                         
   
                                                                                                                                                                                          
    
	public static function looksLikeAToken(string $value): bool {
		return (preg_match('/^[0-9a-fA-F]{32}$/', trim($value)) === 1);
	}                       

	   
                        
   
                                                
                                     
                                 
   
                                                                
    
	private function fetch(string $method, string $from, string $to): array {
		$result = $this->egress->read(
			configKey: self::SOURCE_KEY,
			endpoint: self::ENDPOINT,
			config: [
				'query' => [
					'module' => 'API',
					'method' => $method,
					'idSite' => $this->siteId(),
					'period' => 'range',
					'date' => ($from . ',' . $to),
					'format' => 'JSON',
					'filter_limit' => '100',
				],
			],
		);

		$decoded = $result->json();
		if ($decoded === null) {
			return [];
		}

		return $decoded;
	}             

	   
                                                                   
   
                                                                   
   
                                                                                  
   
                                                                                                                                                                               
    
	private function matchCampaigns(array $rows): array {
		$ours = [];
		foreach ($this->campaigns->all() as $campaign) {
			$value = mb_strtolower(trim((string)($campaign['utmCampaign'] ?? '')), 'UTF-8');
			if ($value !== '') {
				$ours[$value] = $campaign;
			}
		}

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$label = trim((string)($row['label'] ?? ''));
			$key = mb_strtolower($label, 'UTF-8');
			$match = ($ours[$key] ?? null);
			$out[] = [
				'campaign' => $label,
				'visits' => (int)($row['nb_visits'] ?? 0),
				'actions' => (int)($row['nb_actions'] ?? 0),
				'matched' => ($match !== null),
				'campaignId' => (string)($match['id'] ?? ($match['@self']['id'] ?? '')),
				'campaignName' => (string)($match['name'] ?? ''),
			];
		}

		return $out;
	}                      

	   
                                                                
   
                  
    
	private function siteId(): string {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, self::SITE_KEY, ''));
		if ($value === '') {
			return '1';
		}

		return $value;
	}              
}           
