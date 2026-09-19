<?php

/**
 * Unit tests for PortalScopeResolver — the per-customer IDOR boundary.
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
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for per-customer + delegated scope resolution.
 */
class PortalScopeResolverTest extends TestCase {
	/**
	 * The fake repository.
	 *
	 * @var FakePortalObjectRepository
	 */
	private FakePortalObjectRepository $repository;

	/**
	 * The resolver under test.
	 *
	 * @var PortalScopeResolver
	 */
	private PortalScopeResolver $resolver;

	/**
	 * Set up the resolver with a delegation service over the fake repo.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = new FakePortalObjectRepository();

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$audit = $this->createMock(\OCA\Pipelinq\Service\Portal\PortalAuditService::class);

		$delegations = new PortalDelegationService($this->repository, $audit, $time);
		$this->resolver = new PortalScopeResolver($this->repository, $delegations);
	}//end setUp()

	/**
	 * Own data classifies as own (null), a stranger's as not-visible (false).
	 *
	 * @return void
	 */
	public function testOwnVisibleStrangerNot(): void {
		$account = $this->repository->seed('crmPortalAccount', 'acc-a', [
			'linkedContactId' => 'contact-a',
			'linkedOrganisationId' => 'org-a',
		]);
		$resolved = $this->resolver->resolve($account, 'view-invoices');

		$this->assertNull($this->resolver->classify($resolved, 'contact-a', null), 'own contact is own');
		$this->assertNull($this->resolver->classify($resolved, null, 'org-a'), 'own org is own');
		$this->assertFalse($this->resolver->classify($resolved, 'contact-x', 'org-x'), 'stranger is not visible');
	}//end testOwnVisibleStrangerNot()

	/**
	 * A guessed id from another customer is rejected (IDOR). The whole point.
	 *
	 * @return void
	 */
	public function testCrossCustomerIdorRejected(): void {
		$victim = $this->repository->seed('crmPortalAccount', 'victim', [
			'linkedContactId' => 'contact-victim',
			'linkedOrganisationId' => 'org-victim',
		]);
		$attacker = $this->repository->seed('crmPortalAccount', 'attacker', [
			'linkedContactId' => 'contact-attacker',
			'linkedOrganisationId' => 'org-attacker',
		]);

		$resolved = $this->resolver->resolve($attacker, 'view-invoices');

		// The attacker may NOT classify the victim's contact/org as visible.
		$this->assertFalse($this->resolver->classify($resolved, 'contact-victim', null));
		$this->assertFalse($this->resolver->classify($resolved, null, 'org-victim'));
		unset($victim);
	}//end testCrossCustomerIdorRejected()

	/**
	 * An active delegation makes the grantor's org visible, tagged delegatedFrom.
	 *
	 * @return void
	 */
	public function testActiveDelegationWidensVisibility(): void {
		$granter = $this->repository->seed('crmPortalAccount', 'granter', [
			'email' => 'boss@org.nl',
			'linkedOrganisationId' => 'org-shared',
		]);
		$grantee = $this->repository->seed('crmPortalAccount', 'grantee', [
			'email' => 'colleague@org.nl',
			'linkedOrganisationId' => 'org-own',
		]);

		$this->repository->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'validFrom' => null,
			'validUntil' => null,
			'revokedAt' => null,
		]);

		$resolved = $this->resolver->resolve($grantee, 'view-invoices');
		$this->assertSame('granter', $this->resolver->classify($resolved, null, 'org-shared'));
		unset($granter);
	}//end testActiveDelegationWidensVisibility()

	/**
	 * A delegation for a different scope does NOT widen this scope.
	 *
	 * @return void
	 */
	public function testDelegationScopeIsolation(): void {
		$this->repository->seed('crmPortalAccount', 'granter', ['linkedOrganisationId' => 'org-shared']);
		$grantee = $this->repository->seed('crmPortalAccount', 'grantee', ['linkedOrganisationId' => 'org-own']);
		$this->repository->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-contracts'],
			'revokedAt' => null,
		]);

		// Asking for view-invoices must NOT pick up the view-contracts grant.
		$resolved = $this->resolver->resolve($grantee, 'view-invoices');
		$this->assertFalse($this->resolver->classify($resolved, null, 'org-shared'));
	}//end testDelegationScopeIsolation()

	/**
	 * A revoked delegation no longer widens visibility.
	 *
	 * @return void
	 */
	public function testRevokedDelegationDoesNotWiden(): void {
		$this->repository->seed('crmPortalAccount', 'granter', ['linkedOrganisationId' => 'org-shared']);
		$grantee = $this->repository->seed('crmPortalAccount', 'grantee', ['linkedOrganisationId' => 'org-own']);
		$this->repository->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'revokedAt' => '2020-01-01T00:00:00+00:00',
		]);

		$resolved = $this->resolver->resolve($grantee, 'view-invoices');
		$this->assertFalse($this->resolver->classify($resolved, null, 'org-shared'));
	}//end testRevokedDelegationDoesNotWiden()
}//end class
