<?php

/**
 * Golden test for the order and invoice facades: own, delegated and refused rows.
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
use OCA\Pipelinq\Service\Portal\PortalOrderService;
use OCA\Pipelinq\Service\Portal\PortalScopeResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Both facades read posTransaction rows of the account's own client and of
 * clients that delegated `view-invoices`, and refuse every other row. This pins
 * which ids each facade returns, list and by id, own and delegated.
 */
class PortalPosFacadeGoldenTest extends TestCase {
	/**
	 * Run the same scenario through both facades.
	 *
	 * @return void
	 */
	public function testOwnDelegatedAndRefusedRows(): void {
		foreach ([PortalOrderService::class, PortalInvoiceService::class] as $class) {
			$portalRepo = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
			$reader = new FakeMainRegisterReader(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
			$time = $this->createMock(ITimeFactory::class);
			$time->method('getTime')->willReturn(1000);
			$audit = $this->createMock(\OCA\Pipelinq\Service\Portal\PortalAuditService::class);
			$delegations = new PortalDelegationService($portalRepo, $audit, $time);
			$service = new $class($reader, new PortalScopeResolver($portalRepo, $delegations));

			$me = $portalRepo->seed('crmPortalAccount', 'acc-me', ['linkedOrganisationId' => 'org-me', 'linkedContactId' => 'c-me']);
			$portalRepo->seed('crmPortalAccount', 'acc-boss', ['linkedOrganisationId' => 'org-boss']);
			$portalRepo->seed('crmPortalAccount', 'acc-other', ['linkedOrganisationId' => 'org-other']);
			$portalRepo->seed('portalDelegation', 'd-1', ['granterAccountId' => 'acc-boss', 'granteeAccountId' => 'acc-me', 'tenantId' => 't', 'scopes' => ['view-invoices'], 'validFrom' => null, 'validUntil' => null, 'revokedAt' => null]);
			$portalRepo->seed('portalDelegation', 'd-2', ['granterAccountId' => 'acc-other', 'granteeAccountId' => 'acc-me', 'tenantId' => 't', 'scopes' => ['view-contracts'], 'validFrom' => null, 'validUntil' => null, 'revokedAt' => null]);

			$reader->seed('posTransaction', 'tx-own', ['client' => 'org-me', 'total' => 1, 'confirmedAt' => '2026-05-01T00:00:00Z']);
			$reader->seed('posTransaction', 'tx-boss', ['client' => ['id' => 'org-boss'], 'total' => 2, 'confirmedAt' => '2026-05-02T00:00:00Z']);
			$reader->seed('posTransaction', 'tx-other', ['client' => 'org-other', 'total' => 3, 'confirmedAt' => '2026-05-03T00:00:00Z']);
			$reader->seed('posTransaction', 'tx-contact', ['contact' => 'c-me', 'total' => 4, 'confirmedAt' => '2026-05-04T00:00:00Z']);
			$reader->seed('posTransaction', 'tx-none', ['total' => 5]);

			$page = $service->getForAccount($me);
			$rows = [];
			foreach ($page['items'] as $item) {
				$rows[$item['id']] = $item['delegatedFrom'];
			}

			ksort($rows);
			$this->assertSame(['tx-boss' => 'acc-boss', 'tx-own' => null], $rows, $class);
			$this->assertSame(2, $page['total'], $class);
			$this->assertSame('acc-boss', $service->getOneForAccount($me, 'tx-boss')['delegatedFrom'], $class);
			$this->assertNull($service->getOneForAccount($me, 'tx-own')['delegatedFrom'], $class);
			foreach (['tx-other', 'tx-contact', 'tx-none', 'tx-missing'] as $refused) {
				$this->assertNull($service->getOneForAccount($me, $refused), $class . ' ' . $refused);
			}
		}//end foreach
	}//end testOwnDelegatedAndRefusedRows()
}//end class
