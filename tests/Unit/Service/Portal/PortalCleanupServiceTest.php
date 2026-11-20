<?php

/**
 * PortalCleanupService: every way a closed account's cleanup ends.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalCleanupService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A closed account's contact is pseudonymised unless a retention hold applies.
 */
class PortalCleanupServiceTest extends TestCase {

	/**
	 * Every way a cleanup ends: an account without a contact is left; an open
	 * invoice of the contact (by id or by a nested id or uuid) holds it; a
	 * settled or refunded one does not; a contact that is gone is detached
	 * only; otherwise name and email are hashed, the phone cleared, the
	 * account detached and the pseudonymisation audited; the run counts the
	 * pseudonymised accounts.
	 *
	 * @return void
	 */
	public function testEveryWayACleanupEnds(): void {
		$appConfig = InstalledAppConfig::wire($this->createMock(IAppConfig::class));
		$repository = new FakePortalObjectRepository($appConfig);
		$reader = new FakeMainRegisterReader($appConfig);
		$audits = [];
		$audit = $this->createMock(PortalAuditService::class);
		$audit->method('log')->willReturnCallback(
			static function (...$args) use (&$audits): void {
				$audits[] = $args;
			}
		);

		$reader->seed('contact', 'c-free', ['name' => 'Jan', 'email' => 'jan@example.test', 'phone' => '06', 'city' => 'Ede']);
		$reader->seed('contact', 'c-held', ['name' => 'Piet']);
		$reader->seed('contact', 'c-nested', ['name' => 'Kees']);
		$reader->seed('contact', 'c-uuid', ['name' => 'Anna']);
		$reader->seed('posTransaction', 'i-1', ['status' => 'open', 'client' => 'c-held']);
		$reader->seed('posTransaction', 'i-2', ['status' => 'settled', 'client' => 'c-free']);
		$reader->seed('posTransaction', 'i-3', ['status' => 'refunded', 'client' => 'c-free']);
		$reader->seed('posTransaction', 'i-4', ['client' => ['id' => 'c-nested']]);
		$reader->seed('posTransaction', 'i-5', ['status' => 'draft', 'client' => ['uuid' => 'c-uuid']]);

		$repository->seed('crmPortalAccount', 'a-none', ['status' => 'closed', 'tenantId' => 't-1', 'linkedContactId' => '']);
		$repository->seed('crmPortalAccount', 'a-held', ['status' => 'closed', 'tenantId' => 't-1', 'linkedContactId' => 'c-held']);
		$repository->seed('crmPortalAccount', 'a-nested', ['status' => 'closed', 'tenantId' => 't-1', 'linkedContactId' => 'c-nested']);
		$repository->seed('crmPortalAccount', 'a-uuid', ['status' => 'closed', 'tenantId' => 't-1', 'linkedContactId' => 'c-uuid']);
		$repository->seed('crmPortalAccount', 'a-gone', ['status' => 'closed', 'tenantId' => 't-1', 'linkedContactId' => 'c-gone']);
		$repository->seed('crmPortalAccount', 'a-free', ['status' => 'closed', 'tenantId' => 't-2', 'linkedContactId' => 'c-free']);
		$repository->seed('crmPortalAccount', 'a-open', ['status' => 'active', 'tenantId' => 't-1', 'linkedContactId' => 'c-free']);

		$this->assertSame(1, (new PortalCleanupService($repository, $reader, $audit))->run());

		$this->assertSame(
			['name' => '#' . substr(hash('sha256', 'Jan'), 0, 16), 'email' => '#' . substr(hash('sha256', 'jan@example.test'), 0, 16), 'phone' => null, 'city' => 'Ede', '@self' => ['id' => 'c-free']],
			$reader->find('contact', 'c-free')
		);
		foreach (['c-held' => 'Piet', 'c-nested' => 'Kees', 'c-uuid' => 'Anna'] as $id => $name) {
			$this->assertSame($name, $reader->find('contact', $id)['name'], $id);
		}

		$linked = [];
		foreach (['a-none', 'a-held', 'a-nested', 'a-uuid', 'a-gone', 'a-free', 'a-open'] as $id) {
			$linked[$id] = $repository->find('crmPortalAccount', $id)['linkedContactId'];
		}

		$this->assertSame(['a-none' => '', 'a-held' => 'c-held', 'a-nested' => 'c-nested', 'a-uuid' => 'c-uuid', 'a-gone' => null, 'a-free' => null, 'a-open' => 'c-free'], $linked);
		$this->assertSame([['a-free', 't-2', 'account-pseudonymised', 'success', ['targetObjectType' => 'contact', 'targetObjectId' => 'c-free']]], $audits);
	}//end testEveryWayACleanupEnds()
}//end class
