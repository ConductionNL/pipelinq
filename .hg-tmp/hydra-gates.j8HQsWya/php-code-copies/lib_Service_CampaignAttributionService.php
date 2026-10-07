<?php

   
                                       
  
                                                                     
                                                                     
  
                                                                        
                                                                        
                                                                      
                                                                   
                                                                      
                                                             
  
                                                                      
                                                                        
                                                                    
                                                                   
                                                                        
                                                                     
                        
  
                    
                                 
  
                                             
                                  
                                                                                    
  
                         
  
                                                 
  
                                                                                                                                                  
   

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

   
                                                                              
  
                                                                                                                                                  
   
class CampaignAttributionService {

	   
                                                                      
                                                                   
                                                               
   
                           
    
	public const MODELS = ['first', 'last', 'linear'];

	   
                         
   
               
    
	public const LEAD_SCHEMA_SLUG = 'lead';

	   
                           
   
               
    
	public const CLIENT_SCHEMA_SLUG = 'client';

	   
                                                                     
                                                                       
                                                
   
               
    
	public const CLIENT_SHILLINQ_REF = 'shillinqOrganisationRef';

	   
                                                        
   
               
    
	public const BASIS_PAID_INVOICE = 'paid_invoice';

	   
                                              
   
               
    
	public const BASIS_WON_LEAD = 'won_lead';

	   
                                                  
   
               
    
	public const BASIS_OPEN = 'open';

	   
                
   
                                                                
                                                             
                                                                       
   
                                                                                                                                                   
    
	public function __construct(
		private ListObjectStore $store,
		private TouchpointService $touchpoints,
		private ShillinqInvoiceReader $invoices,
	) {
	}                   

	   
                                               
   
                                           
                                                                
                                                            
   
                                                                         
                                                   
   
                                                                                                                                                   
    
	public function forCampaign(string $campaignId, string $from = '', string $to = ''): array {
		$touchpoints = $this->touchpoints->forCampaign(campaignId: $campaignId);
		$leads = $this->leadsFor(campaignId: $campaignId, touchpoints: $touchpoints);
		$closes = $this->closeAll(leads: $leads, from: $from, to: $to);

		return [
			'touchpoints' => $touchpoints,
			'leads' => array_values($closes),
			'totals' => $this->totals(closes: $closes),
			'models' => $this->models(touchpoints: $touchpoints, closes: $closes),
		];
	}                   

	   
                                                              
   
                                                                      
                                                                     
                                                                      
                                                                 
   
                                                                                              
                                                
                                                          
   
                                                                   
   
                                                                                                                                                   
    
	public function split(array $touchpoints, float $value, string $model): array {
		$ids = [];
		foreach ($touchpoints as $touchpoint) {
			$id = $this->touchpoints->idOf(touchpoint: $touchpoint);
			if ($id !== '' && in_array($id, $ids, true) === false) {
				$ids[] = $id;
			}
		}

		if ($ids === [] || $value === 0.0) {
			return [];
		}

		if ($model === 'first') {
			return [$ids[0] => $value];
		}

		if ($model === 'last') {
			return [$ids[(count($ids) - 1)] => $value];
		}

		$share = ($value / count($ids));
		$split = [];
		foreach ($ids as $id) {
			$split[$id] = $share;
		}

		return $split;
	}             

	   
                                              
   
                                                   
                                     
                                 
                                                                                         
   
                                                                                                
                      
   
                                                                                                                                                                            
    
	public function closeFor(array $lead, string $from, string $to, array $claimed = []): array {
		$customerRef = $this->customerRefFor(lead: $lead);
		if ($customerRef !== '') {
			$invoices = $this->invoices->paidInvoicesFor(customerRef: $customerRef, from: $from, to: $to);
			$total = 0.0;
			$ids = [];
			foreach ($invoices as $invoice) {
				if (isset($claimed[$invoice['id']]) === true) {
					continue;
				}

				$total += $invoice['amount'];
				$ids[] = $invoice['id'];
			}

			if ($ids !== []) {
				return [
					'basis' => self::BASIS_PAID_INVOICE,
					'value' => $total,
					'currency' => 'EUR',
					'invoiceIds' => $ids,
				];
			}
		}

		if ($this->isWon(lead: $lead) === true) {
			return [
				'basis' => self::BASIS_WON_LEAD,
				'value' => (float)($lead['value'] ?? 0),
				'currency' => 'EUR',
				'invoiceIds' => [],
			];
		}

		return ['basis' => self::BASIS_OPEN, 'value' => 0.0, 'currency' => 'EUR', 'invoiceIds' => []];
	}                

	   
                                 
   
                                                                 
                                                                     
                                                         
   
                                                   
   
                                           
   
                                                                                                                                                                            
    
	public function isWon(array $lead): bool {
		if (strtolower(trim((string)($lead['status'] ?? ''))) === 'won') {
			return true;
		}

		$stage = strtolower(trim((string)($lead['stage'] ?? '')));

		return ($stage === 'won' || $stage === 'closed-won' || $stage === 'closed won');
	}             

	   
                                                                      
                                                                       
                                                                       
                               
   
                                           
                                                                                    
   
                                                                    
    
	private function leadsFor(string $campaignId, array $touchpoints): array {
		$leads = [];
		foreach ($this->store->findAll(schemaSlug: self::LEAD_SCHEMA_SLUG, filters: ['campaignId' => $campaignId]) as $lead) {
			$id = $this->store->idOf(payload: $lead);
			if ($id !== '') {
				$leads[$id] = $lead;
			}
		}

		foreach ($touchpoints as $touchpoint) {
			$leadId = trim((string)($touchpoint['leadId'] ?? ''));
			if ($leadId === '' || isset($leads[$leadId]) === true) {
				continue;
			}

			$lead = $this->store->find(schemaSlug: self::LEAD_SCHEMA_SLUG, id: $leadId);
			if ($lead !== null) {
				$leads[$leadId] = $lead;
			}
		}

		return $leads;
	}                

	   
                                                 
   
                                                                          
                                     
                                 
   
                                                                             
    
	private function closeAll(array $leads, string $from, string $to): array {
		$claimed = [];
		$closes = [];
		foreach ($leads as $leadId => $lead) {
			$close = $this->closeFor(lead: $lead, from: $from, to: $to, claimed: $claimed);
			foreach ($close['invoiceIds'] as $invoiceId) {
				$claimed[$invoiceId] = $leadId;
			}

			$closes[$leadId] = [
				'leadId' => $leadId,
				'title' => (string)($lead['title'] ?? ''),
				'contactId' => (string)($lead['contact'] ?? ''),
				'clientId' => (string)($lead['client'] ?? ''),
				'basis' => $close['basis'],
				'value' => $close['value'],
				'currency' => $close['currency'],
				'invoiceIds' => $close['invoiceIds'],
			];
		}

		return $closes;
	}                

	   
                                 
   
                                                                     
                                                                    
                                        
   
                                                                                
   
                                            
    
	private function totals(array $closes): array {
		$totals = [
			self::BASIS_PAID_INVOICE => ['leads' => 0, 'value' => 0.0],
			self::BASIS_WON_LEAD => ['leads' => 0, 'value' => 0.0],
			self::BASIS_OPEN => ['leads' => 0, 'value' => 0.0],
		];

		foreach ($closes as $close) {
			$basis = (string)$close['basis'];
			$totals[$basis]['leads']++;
			$totals[$basis]['value'] += (float)$close['value'];
		}

		return [
			'byBasis' => $totals,
			'leads' => count($closes),
			'attributedValue' => ($totals[self::BASIS_PAID_INVOICE]['value'] + $totals[self::BASIS_WON_LEAD]['value']),
			'currency' => 'EUR',
		];
	}              

	   
                                             
   
                                                                                                  
                                                                                
   
                                                                                  
    
	private function models(array $touchpoints, array $closes): array {
		$perLead = [];
		foreach ($touchpoints as $touchpoint) {
			$leadId = trim((string)($touchpoint['leadId'] ?? ''));
			if ($leadId === '') {
				continue;
			}

			$perLead[$leadId][] = $touchpoint;
		}

		$channels = [];
		foreach ($touchpoints as $touchpoint) {
			$id = $this->touchpoints->idOf(touchpoint: $touchpoint);
			if ($id !== '') {
				$channels[$id] = (string)($touchpoint['channel'] ?? 'direct');
			}
		}

		$models = [];
		foreach (self::MODELS as $model) {
			$models[$model] = $this->oneModel(perLead: $perLead, closes: $closes, channels: $channels, model: $model);
		}

		return $models;
	}              

	   
                              
   
                                                                                               
                                                                                
                                                                    
                                   
   
                                                                                                    
                               
    
	private function oneModel(array $perLead, array $closes, array $channels, string $model): array {
		$byTouchpoint = [];
		$byChannel = [];
		$total = 0.0;

		foreach ($perLead as $leadId => $leadTouchpoints) {
			$value = (float)($closes[$leadId]['value'] ?? 0);
			foreach ($this->split(touchpoints: $leadTouchpoints, value: $value, model: $model) as $id => $share) {
				$byTouchpoint[$id] = (($byTouchpoint[$id] ?? 0.0) + $share);
				$channel = ($channels[$id] ?? 'direct');
				$byChannel[$channel] = (($byChannel[$channel] ?? 0.0) + $share);
				$total += $share;
			}
		}

		return ['byTouchpoint' => $byTouchpoint, 'byChannel' => $byChannel, 'total' => $total];
	}                

	   
                                                            
   
                                                   
   
                                                     
    
	private function customerRefFor(array $lead): string {
		$clientId = trim((string)($lead['client'] ?? ''));
		if ($clientId === '' || $this->invoices->isAvailable() === false) {
			return '';
		}

		$client = $this->store->find(schemaSlug: self::CLIENT_SCHEMA_SLUG, id: $clientId);
		if ($client === null) {
			return '';
		}

		return trim((string)($client[self::CLIENT_SHILLINQ_REF] ?? ''));
	}                      
}           
