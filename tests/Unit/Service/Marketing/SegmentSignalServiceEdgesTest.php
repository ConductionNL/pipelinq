<?php

/**
 * Edge cases of SegmentSignalService: availability, unresolved signals, the
 * contact-to-client hop, memoisation and the rows a day count ignores.
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
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-an-unresolved-signal-shrinks-the-audience
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\BookkeepingSignals;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the SegmentSignalService paths the derivation tests do not reach.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\SegmentSignalService
 * @uses   \OCA\Pipelinq\Service\Marketing\BookkeepingSignals
 * @uses   \OCA\Pipelinq\Service\Marketing\LastContactReader
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-an-unresolved-signal-shrinks-the-audience
 */
class SegmentSignalServiceEdgesTest extends TestCase {

	/**
	 * A fixed "now" so every day count is arithmetic.
	 *
	 * @var string
	 */
	private const NOW = '2026-09-05 12:00:00';

	/**
	 * The store the service reads from.
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
	 * A customer with a bookkeeping reference, one without, and their contacts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryListObjectStore(seed: [
			'client' => [
				['uuid' => 'client-1', 'name' => 'Bakkerij', 'shillinqOrganisationRef' => 'cust-1'],
				['uuid' => 'client-2', 'name' => 'Gemeente', 'shillinqOrganisationRef' => ''],
			],
			'contact' => [
				['uuid' => 'contact-1', 'name' => 'Anna', 'client' => 'client-1'],
				['uuid' => 'contact-2', 'name' => 'Bram', 'client' => 'client-2'],
			],
			'product' => [
				['uuid' => 'p-0', 'name' => '   ', 'type' => 'service'],
				['uuid' => 'p-1', 'name' => 'Adviesuur', 'type' => 'service'],
			],
		]);
		$this->reader = new FakeShillinqInvoiceReader();
	}//end setUp()

	/**
	 * Availability names the reason only when shillinq is missing.
	 *
	 * @return void
	 */
	public function testAvailabilityGivesAReasonOnlyWhenShillinqIsMissing(): void {
		$this->assertSame(expected: ['shillinq' => true, 'reason' => ''], actual: $this->service()->availability());

		$this->reader->available = false;
		$this->assertSame(
			expected: ['shillinq' => false, 'reason' => 'shillinq_not_installed'],
			actual: $this->service()->availability()
		);
	}//end testAvailabilityGivesAReasonOnlyWhenShillinqIsMissing()

	/**
	 * A stored property is not a signal, and an entity with no client and no
	 * id of its own has nothing to read: both answer null, never a value.
	 *
	 * @return void
	 */
	public function testANonSignalFieldOrAnEntityWithoutAClientResolvesToNothing(): void {
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'overdue', 'amount' => 10.0, 'invoiceDate' => '2026-08-01', 'lines' => []],
		];
		$service = $this->service();

		$this->assertNull(actual: $service->valueFor(field: 'email', entity: ['uuid' => 'client-1']));
		$this->assertNull(actual: $service->valueFor(field: SegmentSignalService::FIELD_DUNNING, entity: ['name' => 'nobody']));
		$this->assertFalse(condition: $service->resolves(field: SegmentSignalService::FIELD_DUNNING, entity: ['client' => '  ']));
		$this->assertTrue(condition: $service->resolves(field: SegmentSignalService::FIELD_DUNNING, entity: ['uuid' => 'client-1']));
	}//end testANonSignalFieldOrAnEntityWithoutAClientResolvesToNothing()

	/**
	 * A contact whose client is an embedded object resolves through that
	 * object's id; a scalar client reference is trimmed.
	 *
	 * @return void
	 */
	public function testTheClientIsReadFromAnEmbeddedObjectOrATrimmedReference(): void {
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'disputed', 'amount' => 10.0, 'invoiceDate' => '2026-08-01', 'lines' => []],
		];
		$service = $this->service();

		$this->assertSame(
			expected: 'disputed',
			actual: $service->valueFor(field: SegmentSignalService::FIELD_DUNNING, entity: ['client' => ['uuid' => 'client-1']])
		);
		$this->assertSame(
			expected: 'disputed',
			actual: $service->valueFor(field: SegmentSignalService::FIELD_DUNNING, entity: ['client' => ' client-1 '])
		);
		$this->assertNull(
			actual: $service->valueFor(field: SegmentSignalService::FIELD_DUNNING, entity: ['client' => ['name' => 'no id']])
		);
	}//end testTheClientIsReadFromAnEmbeddedObjectOrATrimmedReference()

	/**
	 * The consent gate gets no answer for a blank or unknown contact, nor
	 * for a customer whose dunning state is unknown.
	 *
	 * @return void
	 */
	public function testDunningStateForContactIsNullWithoutAnAnswer(): void {
		$service = $this->service();

		$this->assertNull(actual: $service->dunningStateForContact(contactId: '   '));
		$this->assertNull(actual: $service->dunningStateForContact(contactId: 'contact-unknown'));
		$this->assertNull(actual: $service->dunningStateForContact(contactId: 'contact-2'));
		$this->assertNull(actual: $service->dunningStateForContact(contactId: 'contact-1'));
	}//end testDunningStateForContactIsNullWithoutAnAnswer()

	/**
	 * Answers are memoised per client until forget() clears them.
	 *
	 * @return void
	 */
	public function testSignalsAreMemoisedUntilForgotten(): void {
		$service = $this->service();
		$this->reader->invoices['cust-1'] = [
			['lifecycleState' => 'paid', 'amount' => 10.0, 'invoiceDate' => '2026-08-01', 'lines' => []],
		];
		$this->assertSame(expected: 'current', actual: $service->signalsFor(clientId: 'client-1')[SegmentSignalService::FIELD_DUNNING]);

		$this->reader->invoices['cust-1'][] = ['lifecycleState' => 'written-off', 'amount' => 5.0, 'invoiceDate' => '2026-08-02', 'lines' => []];
		$this->assertSame(expected: 'current', actual: $service->signalsFor(clientId: 'client-1')[SegmentSignalService::FIELD_DUNNING]);

		$service->forget();
		$this->assertSame(expected: 'written-off', actual: $service->signalsFor(clientId: 'client-1')[SegmentSignalService::FIELD_DUNNING]);
	}//end testSignalsAreMemoisedUntilForgotten()

	/**
	 * A contract end that is blank or not a date is no renewal at all.
	 *
	 * @return void
	 */
	public function testAnUndatedContractIsNoRenewal(): void {
		$this->store->save('salesContract', ['clientRef' => 'client-1', 'endDate' => '']);
		$this->store->save('salesContract', ['clientRef' => 'client-1', 'endDate' => 'someday']);
		$this->store->save('salesContract', ['clientRef' => 'client-1', 'endDate' => 20261231]);

		$this->assertNull(actual: $this->service()->signalsFor(clientId: 'client-1')[SegmentSignalService::FIELD_RENEWAL_DAYS]);
	}//end testAnUndatedContractIsNoRenewal()

	/**
	 * A lead without a status counts as open, the longest wait wins, and an
	 * open lead without a stage date is skipped.
	 *
	 * @return void
	 */
	public function testALeadWithoutAStatusIsOpenAndTheLongestWaitWins(): void {
		$this->store->save('lead', ['client' => 'client-1', 'status' => '', 'stageEnteredAt' => '2026-08-26']);
		$this->store->save('lead', ['client' => 'client-1', 'status' => ' Negotiation ', 'stageEnteredAt' => '2026-07-07T10:00:00']);
		$this->store->save('lead', ['client' => 'client-1', 'status' => 'open']);

		$this->assertSame(expected: 60, actual: $this->service()->signalsFor(clientId: 'client-1')[SegmentSignalService::FIELD_STALLED_DAYS]);
	}//end testALeadWithoutAStatusIsOpenAndTheLongestWaitWins()

	/**
	 * A catalogue entry with a blank name classifies nothing; the named one still does.
	 *
	 * @return void
	 */
	public function testABlankCatalogueNameIsIgnored(): void {
		$this->reader->invoices['cust-1'] = [
			[
				'lifecycleState' => 'paid',
				'amount' => 10.0,
				'invoiceDate' => '2026-08-01',
				'lines' => [['itemName' => '   '], ['itemName' => 'ADVIESUUR']],
			],
		];

		$this->assertSame(
			expected: ['adviesuur'],
			actual: $this->service()->signalsFor(clientId: 'client-1')[SegmentSignalService::FIELD_SERVICES]
		);
	}//end testABlankCatalogueNameIsIgnored()

	/**
	 * A fresh service over the current store and reader.
	 *
	 * @return SegmentSignalService The service.
	 */
	private function service(): SegmentSignalService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $default
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $default
		);

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime(self::NOW));

		return new SegmentSignalService(
			store: $this->store,
			bookkeeping: new BookkeepingSignals(invoices: $this->reader, appConfig: $appConfig, time: $time),
			time: $time,
		);
	}//end service()
}//end class
