<?php

/**
 * Unit tests for PortalInvoiceService — per-customer scoping + IDOR + pagination.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalInvoiceService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the invoice read facade.
 */
class PortalInvoiceServiceTest extends TestCase {
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
	 * The facade under test.
	 *
	 * @var PortalInvoiceService
	 */
	private PortalInvoiceService $service;

	/**
	 * Set up the facade over fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->portalRepo = new FakePortalObjectRepository();
		$this->reader = new FakeMainRegisterReader();

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$audit = $this->createMock(\OCA\Pipelinq\Service\Portal\PortalAuditService::class);

		$delegations = new PortalDelegationService($this->portalRepo, $audit, $time);
		$scope = new PortalScopeResolver($this->portalRepo, $delegations);
		$this->service = new PortalInvoiceService($this->reader, $scope);
	}//end setUp()

	/**
	 * A customer only sees invoices of their own linked client.
	 *
	 * @return void
	 */
	public function testOnlyOwnInvoicesAreReturned(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);

		$this->reader->seed('posTransaction', 'inv-1', ['client' => 'org-a', 'total' => 100, 'confirmedAt' => '2026-05-01T00:00:00Z']);
		$this->reader->seed('posTransaction', 'inv-2', ['client' => 'org-a', 'total' => 200, 'confirmedAt' => '2026-05-02T00:00:00Z']);
		// Another customer's invoice — must never appear.
		$this->reader->seed('posTransaction', 'inv-x', ['client' => 'org-x', 'total' => 999, 'confirmedAt' => '2026-05-03T00:00:00Z']);

		$page = $this->service->getForAccount($account);
		$this->assertSame(2, $page['total']);
		$ids = array_column($page['items'], 'id');
		$this->assertContains('inv-1', $ids);
		$this->assertContains('inv-2', $ids);
		$this->assertNotContains('inv-x', $ids);
	}//end testOnlyOwnInvoicesAreReturned()

	/**
	 * getOneForAccount returns null for another customer's invoice id (IDOR → 404).
	 *
	 * @return void
	 */
	public function testCrossCustomerInvoiceIdReturnsNull(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed('posTransaction', 'inv-x', ['client' => 'org-x', 'total' => 999]);

		$this->assertNull($this->service->getOneForAccount($account, 'inv-x'));
	}//end testCrossCustomerInvoiceIdReturnsNull()

	/**
	 * A customer can fetch their own invoice by id.
	 *
	 * @return void
	 */
	public function testOwnInvoiceByIdReturned(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed('posTransaction', 'inv-1', ['client' => 'org-a', 'total' => 100, 'invoiceNumber' => 'INV-1']);

		$invoice = $this->service->getOneForAccount($account, 'inv-1');
		$this->assertNotNull($invoice);
		$this->assertSame('INV-1', $invoice['invoiceNumber']);
		$this->assertNull($invoice['delegatedFrom']);
	}//end testOwnInvoiceByIdReturned()

	/**
	 * Results are ordered newest-first and paginated.
	 *
	 * @return void
	 */
	public function testOrderingAndPagination(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		for ($i = 1; $i <= 25; $i++) {
			$this->reader->seed('posTransaction', 'inv-' . $i, [
				'client' => 'org-a',
				'total' => $i,
				'confirmedAt' => sprintf('2026-05-%02dT00:00:00Z', $i),
			]);
		}

		$page1 = $this->service->getForAccount($account, 1, 10);
		$this->assertSame(25, $page1['total']);
		$this->assertCount(10, $page1['items']);
		// Newest first: inv-25 has the latest date.
		$this->assertSame('inv-25', $page1['items'][0]['id']);

		$page3 = $this->service->getForAccount($account, 3, 10);
		$this->assertCount(5, $page3['items']);
	}//end testOrderingAndPagination()

	/**
	 * A delegated invoice is included and tagged with delegatedFrom.
	 *
	 * @return void
	 */
	public function testDelegatedInvoiceTagged(): void {
		$this->portalRepo->seed('crmPortalAccount', 'granter', ['linkedOrganisationId' => 'org-shared']);
		$grantee = $this->portalRepo->seed('crmPortalAccount', 'grantee', ['linkedOrganisationId' => 'org-own']);
		$this->portalRepo->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'revokedAt' => null,
		]);

		$this->reader->seed('posTransaction', 'inv-own', ['client' => 'org-own', 'total' => 10, 'confirmedAt' => '2026-05-01T00:00:00Z']);
		$this->reader->seed('posTransaction', 'inv-shared', ['client' => 'org-shared', 'total' => 20, 'confirmedAt' => '2026-05-02T00:00:00Z']);

		$page = $this->service->getForAccount($grantee);
		$this->assertSame(2, $page['total']);

		$byId = [];
		foreach ($page['items'] as $item) {
			$byId[$item['id']] = $item;
		}
		$this->assertNull($byId['inv-own']['delegatedFrom']);
		$this->assertSame('granter', $byId['inv-shared']['delegatedFrom']);
	}//end testDelegatedInvoiceTagged()
}//end class
