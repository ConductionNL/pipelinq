<?php

/**
 * Pipelinq CampaignAttributionService.
 *
 * Divides a campaign's value over the touchpoints that earned it, in
 * three models, and decides what a lead is worth in the first place.
 *
 * 🔴 NOTHING HERE IS STORED. The models are computed from the touchpoint
 * log every time the report is read. A stored answer is a second truth:
 * it disagrees with the log the moment a touchpoint arrives late, and
 * nobody can tell which of the two is wrong. The `attributionLink`
 * schema carries `model` and `touchpointIds` so a written row can say
 * what produced it; this service never reads its own output.
 *
 * Closing has two rules and the report says which one it used. A lead
 * whose client maps to a shillinq customer with paid AR invoices in the
 * window closes on that money. Any other won lead closes on its own
 * value, which is a forecast and is reported as one. An invoice is
 * claimed by at most one lead per report: two leads for the same client
 * would otherwise each count it and the campaign would report double
 * what shillinq booked.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * CampaignAttributionService: first touch, last touch, linear, and the close.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */
class CampaignAttributionService {

	/**
	 * The models this service computes. All three, every time: computing
	 * one is no cheaper than computing three over the same log, and a
	 * reader who has to reload the page to compare them will not.
	 *
	 * @var array<int, string>
	 */
	public const MODELS = ['first', 'last', 'linear'];

	/**
	 * The lead schema slug.
	 *
	 * @var string
	 */
	public const LEAD_SCHEMA_SLUG = 'lead';

	/**
	 * The client schema slug.
	 *
	 * @var string
	 */
	public const CLIENT_SCHEMA_SLUG = 'client';

	/**
	 * The client property carrying the shillinq customer reference. The
	 * seam `time-billing-handoff-emit` already established, reused rather
	 * than a second mapping nobody would maintain.
	 *
	 * @var string
	 */
	public const CLIENT_SHILLINQ_REF = 'shillinqOrganisationRef';

	/**
	 * Closing basis: money shillinq recorded as collected.
	 *
	 * @var string
	 */
	public const BASIS_PAID_INVOICE = 'paid_invoice';

	/**
	 * Closing basis: a lead somebody marked won.
	 *
	 * @var string
	 */
	public const BASIS_WON_LEAD = 'won_lead';

	/**
	 * Closing basis: the lead has not closed at all.
	 *
	 * @var string
	 */
	public const BASIS_OPEN = 'open';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param TouchpointService $touchpoints The touchpoint log.
	 * @param ShillinqInvoiceReader $invoices Paid AR invoices, read only.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
	 */
	public function __construct(
		private ListObjectStore $store,
		private TouchpointService $touchpoints,
		private ShillinqInvoiceReader $invoices,
	) {
	}//end __construct()

	/**
	 * Attribution for one campaign over a window.
	 *
	 * @param string $campaignId The campaign.
	 * @param string $from Window start `YYYY-MM-DD`, may be empty.
	 * @param string $to Window end `YYYY-MM-DD`, may be empty.
	 *
	 * @return array<string, mixed> `touchpoints[]`, `leads[]`, `totals` and
	 *         `models`: the whole attribution record.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
	 */
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
	}//end forCampaign()

	/**
	 * Split one lead's value over its touchpoints, in one model.
	 *
	 * First touch gives the whole value to the earliest touchpoint, last
	 * touch to the latest, and linear splits it evenly. A lead with one
	 * touchpoint therefore reports the same value under all three, which
	 * is the property that says the models agree where they should.
	 *
	 * @param array<int, array<string, mixed>> $touchpoints The lead's touchpoints, oldest first.
	 * @param float $value The lead's closed value.
	 * @param string $model One of `first`, `last`, `linear`.
	 *
	 * @return array<string, float> Touchpoint id to attributed value.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
	 */
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
	}//end split()

	/**
	 * What one lead closed on, and for how much.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 * @param string $from Window start.
	 * @param string $to Window end.
	 * @param array<string, string> $claimed Invoice id to the lead that already counted it.
	 *
	 * @return array{basis: string, value: float, currency: string, invoiceIds: array<int, string>}
	 *         The close.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
	 */
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
	}//end closeFor()

	/**
	 * Whether a lead counts as won.
	 *
	 * Both spellings are accepted because the app carries both: the
	 * schema's own `status` enum says `won`, while `AttributionService`
	 * and the pipeline board speak of a `closed-won` stage.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 *
	 * @return bool True when the lead is won.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
	 */
	public function isWon(array $lead): bool {
		if (strtolower(trim((string)($lead['status'] ?? ''))) === 'won') {
			return true;
		}

		$stage = strtolower(trim((string)($lead['stage'] ?? '')));

		return ($stage === 'won' || $stage === 'closed-won' || $stage === 'closed won');
	}//end isWon()

	/**
	 * Every lead of a campaign: those naming it, plus those a touchpoint
	 * names. Both, because a lead created before the campaign existed can
	 * still be reached by a touchpoint, and a lead with no touchpoint yet
	 * still belongs in the count.
	 *
	 * @param string $campaignId The campaign.
	 * @param array<int, array<string, mixed>> $touchpoints The campaign's touchpoints.
	 *
	 * @return array<string, array<string, mixed>> Lead id to lead row.
	 */
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
	}//end leadsFor()

	/**
	 * Close every lead, claiming each invoice once.
	 *
	 * @param array<string, array<string, mixed>> $leads Lead id to lead row.
	 * @param string $from Window start.
	 * @param string $to Window end.
	 *
	 * @return array<string, array<string, mixed>> Lead id to the closed record.
	 */
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
	}//end closeAll()

	/**
	 * The totals per closing basis.
	 *
	 * Reported separately, never as one number, so a reader can see how
	 * much of the attributed value is money shillinq collected and how
	 * much is a forecast somebody entered.
	 *
	 * @param array<string, array<string, mixed>> $closes Lead id to closed record.
	 *
	 * @return array<string, mixed> The totals.
	 */
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
	}//end totals()

	/**
	 * The three models over the whole campaign.
	 *
	 * @param array<int, array<string, mixed>> $touchpoints The campaign's touchpoints, oldest first.
	 * @param array<string, array<string, mixed>> $closes Lead id to closed record.
	 *
	 * @return array<string, mixed> Model to `byTouchpoint`, `byChannel` and `total`.
	 */
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
	}//end models()

	/**
	 * One model over every lead.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $perLead Lead id to its touchpoints.
	 * @param array<string, array<string, mixed>> $closes Lead id to closed record.
	 * @param array<string, string> $channels Touchpoint id to channel.
	 * @param string $model The model.
	 *
	 * @return array{byTouchpoint: array<string, float>, byChannel: array<string, float>, total: float}
	 *         The model's answer.
	 */
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
	}//end oneModel()

	/**
	 * The shillinq customer reference a lead's client carries.
	 *
	 * @param array<string, mixed> $lead The lead row.
	 *
	 * @return string The reference, or an empty string.
	 */
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
	}//end customerRefFor()
}//end class
