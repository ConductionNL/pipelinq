<?php

   
                                  
  
                                                                         
                                                                        
                          
  
                                                                      
                                                                        
                                                                        
                                                                      
                                      
  
                                                                    
                                                                     
             
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                    
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;

   
                                                                
  
                                                                       
                                                                  
                                                                 
  
                                                                                                                    
   
class CampaignReportService {

	   
                          
   
               
    
	public const BLAST_SCHEMA_SLUG = 'blast';

	   
                
   
                                                         
                                                                                                
                                                              
                                                             
   
                                                                                                                     
    
	public function __construct(
		private CampaignService $campaigns,
		private CampaignAttributionService $attribution,
		private ListObjectStore $store,
		private ITimeFactory $time,
	) {
	}                   

	   
                                
   
                                           
                                                                                             
                                                                                             
   
                                                                                           
   
                                                                                                                     
    
	public function forCampaign(string $campaignId, ?string $from = null, ?string $to = null): ?array {
		$campaign = $this->campaigns->find(id: $campaignId);
		if ($campaign === null) {
			return null;
		}

		$window = $this->window(campaign: $campaign, from: $from, to: $to);
		$blasts = $this->store->findAll(schemaSlug: self::BLAST_SCHEMA_SLUG, filters: ['campaignId' => $campaignId]);
		$attribution = $this->attribution->forCampaign(campaignId: $campaignId, from: $window['from'], to: $window['to']);

		return [
			'campaign' => [
				'id' => $campaignId,
				'name' => (string)($campaign['name'] ?? ''),
				'goal' => (string)($campaign['goal'] ?? ''),
				'status' => (string)($campaign['status'] ?? ''),
				'utmCampaign' => (string)($campaign['utmCampaign'] ?? ''),
				'utmSource' => (string)($campaign['utmSource'] ?? ''),
				'utmMedium' => (string)($campaign['utmMedium'] ?? ''),
				'landingPage' => (array)($campaign['landingPage'] ?? []),
				'defaultModel' => (string)($campaign['attribution']['model'] ?? 'last'),
			],
			'window' => $window,
			'channels' => $this->channels(blasts: $blasts, touchpoints: $attribution['touchpoints']),
			'engagement' => $this->engagement(touchpoints: $attribution['touchpoints']),
			'leads' => $attribution['leads'],
			'totals' => $attribution['totals'],
			'models' => $attribution['models'],
			'cost' => $this->cost(campaign: $campaign),
		];
	}                   

	   
                                 
   
                                                                     
                                                                      
                                                                 
                                                           
   
                                                                          
                                                                                    
   
                                                                 
   
                                                                                                                     
    
	public function channels(array $blasts, array $touchpoints): array {
		$rows = [];
		foreach ($blasts as $blast) {
			$channel = (string)($blast['channel'] ?? 'email');
			$totals = (array)($blast['totals'] ?? []);
			$row = ($rows[$channel] ?? $this->emptyChannel(channel: $channel));
			$row['reach'] = ((int)($row['reach'] ?? 0) + (int)($totals['delivered'] ?? 0));
			$row['sent'] = ($row['sent'] + (int)($totals['sent'] ?? 0));
			$row['opened'] = ($row['opened'] + (int)($totals['opened'] ?? 0));
			$row['reported'] = true;
			$rows[$channel] = $row;
		}

		foreach ($touchpoints as $touchpoint) {
			$channel = (string)($touchpoint['channel'] ?? 'direct');
			$row = ($rows[$channel] ?? $this->emptyChannel(channel: $channel));
			$kind = (string)($touchpoint['kind'] ?? '');
			if (isset($row[$kind]) === true) {
				$row[$kind]++;
			}

			$rows[$channel] = $row;
		}

		foreach ($rows as $channel => $row) {
			if ($row['reported'] === false) {
				$rows[$channel]['reach'] = null;
			}

			unset($rows[$channel]['reported']);
		}

		ksort($rows);

		return array_values($rows);
	}                

	   
                                                           
   
                                                                                    
   
                                                  
   
                                                                                                                     
    
	public function engagement(array $touchpoints): array {
		$counts = [];
		foreach (TouchpointService::KINDS as $kind) {
			$counts[$kind] = 0;
		}

		foreach ($touchpoints as $touchpoint) {
			$kind = (string)($touchpoint['kind'] ?? '');
			if (isset($counts[$kind]) === true) {
				$counts[$kind]++;
			}
		}

		return $counts;
	}                  

	   
                                                            
   
                                                       
   
                                                                                                                            
                                                         
   
                                                                                                                     
    
	public function cost(array $campaign): array {
		$recorded = [];
		$total = null;
		foreach ((array)($campaign['costs'] ?? []) as $entry) {
			if (is_array($entry) === false || isset($entry['amountEur']) === false) {
				continue;
			}

			$recorded[] = [
				'channel' => (string)($entry['channel'] ?? ''),
				'amountEur' => (float)$entry['amountEur'],
				'note' => (string)($entry['note'] ?? ''),
			];
			$total = ((float)($total ?? 0.0) + (float)$entry['amountEur']);
		}

		$budget = null;
		if (isset($campaign['budgetEur']) === true && is_numeric($campaign['budgetEur']) === true) {
			$budget = (float)$campaign['budgetEur'];
		}

		return ['budgetEur' => $budget, 'recorded' => $recorded, 'totalEur' => $total, 'currency' => 'EUR'];
	}            

	   
                                           
   
                                            
   
                                         
    
	private function emptyChannel(string $channel): array {
		return [
			'channel' => $channel,
			'reach' => 0,
			'sent' => 0,
			'opened' => 0,
			'click' => 0,
			'visit' => 0,
			'submit' => 0,
			'reply' => 0,
			'reported' => false,
		];
	}                    

	   
                                                                  
                                     
   
                                                       
                                             
                                         
   
                                                              
    
	private function window(array $campaign, ?string $from, ?string $to): array {
		$today = gmdate('Y-m-d', $this->time->getTime());

		$start = $this->day(value: $from);
		if ($start === '') {
			$start = $this->day(value: (string)($campaign['startsAt'] ?? ''));
		}

		if ($start === '') {
			$start = gmdate('Y-m-d', ($this->time->getTime() - (90 * 86400)));
		}

		$end = $this->day(value: $to);
		if ($end === '') {
			$end = $this->day(value: (string)($campaign['endsAt'] ?? ''));
		}

		if ($end === '' || $end > $today) {
			$end = $today;
		}

		if ($start > $end) {
			$start = $end;
		}

		return ['from' => $start, 'to' => $end];
	}              

	   
                                                                
   
                                        
   
                                                    
    
	private function day(?string $value): string {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($value), $matches) !== 1) {
			return '';
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return '';
		}

		return ($matches[1] . '-' . $matches[2] . '-' . $matches[3]);
	}           
}           
