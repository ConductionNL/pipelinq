<?php

/**
 * Unit tests for PortalOrderService — client scoping, presentation and order.
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

use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalOrderService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the order read facade over POS transactions.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalOrderService
 * @covers \OCA\Pipelinq\Service\Portal\AbstractPosTransactionFacade
 * @uses   \OCA\Pipelinq\Service\Portal\AbstractPortalReadFacade
 * @uses   \OCA\Pipelinq\Service\Portal\MainRegisterReader
 * @uses   \OCA\Pipelinq\Service\Portal\PortalDelegationService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalObjectRepository
 * @uses   \OCA\Pipelinq\Service\Portal\PortalScopeResolver
 * @uses   \OCA\Pipelinq\Service\Portal\ScopedOwner
 * @uses   \OCA\Pipelinq\Service\SettingsLoadService
 */
class PortalOrderServiceTest extends TestCase {
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
	 * @var PortalOrderService
	 */
	private PortalOrderService $service;

	/**
	 * Set up the facade over fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->portalRepo = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
		$this->reader = new FakeMainRegisterReader(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);

		$delegations = new PortalDelegationService($this->portalRepo, $this->createMock(PortalAuditService::class), $time);
		$scope = new PortalScopeResolver($this->portalRepo, $delegations);
		$this->service = new PortalOrderService($this->reader, $scope);
	}//end setUp()

	/**
	 * Orders belong to the account through the client only: a matching
	 * contact on the transaction does not make it the account's.
	 *
	 * @return void
	 */
	public function testOrdersAreScopedByClientOnly(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'ct-a', 'linkedOrganisationId' => 'org-a']);

		$this->reader->seed('posTransaction', 'o-own', ['client' => 'org-a', 'confirmedAt' => '2026-05-01T00:00:00Z']);
		$this->reader->seed('posTransaction', 'o-contact', ['contact' => 'ct-a', 'client' => 'org-x', 'confirmedAt' => '2026-05-02T00:00:00Z']);

		$page = $this->service->getForAccount($account);

		$this->assertSame(1, $page['total']);
		$this->assertSame('o-own', $page['items'][0]['id']);
		$this->assertNull($this->service->getOneForAccount($account, 'o-contact'));
	}//end testOrdersAreScopedByClientOnly()

	/**
	 * An order presents its reference (else the invoice number), its
	 * confirmation date (else the parked date), total and status.
	 *
	 * @return void
	 */
	public function testAnOrderIsPresentedWithFallbacks(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed('posTransaction', 'o-1', [
			'client' => 'org-a',
			'reference' => 'ORD-1',
			'invoiceNumber' => 'INV-1',
			'confirmedAt' => '2026-05-01T00:00:00Z',
			'parkedAt' => '2026-04-30T00:00:00Z',
			'total' => 150,
			'status' => 'confirmed',
		]);
		$this->reader->seed('posTransaction', 'o-2', ['client' => 'org-a', 'invoiceNumber' => 'INV-2', 'parkedAt' => '2026-04-01T00:00:00Z']);

		$this->assertSame(
			[
				'id' => 'o-1',
				'orderNumber' => 'ORD-1',
				'date' => '2026-05-01T00:00:00Z',
				'total' => 150,
				'status' => 'confirmed',
				'delegatedFrom' => null,
			],
			$this->service->getOneForAccount($account, 'o-1')
		);

		$second = $this->service->getOneForAccount($account, 'o-2');
		$this->assertSame('INV-2', $second['orderNumber']);
		$this->assertSame('2026-04-01T00:00:00Z', $second['date']);
		$this->assertNull($second['total']);
	}//end testAnOrderIsPresentedWithFallbacks()

	/**
	 * Orders sort newest first by confirmation, else parked date; undated last.
	 *
	 * @return void
	 */
	public function testOrdersSortByConfirmedThenParkedDate(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed('posTransaction', 'o-undated', ['client' => 'org-a']);
		$this->reader->seed('posTransaction', 'o-parked', ['client' => 'org-a', 'parkedAt' => '2026-06-01T00:00:00Z']);
		$this->reader->seed('posTransaction', 'o-confirmed', ['client' => 'org-a', 'confirmedAt' => '2026-05-01T00:00:00Z', 'parkedAt' => '2026-07-01T00:00:00Z']);

		$page = $this->service->getForAccount($account);

		$this->assertSame(['o-parked', 'o-confirmed', 'o-undated'], array_column($page['items'], 'id'));
	}//end testOrdersSortByConfirmedThenParkedDate()

	/**
	 * Orders are shared under the view-invoices scope, tagged with the grantor.
	 *
	 * @return void
	 */
	public function testAViewInvoicesGrantSharesOrders(): void {
		$this->portalRepo->seed('crmPortalAccount', 'granter', ['linkedOrganisationId' => 'org-shared']);
		$grantee = $this->portalRepo->seed('crmPortalAccount', 'grantee', ['linkedOrganisationId' => 'org-own']);
		$this->portalRepo->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'revokedAt' => null,
		]);
		$this->reader->seed('posTransaction', 'o-shared', ['client' => 'org-shared']);

		$order = $this->service->getOneForAccount($grantee, 'o-shared');

		$this->assertNotNull($order);
		$this->assertSame('granter', $order['delegatedFrom']);
	}//end testAViewInvoicesGrantSharesOrders()
}//end class
