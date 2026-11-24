<?php

/**
 * Unit tests for the portal's invoice sources: no carts, and the bookkeeping
 * invoices of the client next to its POS invoices.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/specify-capability-rows/specs/customer-portal/spec.md#requirement-a-client-sees-their-own-invoices-contracts-orders-and-quotes
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalLedgerInvoices;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCA\Pipelinq\Tests\Unit\Service\Marketing\FakeShillinqInvoiceReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the invoice facade's two sources.
 */
class PortalInvoiceSourcesTest extends TestCase {
	/**
	 * The fake portal repository.
	 *
	 * @var FakePortalObjectRepository
	 */
	private FakePortalObjectRepository $portalRepo;

	/**
	 * The fake main-register reader.
	 *
	 * @var FakeMainRegisterReader
	 */
	private FakeMainRegisterReader $reader;

	/**
	 * The fake bookkeeping reader.
	 *
	 * @var FakeShillinqInvoiceReader
	 */
	private FakeShillinqInvoiceReader $ledger;

	/**
	 * The facade under test.
	 *
	 * @var PortalInvoiceService
	 */
	private PortalInvoiceService $service;

	/**
	 * Set up the facade over fakes, with client org-a booked as customer cust-a.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->portalRepo = new FakePortalObjectRepository(
			appConfig: InstalledAppConfig::wire(config: $this->createMock(originalClassName: IAppConfig::class))
		);
		$this->reader = new FakeMainRegisterReader(
			appConfig: InstalledAppConfig::wire(config: $this->createMock(originalClassName: IAppConfig::class))
		);
		$this->ledger = new FakeShillinqInvoiceReader();

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$audit = $this->createMock(originalClassName: PortalAuditService::class);

		$delegations = new PortalDelegationService(repository: $this->portalRepo, audit: $audit, time: $time);
		$this->service = new PortalInvoiceService(
			reader: $this->reader,
			scope: new PortalScopeResolver(repository: $this->portalRepo, delegations: $delegations),
			ledger: new PortalLedgerInvoices(reader: $this->reader, ledger: $this->ledger)
		);

		$this->reader->seed('client', 'org-a', ['name' => 'Bakkerij A', 'shillinqOrganisationRef' => 'cust-a']);
		$this->reader->seed('client', 'org-x', ['name' => 'Someone else', 'shillinqOrganisationRef' => 'cust-x']);
	}//end setUp()

	/**
	 * A bookkeeping invoice as ShillinqInvoiceReader::invoicesFor() answers it.
	 *
	 * @param string $id     The invoice id.
	 * @param string $date   The invoice date.
	 * @param string $state  The lifecycle state.
	 * @param float  $amount The gross amount.
	 *
	 * @return array<string, mixed> The invoice.
	 */
	private static function ledgerInvoice(string $id, string $date, string $state, float $amount = 10.0): array {
		return [
			'id' => $id,
			'invoiceNumber' => 'F-' . $id,
			'invoiceDate' => $date,
			'amount' => $amount,
			'currency' => 'EUR',
			'lifecycleState' => $state,
		];
	}//end ledgerInvoice()

	/**
	 * A draft or parked cart is not listed and cannot be fetched; a confirmed one is.
	 *
	 * @return void
	 */
	public function testADraftOrParkedCartIsNotAnInvoice(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed('posTransaction', 'cart-draft', ['client' => 'org-a', 'status' => 'draft', 'total' => 5]);
		$this->reader->seed('posTransaction', 'cart-parked', ['client' => 'org-a', 'status' => 'Parked', 'total' => 6]);
		$this->reader->seed(
			'posTransaction',
			'inv-1',
			['client' => 'org-a', 'status' => 'confirmed', 'total' => 7, 'confirmedAt' => '2026-05-01T00:00:00Z']
		);

		$page = $this->service->getForAccount(account: $account);

		self::assertSame(expected: ['inv-1'], actual: array_column($page['items'], 'id'));
		self::assertSame(expected: 'pos', actual: $page['items'][0]['source']);
		self::assertNull(actual: $this->service->getOneForAccount(account: $account, id: 'cart-draft'));
		self::assertNull(actual: $this->service->getOneForAccount(account: $account, id: 'cart-parked'));
		self::assertNotNull(actual: $this->service->getOneForAccount(account: $account, id: 'inv-1'));
	}//end testADraftOrParkedCartIsNotAnInvoice()

	/**
	 * The client's bookkeeping invoices are listed next to its POS invoices,
	 * newest first, without drafts and without another customer's invoices.
	 *
	 * @return void
	 */
	public function testTheBookkeepingInvoicesOfTheClientAreListed(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed(
			'posTransaction',
			'pos-1',
			['client' => 'org-a', 'status' => 'settled', 'total' => 12.5, 'confirmedAt' => '2026-05-02T09:00:00Z']
		);
		$this->ledger->invoices = [
			'cust-a' => [
				self::ledgerInvoice(id: 'ar-1', date: '2026-05-01', state: 'sent'),
				self::ledgerInvoice(id: 'ar-2', date: '2026-05-03', state: 'paid', amount: 60.5),
				self::ledgerInvoice(id: 'ar-draft', date: '2026-05-04', state: 'draft'),
			],
			'cust-x' => [self::ledgerInvoice(id: 'ar-x', date: '2026-05-05', state: 'sent')],
		];

		$page = $this->service->getForAccount(account: $account);

		self::assertSame(expected: 3, actual: $page['total']);
		self::assertSame(expected: ['ar-2', 'pos-1', 'ar-1'], actual: array_column($page['items'], 'id'));
		self::assertSame(
			expected: [
				'id' => 'ar-2',
				'invoiceNumber' => 'F-ar-2',
				'date' => '2026-05-03',
				'amount' => 60.5,
				'currency' => 'EUR',
				'status' => 'paid',
				'source' => 'ledger',
				'delegatedFrom' => null,
			],
			actual: $page['items'][0]
		);
		self::assertSame(expected: 'F-ar-2', actual: $this->service->getOneForAccount(account: $account, id: 'ar-2')['invoiceNumber']);
		self::assertNull(actual: $this->service->getOneForAccount(account: $account, id: 'ar-x'));
		self::assertNull(actual: $this->service->getOneForAccount(account: $account, id: 'ar-draft'));
	}//end testTheBookkeepingInvoicesOfTheClientAreListed()

	/**
	 * A delegated client's bookkeeping invoice is tagged with its grantor.
	 *
	 * @return void
	 */
	public function testADelegatedBookkeepingInvoiceNamesItsGrantor(): void {
		$this->portalRepo->seed('crmPortalAccount', 'granter', ['linkedOrganisationId' => 'org-a']);
		$grantee = $this->portalRepo->seed('crmPortalAccount', 'bookkeeper', ['linkedOrganisationId' => 'org-own']);
		$this->portalRepo->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'granteeAccountId' => 'bookkeeper',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'revokedAt' => null,
		]);
		$this->ledger->invoices = ['cust-a' => [self::ledgerInvoice(id: 'ar-1', date: '2026-05-01', state: 'sent')]];

		$page = $this->service->getForAccount(account: $grantee);

		self::assertSame(expected: ['ar-1'], actual: array_column($page['items'], 'id'));
		self::assertSame(expected: 'granter', actual: $page['items'][0]['delegatedFrom']);
		self::assertSame(expected: 'granter', actual: $this->service->getOneForAccount(account: $grantee, id: 'ar-1')['delegatedFrom']);
	}//end testADelegatedBookkeepingInvoiceNamesItsGrantor()

	/**
	 * Without the bookkeeping app, or for a client without a customer
	 * reference, only the POS invoices are listed.
	 *
	 * @return void
	 */
	public function testWithoutBookkeepingOnlyPosInvoicesAreListed(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed(
			'posTransaction',
			'pos-1',
			['client' => 'org-a', 'status' => 'confirmed', 'total' => 3, 'confirmedAt' => '2026-05-02T09:00:00Z']
		);
		$this->ledger->invoices = ['cust-a' => [self::ledgerInvoice(id: 'ar-1', date: '2026-05-01', state: 'sent')]];
		$this->ledger->available = false;

		self::assertSame(expected: ['pos-1'], actual: array_column($this->service->getForAccount(account: $account)['items'], 'id'));
		self::assertNull(actual: $this->service->getOneForAccount(account: $account, id: 'ar-1'));

		$this->ledger->available = true;
		$this->reader->seed('client', 'org-a', ['name' => 'Bakkerij A']);

		self::assertSame(expected: ['pos-1'], actual: array_column($this->service->getForAccount(account: $account)['items'], 'id'));
	}//end testWithoutBookkeepingOnlyPosInvoicesAreListed()
}//end class
