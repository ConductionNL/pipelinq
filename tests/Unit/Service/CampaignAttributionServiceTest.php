<?php

/**
 * Unit tests for CampaignAttributionService.
 *
 * Covers:
 * - first touch, last touch and linear divide one lead's value differently
 * - a lead with one touchpoint reports the same value under all three
 * - a paid shillinq invoice closes the lead, and the basis says so
 * - a won lead closes it when there is no invoice, or shillinq is absent
 * - one invoice counts once across two leads of the same client
 * - the totals are reported per basis, never as one number
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CampaignAttributionService;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for CampaignAttributionService.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */
class CampaignAttributionServiceTest extends TestCase {

	/**
	 * The store every read comes from.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Three touchpoints of one lead: a click, a visit and a submit.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function threeTouchpoints(): array {
		return [
			['uuid' => 'tp-1', 'campaignId' => 'camp-1', 'leadId' => 'lead-1', 'kind' => 'click', 'channel' => 'email', 'occurredAt' => '2026-10-14T10:21:33+00:00'],
			['uuid' => 'tp-2', 'campaignId' => 'camp-1', 'leadId' => 'lead-1', 'kind' => 'visit', 'channel' => 'social', 'occurredAt' => '2026-10-20T14:02:00+00:00'],
			['uuid' => 'tp-3', 'campaignId' => 'camp-1', 'leadId' => 'lead-1', 'kind' => 'submit', 'channel' => 'social', 'occurredAt' => '2026-10-21T09:47:12+00:00'],
		];
	}//end threeTouchpoints()

	/**
	 * Build a service over an in-memory store and a fake invoice reader.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $seed Rows to start from.
	 * @param bool $shillinq Whether shillinq answers at all.
	 * @param array<string, array<int, array<string, mixed>>> $invoices Customer reference to invoices.
	 *
	 * @return CampaignAttributionService
	 */
	private function build(array $seed, bool $shillinq = false, array $invoices = []): CampaignAttributionService {
		$this->store = new InMemoryListObjectStore($seed);

		$reader = new class ($shillinq, $invoices) extends ShillinqInvoiceReader {

			/**
			 * @param bool $available Whether shillinq answers.
			 * @param array<string, array<int, array<string, mixed>>> $invoices Customer to invoices.
			 */
			public function __construct(private bool $available, private array $invoices) {
			}//end __construct()

			/**
			 * @return bool
			 */
			public function isAvailable(): bool {
				return $this->available;
			}//end isAvailable()

			/**
			 * @param string $customerRef The customer.
			 * @param string $from Window start.
			 * @param string $to Window end.
			 *
			 * @return array<int, array<string, mixed>> The invoices.
			 */
			public function paidInvoicesFor(string $customerRef, string $from, string $to): array {
				if ($this->available === false) {
					return [];
				}

				return ($this->invoices[$customerRef] ?? []);
			}//end paidInvoicesFor()
		};

		return new CampaignAttributionService($this->store, new TouchpointService($this->store), $reader);
	}//end build()

	/**
	 * @return void
	 */
	public function testFirstTouchGivesTheEarliestTouchpointTheWholeValue(): void {
		$service = $this->build(seed: [
			'touchpoint' => $this->threeTouchpoints(),
			'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 3000]],
		]);

		$models = $service->forCampaign(campaignId: 'camp-1')['models'];

		$this->assertSame(['tp-1' => 3000.0], $models['first']['byTouchpoint']);
		$this->assertSame(['email' => 3000.0], $models['first']['byChannel']);
	}//end testFirstTouchGivesTheEarliestTouchpointTheWholeValue()

	/**
	 * @return void
	 */
	public function testLastTouchGivesTheLatestTouchpointTheWholeValue(): void {
		$service = $this->build(seed: [
			'touchpoint' => $this->threeTouchpoints(),
			'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 3000]],
		]);

		$models = $service->forCampaign(campaignId: 'camp-1')['models'];

		$this->assertSame(['tp-3' => 3000.0], $models['last']['byTouchpoint']);
	}//end testLastTouchGivesTheLatestTouchpointTheWholeValue()

	/**
	 * @return void
	 */
	public function testLinearSplitsEvenlyOverDistinctTouchpoints(): void {
		$service = $this->build(seed: [
			'touchpoint' => $this->threeTouchpoints(),
			'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 3000]],
		]);

		$models = $service->forCampaign(campaignId: 'camp-1')['models'];

		$this->assertSame(['tp-1' => 1000.0, 'tp-2' => 1000.0, 'tp-3' => 1000.0], $models['linear']['byTouchpoint']);
		$this->assertSame(['email' => 1000.0, 'social' => 2000.0], $models['linear']['byChannel']);
		$this->assertSame(3000.0, $models['linear']['total']);
	}//end testLinearSplitsEvenlyOverDistinctTouchpoints()

	/**
	 * @return void
	 */
	public function testASingleTouchpointMakesTheThreeModelsAgree(): void {
		$service = $this->build(seed: [
			'touchpoint' => [['uuid' => 'tp-1', 'campaignId' => 'camp-1', 'leadId' => 'lead-1', 'kind' => 'submit', 'channel' => 'email', 'occurredAt' => '2026-10-21T09:47:12+00:00']],
			'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 500]],
		]);

		$models = $service->forCampaign(campaignId: 'camp-1')['models'];

		$this->assertSame(['tp-1' => 500.0], $models['first']['byTouchpoint']);
		$this->assertSame($models['first']['byTouchpoint'], $models['last']['byTouchpoint']);
		$this->assertSame($models['first']['byTouchpoint'], $models['linear']['byTouchpoint']);
	}//end testASingleTouchpointMakesTheThreeModelsAgree()

	/**
	 * @return void
	 */
	public function testAPaidInvoiceClosesTheLead(): void {
		$service = $this->build(
			seed: [
				'touchpoint' => $this->threeTouchpoints(),
				'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 3000, 'client' => 'client-1']],
				'client' => [['uuid' => 'client-1', 'name' => 'Gemeente Voorbeeld', 'shillinqOrganisationRef' => 'cust-1']],
			],
			shillinq: true,
			invoices: ['cust-1' => [['id' => 'inv-1', 'amount' => 4840.0, 'currency' => 'EUR', 'invoiceDate' => '2026-11-02', 'invoiceNumber' => '2026-014']]]
		);

		$record = $service->forCampaign(campaignId: 'camp-1', from: '2026-10-01', to: '2026-11-30');

		$this->assertSame('paid_invoice', $record['leads'][0]['basis']);
		$this->assertSame(4840.0, $record['leads'][0]['value']);
		$this->assertSame(['inv-1'], $record['leads'][0]['invoiceIds']);
		$this->assertSame(4840.0, $record['totals']['attributedValue']);
		$this->assertSame(4840.0, $record['totals']['byBasis']['paid_invoice']['value']);
		$this->assertSame(0.0, $record['totals']['byBasis']['won_lead']['value']);
	}//end testAPaidInvoiceClosesTheLead()

	/**
	 * @return void
	 */
	public function testAWonLeadClosesWhenThereIsNoInvoice(): void {
		$service = $this->build(
			seed: [
				'touchpoint' => $this->threeTouchpoints(),
				'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 3000, 'client' => 'client-1']],
				'client' => [['uuid' => 'client-1', 'name' => 'Gemeente Voorbeeld', 'shillinqOrganisationRef' => 'cust-1']],
			],
			shillinq: true,
			invoices: []
		);

		$record = $service->forCampaign(campaignId: 'camp-1');

		$this->assertSame('won_lead', $record['leads'][0]['basis']);
		$this->assertSame(3000.0, $record['leads'][0]['value']);
	}//end testAWonLeadClosesWhenThereIsNoInvoice()

	/**
	 * @return void
	 */
	public function testShillinqAbsentFallsBackToTheWonLead(): void {
		$service = $this->build(
			seed: [
				'touchpoint' => $this->threeTouchpoints(),
				'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 3000, 'client' => 'client-1']],
				'client' => [['uuid' => 'client-1', 'shillinqOrganisationRef' => 'cust-1']],
			],
			shillinq: false,
			invoices: ['cust-1' => [['id' => 'inv-1', 'amount' => 4840.0, 'currency' => 'EUR', 'invoiceDate' => '2026-11-02', 'invoiceNumber' => '2026-014']]]
		);

		$record = $service->forCampaign(campaignId: 'camp-1');

		$this->assertSame('won_lead', $record['leads'][0]['basis']);
		$this->assertSame(3000.0, $record['leads'][0]['value']);
	}//end testShillinqAbsentFallsBackToTheWonLead()

	/**
	 * @return void
	 */
	public function testAnInvoiceCountsOnceAcrossTwoLeadsOfTheSameClient(): void {
		$service = $this->build(
			seed: [
				'touchpoint' => [
					['uuid' => 'tp-1', 'campaignId' => 'camp-1', 'leadId' => 'lead-1', 'kind' => 'submit', 'channel' => 'email', 'occurredAt' => '2026-10-01T09:00:00+00:00'],
					['uuid' => 'tp-2', 'campaignId' => 'camp-1', 'leadId' => 'lead-2', 'kind' => 'submit', 'channel' => 'email', 'occurredAt' => '2026-10-02T09:00:00+00:00'],
				],
				'lead' => [
					['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'won', 'value' => 100, 'client' => 'client-1'],
					['uuid' => 'lead-2', 'campaignId' => 'camp-1', 'status' => 'open', 'value' => 100, 'client' => 'client-1'],
				],
				'client' => [['uuid' => 'client-1', 'shillinqOrganisationRef' => 'cust-1']],
			],
			shillinq: true,
			invoices: ['cust-1' => [['id' => 'inv-1', 'amount' => 4840.0, 'currency' => 'EUR', 'invoiceDate' => '2026-11-02', 'invoiceNumber' => '2026-014']]]
		);

		$record = $service->forCampaign(campaignId: 'camp-1');

		$bases = array_column($record['leads'], 'basis', 'leadId');
		$this->assertSame('paid_invoice', $bases['lead-1']);
		$this->assertSame('open', $bases['lead-2']);
		$this->assertSame(4840.0, $record['totals']['attributedValue']);
	}//end testAnInvoiceCountsOnceAcrossTwoLeadsOfTheSameClient()

	/**
	 * @return void
	 */
	public function testAnOpenLeadContributesNothing(): void {
		$service = $this->build(seed: [
			'touchpoint' => $this->threeTouchpoints(),
			'lead' => [['uuid' => 'lead-1', 'campaignId' => 'camp-1', 'status' => 'open', 'value' => 3000]],
		]);

		$record = $service->forCampaign(campaignId: 'camp-1');

		$this->assertSame('open', $record['leads'][0]['basis']);
		$this->assertSame(0.0, $record['totals']['attributedValue']);
		$this->assertSame([], $record['models']['linear']['byTouchpoint']);
	}//end testAnOpenLeadContributesNothing()

	/**
	 * A stage of `closed-won` and a status of `won` mean the same thing;
	 * the app carries both spellings and the report must not depend on
	 * which one a pipeline happens to use.
	 *
	 * @return void
	 */
	public function testBothSpellingsOfWonAreAccepted(): void {
		$service = $this->build(seed: []);

		$this->assertTrue($service->isWon(lead: ['status' => 'won']));
		$this->assertTrue($service->isWon(lead: ['stage' => 'closed-won']));
		$this->assertTrue($service->isWon(lead: ['stage' => 'Won']));
		$this->assertFalse($service->isWon(lead: ['status' => 'open', 'stage' => 'qualified']));
	}//end testBothSpellingsOfWonAreAccepted()
}//end class
