<?php

/**
 * Unit tests for PortalContractService — owner fields, presentation and order.
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
use OCA\Pipelinq\Service\Portal\PortalContractService;
use OCA\Pipelinq\Service\Portal\PortalDelegationService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sales-contract read facade.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalContractService
 * @uses   \OCA\Pipelinq\Service\Portal\AbstractPortalReadFacade
 * @uses   \OCA\Pipelinq\Service\Portal\MainRegisterReader
 * @uses   \OCA\Pipelinq\Service\Portal\PortalDelegationService
 * @uses   \OCA\Pipelinq\Service\Portal\PortalObjectRepository
 * @uses   \OCA\Pipelinq\Service\Portal\PortalScopeResolver
 * @uses   \OCA\Pipelinq\Service\Portal\ScopedOwner
 * @uses   \OCA\Pipelinq\Service\SettingsLoadService
 */
class PortalContractServiceTest extends TestCase {
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
	 * @var PortalContractService
	 */
	private PortalContractService $service;

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
		$this->service = new PortalContractService($this->reader, $scope);
	}//end setUp()

	/**
	 * A contract is the account's own through its contact or its client, in
	 * either a plain id or an `{id}` / `{uuid}` reference; others are left out.
	 *
	 * @return void
	 */
	public function testContractsOfTheOwnContactOrClientAreReturned(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedContactId' => 'ct-a', 'linkedOrganisationId' => 'org-a']);

		$this->reader->seed('salesContract', 'c-contact', ['contact' => 'ct-a', 'startDate' => '2026-01-01']);
		$this->reader->seed('salesContract', 'c-client', ['client' => ['id' => 'org-a'], 'startDate' => '2026-02-01']);
		$this->reader->seed('salesContract', 'c-uuid', ['client' => ['uuid' => 'org-a'], 'startDate' => '2026-03-01']);
		$this->reader->seed('salesContract', 'c-other', ['contact' => 'ct-x', 'client' => 'org-x', 'startDate' => '2026-04-01']);
		$this->reader->seed('salesContract', 'c-empty', ['contact' => '', 'client' => ['name' => 'no id']]);

		$page = $this->service->getForAccount($account);

		$this->assertSame(3, $page['total']);
		$this->assertSame(['c-uuid', 'c-client', 'c-contact'], array_column($page['items'], 'id'));
	}//end testContractsOfTheOwnContactOrClientAreReturned()

	/**
	 * A contract presents its number (falling back to the reference), dates,
	 * value and status.
	 *
	 * @return void
	 */
	public function testAContractIsPresentedWithItsTerms(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed('salesContract', 'c-1', [
			'client' => 'org-a',
			'contractNumber' => 'SC-1',
			'reference' => 'REF-1',
			'startDate' => '2026-01-01',
			'endDate' => '2026-12-31',
			'value' => 1200,
			'status' => 'active',
		]);
		$this->reader->seed('salesContract', 'c-2', ['client' => 'org-a', 'reference' => 'REF-2']);

		$this->assertSame(
			[
				'id' => 'c-1',
				'contractNumber' => 'SC-1',
				'startDate' => '2026-01-01',
				'endDate' => '2026-12-31',
				'value' => 1200,
				'status' => 'active',
				'delegatedFrom' => null,
			],
			$this->service->getOneForAccount($account, 'c-1')
		);

		$second = $this->service->getOneForAccount($account, 'c-2');
		$this->assertSame('REF-2', $second['contractNumber']);
		$this->assertNull($second['startDate']);
		$this->assertNull($second['status']);
	}//end testAContractIsPresentedWithItsTerms()

	/**
	 * Another customer's contract id and an unknown id both answer null.
	 *
	 * @return void
	 */
	public function testForeignOrUnknownContractIsNull(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		$this->reader->seed('salesContract', 'c-x', ['client' => 'org-x']);

		$this->assertNull($this->service->getOneForAccount($account, 'c-x'));
		$this->assertNull($this->service->getOneForAccount($account, 'missing'));
	}//end testForeignOrUnknownContractIsNull()

	/**
	 * A contract shared under the view-contracts scope is tagged with its
	 * grantor; a grant under another scope shares nothing.
	 *
	 * @return void
	 */
	public function testOnlyAViewContractsGrantSharesContracts(): void {
		$this->portalRepo->seed('crmPortalAccount', 'granter', ['linkedOrganisationId' => 'org-shared']);
		$this->portalRepo->seed('crmPortalAccount', 'other', ['linkedOrganisationId' => 'org-invoices']);
		$grantee = $this->portalRepo->seed('crmPortalAccount', 'grantee', ['linkedOrganisationId' => 'org-own']);
		$this->portalRepo->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-contracts'],
			'revokedAt' => null,
		]);
		$this->portalRepo->seed('portalDelegation', 'del-2', [
			'granterAccountId' => 'other',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'revokedAt' => null,
		]);

		$this->reader->seed('salesContract', 'c-shared', ['client' => 'org-shared', 'startDate' => '2026-02-01']);
		$this->reader->seed('salesContract', 'c-invoices', ['client' => 'org-invoices', 'startDate' => '2026-03-01']);

		$page = $this->service->getForAccount($grantee);

		$this->assertSame(1, $page['total']);
		$this->assertSame('c-shared', $page['items'][0]['id']);
		$this->assertSame('granter', $page['items'][0]['delegatedFrom']);
	}//end testOnlyAViewContractsGrantSharesContracts()

	/**
	 * Page and page size are clamped to at least one and at most one hundred.
	 *
	 * @return void
	 */
	public function testPagingIsClamped(): void {
		$account = $this->portalRepo->seed('crmPortalAccount', 'acc-a', ['linkedOrganisationId' => 'org-a']);
		for ($i = 1; $i <= 3; $i++) {
			$this->reader->seed('salesContract', 'c-'.$i, ['client' => 'org-a', 'startDate' => '2026-01-0'.$i]);
		}

		$low = $this->service->getForAccount($account, 0, 0);
		$this->assertSame(1, $low['page']);
		$this->assertSame(1, $low['perPage']);
		$this->assertSame(['c-3'], array_column($low['items'], 'id'));

		$high = $this->service->getForAccount($account, 1, 500);
		$this->assertSame(100, $high['perPage']);
		$this->assertCount(3, $high['items']);
	}//end testPagingIsClamped()
}//end class
