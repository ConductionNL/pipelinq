<?php

/**
 * Unit tests for PortalDelegationService.
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
use OCA\Pipelinq\Service\Portal\PortalException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for B2B delegation grant/revoke + scope hygiene.
 */
class PortalDelegationServiceTest extends TestCase {
	/**
	 * The fake repository.
	 *
	 * @var FakePortalObjectRepository
	 */
	private FakePortalObjectRepository $repository;

	/**
	 * The service under test.
	 *
	 * @var PortalDelegationService
	 */
	private PortalDelegationService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->repository = new FakePortalObjectRepository();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000);
		$time->method('getDateTime')->willReturn((new \DateTime())->setTimestamp(1000));
		$audit = $this->createMock(PortalAuditService::class);

		$this->service = new PortalDelegationService($this->repository, $audit, $time);
	}//end setUp()

	/**
	 * A grant only persists recognised scopes (unknown scopes are dropped).
	 *
	 * @return void
	 */
	public function testGrantSanitisesScopes(): void {
		$this->repository->seed('portalAccount', 'granter', ['email' => 'boss@org.nl', 'tenantId' => 'tenant-a']);

		$delegation = $this->service->grant(
			'granter',
			'tenant-a',
			'colleague@org.nl',
			['view-invoices', 'evil-scope', 'submit-requests'],
			null
		);

		$this->assertSame(['view-invoices', 'submit-requests'], $delegation['scopes']);
	}//end testGrantSanitisesScopes()

	/**
	 * Granting access to your own email is refused.
	 *
	 * @return void
	 */
	public function testCannotGrantToSelf(): void {
		$this->repository->seed('portalAccount', 'granter', ['email' => 'boss@org.nl', 'tenantId' => 'tenant-a']);

		try {
			$this->service->grant('granter', 'tenant-a', 'boss@org.nl', ['view-invoices'], null);
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame('cannotDelegateToSelf', $e->getErrorCode());
		}
	}//end testCannotGrantToSelf()

	/**
	 * Revoking another account's delegation is a 404 (no existence leak).
	 *
	 * @return void
	 */
	public function testCannotRevokeOthersDelegation(): void {
		$this->repository->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'someone-else',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
		]);

		try {
			$this->service->revoke('del-1', 'attacker', 'tenant-a');
			$this->fail('Expected PortalException');
		} catch (PortalException $e) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $e->getStatus());
		}
	}//end testCannotRevokeOthersDelegation()

	/**
	 * The granter can revoke their own delegation (soft delete sets revokedAt).
	 *
	 * @return void
	 */
	public function testGranterCanRevoke(): void {
		$this->repository->seed('portalDelegation', 'del-1', [
			'granterAccountId' => 'granter',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'revokedAt' => null,
		]);

		$this->service->revoke('del-1', 'granter', 'tenant-a');
		$stored = $this->repository->find('portalDelegation', 'del-1');
		$this->assertNotNull($stored['revokedAt']);
	}//end testGranterCanRevoke()

	/**
	 * getActiveScopes returns only valid, unrevoked grants for the grantee.
	 *
	 * @return void
	 */
	public function testGetActiveScopesFiltersInactive(): void {
		$this->repository->seed('portalDelegation', 'active', [
			'granterAccountId' => 'g1',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-invoices'],
			'revokedAt' => null,
		]);
		$this->repository->seed('portalDelegation', 'revoked', [
			'granterAccountId' => 'g2',
			'granteeAccountId' => 'grantee',
			'tenantId' => 'tenant-a',
			'scopes' => ['view-contracts'],
			'revokedAt' => '2020-01-01T00:00:00+00:00',
		]);

		$active = $this->service->getActiveScopes('grantee');
		$this->assertCount(1, $active);
		$this->assertSame('g1', $active[0]['grantorAccountId']);
	}//end testGetActiveScopesFiltersInactive()
}//end class
