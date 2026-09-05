<?php

/**
 * Unit tests for SegmentSignalService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\BookkeepingSignals;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\ShillinqInvoiceReader;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A reader that answers from arrays instead of shillinq's register.
 *
 * It stands in for the real reader rather than being mocked, because the
 * degradation path is the behaviour under test and `isAvailable()` returning
 * false has to be reachable without shillinq being installed.
 */
class FakeShillinqInvoiceReader extends ShillinqInvoiceReader {

	/**
	 * Whether shillinq answers at all.
	 *
	 * @var bool
	 */
	public bool $available = true;

	/**
	 * Customer reference to invoices.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $invoices = [];

	/**
	 * Construct without the real collaborators.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * Whether shillinq answers.
	 *
	 * @return bool True when it does.
	 */
	public function isAvailable(): bool {
		return $this->available;
	}//end isAvailable()

	/**
	 * Every invoice past the draft stage.
	 *
	 * @param string $customerRef The customer.
	 *
	 * @return array<int, array<string, mixed>> The invoices.
	 */
	public function invoicesFor(string $customerRef): array {
		if ($this->available === false) {
			return [];
		}

		$out = [];
		foreach (($this->invoices[$customerRef] ?? []) as $invoice) {
			if ((string)($invoice['lifecycleState'] ?? '') !== 'draft') {
				$out[] = $invoice;
			}
		}

		return $out;
	}//end invoicesFor()

	/**
	 * The paid invoices inside a window.
	 *
	 * @param string $customerRef The customer.
	 * @param string $from Window start.
	 * @param string $to Window end.
	 *
	 * @return array<int, array<string, mixed>> The invoices.
	 */
	public function paidInvoicesFor(string $customerRef, string $from, string $to): array {
		$out = [];
		foreach ($this->invoicesFor(customerRef: $customerRef) as $invoice) {
			$date = (string)($invoice['invoiceDate'] ?? '');
			if ((string)($invoice['lifecycleState'] ?? '') !== 'paid') {
				continue;
			}

			if ($date !== '' && ($date < $from || $date > $to)) {
				continue;
			}

			$out[] = $invoice;
		}

		return $out;
	}//end paidInvoicesFor()
}//end class

/**
 * Tests for SegmentSignalService: each derivation, and what happens when
 * the bookkeeping cannot be read.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */
class SegmentSignalServiceTest extends TestCase {

	/**
	 * A fixed "now" so every day count in this file is arithmetic, not weather.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-05 12:00:00';

	/**
	 * The store the service reads clients, contracts, leads and products from.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The stand-in shillinq reader.
	 *
	 * @var FakeShillinqInvoiceReader
	 */
	private FakeShillinqInvoiceReader $reader;

	/**
	 * Set up a customer with a bookkeeping reference and a small catalogue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryListObjectStore([
			'client' => [
				['uuid' => 'client-1', 'name' => 'Bakkerij', 'shillinqOrganisationRef' => 'cust-1'],
				['uuid' => 'client-2', 'name' => 'Gemeente', 'shillinqOrganisationRef' => ''],
			],
			'contact' => [
				['uuid' => 'contact-1', 'name' => 'Anna', 'email' => 'anna@example.org', 'client' => 'client-1'],
			],
			'product' => [
				['uuid' => 'p-1', 'name' => '[Demo] Adviesuur', 'type' => 'service'],
				['uuid' => 'p-2', 'name' => '[Demo] Koffie zwart', 'type' => 'product'],
			],
		]);

		$this->reader = new FakeShillinqInvoiceReader();
	}//end setUp()

	/**
	 * Recognised revenue sums the PAID invoices inside the window and
	 * ignores the unpaid one, however large.
	 *
	 * @return void
	 */
	public function testRecognisedRevenueSumsPaidInvoicesInTheWindow(): void {
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'paid', 'amount' => 20000.0, 'invoiceDate' => '2026-04-01', 'lines' => []],
			['lifecycleState' => 'paid', 'amount' => 8000.0, 'invoiceDate' => '2026-07-01', 'lines' => []],
			['lifecycleState' => 'overdue', 'amount' => 90000.0, 'invoiceDate' => '2026-08-01', 'lines' => []],
		];

		$signals = $this->service()->signalsFor(clientId: 'client-1');

		$this->assertSame(28000.0, $signals[SegmentSignalService::FIELD_REVENUE]);
	}//end testRecognisedRevenueSumsPaidInvoicesInTheWindow()

	/**
	 * The tier follows the configured thresholds, and a customer with no
	 * paid invoice at all is `none` rather than `low`.
	 *
	 * @return void
	 */
	public function testValueTierFollowsTheConfiguredThresholds(): void {
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'paid', 'amount' => 28000.0, 'invoiceDate' => '2026-04-01', 'lines' => []],
		];
		$this->assertSame('top', $this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_VALUE_TIER]);

		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'paid', 'amount' => 6000.0, 'invoiceDate' => '2026-04-01', 'lines' => []],
		];
		$this->assertSame('mid', $this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_VALUE_TIER]);

		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'overdue', 'amount' => 6000.0, 'invoiceDate' => '2026-04-01', 'lines' => []],
		];
		$this->assertSame('none', $this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_VALUE_TIER]);
	}//end testValueTierFollowsTheConfiguredThresholds()

	/**
	 * A draft invoice is not contact with the customer, so a lapsed
	 * customer is dated from the last invoice that was actually sent.
	 *
	 * @return void
	 */
	public function testDraftInvoicesAreNotContact(): void {
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'draft', 'amount' => 100.0, 'invoiceDate' => '2026-09-01', 'lines' => []],
			['lifecycleState' => 'paid', 'amount' => 100.0, 'invoiceDate' => '2025-07-05', 'lines' => []],
		];

		$this->assertSame(
			14,
			$this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_MONTHS_SINCE_INVOICE]
		);
	}//end testDraftInvoicesAreNotContact()

	/**
	 * Only a line matching the product catalogue is classified. A line
	 * naming something the catalogue does not hold contributes to neither
	 * list, because a guess in a cross-sell audience mails the wrong people.
	 *
	 * @return void
	 */
	public function testOnlyCatalogueItemsAreClassified(): void {
		$this->reader->invoices['cust-1'] = [
			[
				'lifecycleState' => 'paid',
				'amount' => 200.0,
				'invoiceDate' => '2026-08-01',
				'lines' => [
					['itemName' => '[Demo] Adviesuur'],
					['itemName' => 'Reiskosten'],
				],
			],
		];

		$signals = $this->service()->signalsFor('client-1');

		$this->assertSame(['[demo] adviesuur'], $signals[SegmentSignalService::FIELD_SERVICES]);
		$this->assertSame([], $signals[SegmentSignalService::FIELD_PRODUCTS]);
	}//end testOnlyCatalogueItemsAreClassified()

	/**
	 * The dunning state is the worst state across the customer's invoices.
	 *
	 * @return void
	 */
	public function testDunningStateIsTheWorstAcrossInvoices(): void {
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'paid', 'amount' => 100.0, 'invoiceDate' => '2026-08-01', 'lines' => []],
			['lifecycleState' => 'overdue', 'amount' => 100.0, 'invoiceDate' => '2026-08-02', 'lines' => []],
		];

		$this->assertSame('overdue', $this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_DUNNING]);
	}//end testDunningStateIsTheWorstAcrossInvoices()

	/**
	 * A contact's dunning state resolves through the client it belongs to,
	 * which is all the consent gate has to go on.
	 *
	 * @return void
	 */
	public function testDunningStateForContactResolvesThroughTheClient(): void {
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'overdue', 'amount' => 100.0, 'invoiceDate' => '2026-08-02', 'lines' => []],
		];

		$this->assertSame('overdue', $this->service()->dunningStateForContact(contactId: 'contact-1'));
	}//end testDunningStateForContactResolvesThroughTheClient()

	/**
	 * Every bookkeeping signal resolves to nothing when shillinq is absent.
	 * Nothing here may answer zero: a zero is a number the evaluator
	 * compares, and a comparison against a number nobody supplied is how a
	 * "no invoice in twelve months" audience swallows the whole customer base.
	 *
	 * @return void
	 */
	public function testEveryBookkeepingSignalIsNullWithoutShillinq(): void {
		$this->reader->available = false;
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'paid', 'amount' => 28000.0, 'invoiceDate' => '2026-04-01', 'lines' => []],
		];

		$signals = $this->service()->signalsFor('client-1');

		foreach ([
			SegmentSignalService::FIELD_REVENUE,
			SegmentSignalService::FIELD_VALUE_TIER,
			SegmentSignalService::FIELD_MONTHS_SINCE_INVOICE,
			SegmentSignalService::FIELD_PRODUCTS,
			SegmentSignalService::FIELD_SERVICES,
			SegmentSignalService::FIELD_DUNNING,
		] as $field) {
			$this->assertNull($signals[$field], $field . ' must resolve to nothing, not to zero');
		}

		$this->assertFalse($this->service()->availability()['shillinq']);
	}//end testEveryBookkeepingSignalIsNullWithoutShillinq()

	/**
	 * A customer whose shillinqOrganisationRef is empty reads as absent
	 * too, which is the state every seeded demo client is in.
	 *
	 * @return void
	 */
	public function testACustomerWithoutABookkeepingReferenceResolvesToNothing(): void {
		$this->reader->invoices[''] = [
			['lifecycleState' => 'paid', 'amount' => 5.0, 'invoiceDate' => '2026-08-01', 'lines' => []],
		];

		$this->assertNull($this->service()->signalsFor('client-2')[SegmentSignalService::FIELD_REVENUE]);
	}//end testACustomerWithoutABookkeepingReferenceResolvesToNothing()

	/**
	 * The renewal signal takes the NEAREST contract end, not the first row.
	 *
	 * @return void
	 */
	public function testRenewalDaysTakesTheNearestContractEnd(): void {
		$this->store->save('contract', ['clientRef' => 'client-1', 'endDate' => $this->inDays(245)]);
		$this->store->save('contract', ['clientRef' => 'client-1', 'endDate' => $this->inDays(45)]);

		$this->assertSame(
			45,
			$this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_RENEWAL_DAYS]
		);
	}//end testRenewalDaysTakesTheNearestContractEnd()

	/**
	 * A contract that already ended reports a negative number rather than
	 * nothing, so one field answers both "renewing soon" and "lapsed".
	 *
	 * @return void
	 */
	public function testAPastContractEndIsNegative(): void {
		$this->store->save('contract', ['clientRef' => 'client-1', 'endDate' => $this->inDays(-30)]);

		$this->assertSame(
			-30,
			$this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_RENEWAL_DAYS]
		);
	}//end testAPastContractEndIsNegative()

	/**
	 * The stall signal counts from the moment the lead entered its stage.
	 *
	 * @return void
	 */
	public function testStalledDaysCountsFromTheStageEntry(): void {
		$this->store->save('lead', ['client' => 'client-1', 'status' => 'open', 'stageEnteredAt' => $this->inDays(-31)]);

		$this->assertSame(
			31,
			$this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_STALLED_DAYS]
		);
	}//end testStalledDaysCountsFromTheStageEntry()

	/**
	 * A closed lead is not stalled, and a customer with only closed leads
	 * resolves to nothing rather than to zero days.
	 *
	 * @return void
	 */
	public function testStalledDaysIsNullWithoutADatedOpenLead(): void {
		$this->store->save('lead', ['client' => 'client-1', 'status' => 'closed-won', 'stageEnteredAt' => $this->inDays(-90)]);

		$this->assertNull($this->service()->signalsFor('client-1')[SegmentSignalService::FIELD_STALLED_DAYS]);
	}//end testStalledDaysIsNullWithoutADatedOpenLead()

	/**
	 * The catalogue and the property map agree on every field, so the
	 * validator can never be offered a field the evaluator cannot answer.
	 *
	 * @return void
	 */
	public function testTheCatalogueAndThePropertyMapAgree(): void {
		$service = $this->service();

		$this->assertSame(array_keys($service->catalogue()), array_keys($service->schemaProperties()));
		foreach (array_keys($service->catalogue()) as $field) {
			$this->assertTrue($service->isSignalField(field: $field));
		}

		$this->assertFalse($service->isSignalField(field: 'email'));
	}//end testTheCatalogueAndThePropertyMapAgree()

	/**
	 * A fresh service over the current store and reader.
	 *
	 * @return SegmentSignalService The service.
	 */
	private function service(): SegmentSignalService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $default
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime(self::NOW));

		return new SegmentSignalService(
			$this->store,
			new BookkeepingSignals($this->reader, $appConfig, $time),
			$time,
		);
	}//end service()

	/**
	 * A date this many days from the fixed now.
	 *
	 * @param int $days The offset, negative for the past.
	 *
	 * @return string The date as `YYYY-MM-DD`.
	 */
	private function inDays(int $days): string {
		return date('Y-m-d', ((int)strtotime(self::NOW) + ($days * 86400)));
	}//end inDays()
}//end class
