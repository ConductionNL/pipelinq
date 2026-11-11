<?php

/**
 * PortalProfileService: every way a profile update and an email change end.
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

use DateTime;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCA\Pipelinq\Service\Portal\PortalProfileService;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Only editable fields change, each change is audited, and a new email
 * address takes effect only through its own valid, unexpired token.
 */
class PortalProfileServiceTest extends TestCase {

	/** @var array<int, mixed> */
	private array $calls = [];

	private FakePortalObjectRepository $repository;

	/**
	 * The service over a fake repository, a real token service at a fixed
	 * clock with a fixed random source, and recording mail and audit doubles.
	 *
	 * @return PortalProfileService
	 */
	private function service(): PortalProfileService {
		$this->repository = new FakePortalObjectRepository(InstalledAppConfig::wire($this->createMock(IAppConfig::class)));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-05-01T12:00:00+00:00'));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('fixed-random-bytes');
		$tokens = new PortalTokenService($random, $time);

		$mail = $this->createMock(PortalMailService::class);
		$mail->method('sendTokenLink')->willReturnCallback(
			function (...$args): bool {
				$this->calls[] = ['mail', $args];
				return true;
			}
		);
		$audit = $this->createMock(PortalAuditService::class);
		$audit->method('log')->willReturnCallback(
			function (...$args): void {
				$this->calls[] = ['audit', $args];
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:' . $text);

		return new PortalProfileService($this->repository, $tokens, $mail, $audit, $l10n);
	}//end service()

	/**
	 * Every way an update ends: only editable, changed fields are taken and
	 * each is audited in field order; an unchanged or unknown field is not;
	 * an email change is held pending with a token, mailed and audited; a
	 * blank or identical (any case) email changes nothing; the saved
	 * account is presented.
	 *
	 * @return void
	 */
	public function testEveryWayAnUpdateEnds(): void {
		$service = $this->service();
		$account = $this->repository->seed('crmPortalAccount', 'acc-1', ['email' => 'jan@example.test', 'tenantId' => 't-1', 'displayName' => 'Jan', 'phone' => '1', 'locale' => 'nl']);

		$profile = $service->update(
			account: $account,
			tenantId: 't-1',
			changes: ['phone' => '2', 'locale' => 'nl', 'displayName' => 'Jan B', 'status' => 'closed', 'jobTitle' => null, 'email' => ' Nieuw@Example.test ']
		);

		$token = rtrim(strtr(base64_encode('fixed-random-bytes'), '+/', '-_'), '=');
		$this->assertSame(
			[
				['audit', ['acc-1', 't-1', 'profile-update', 'success', ['fieldName' => 'displayName', 'previousValue' => 'Jan', 'newValue' => 'Jan B']]],
				['audit', ['acc-1', 't-1', 'profile-update', 'success', ['fieldName' => 'phone', 'previousValue' => '1', 'newValue' => '2']]],
				['mail', ['nieuw@example.test', '/index.php/apps/pipelinq/portal/verify-email', $token, 'T:Confirm your new email address', 'T:Click the link below to confirm this email address for your portal account.']],
				['audit', ['acc-1', 't-1', 'profile-update', 'pending-verification', ['fieldName' => 'email', 'newValue' => 'nieuw@example.test']]],
			],
			$this->calls
		);
		$this->assertSame(
			['id' => 'acc-1', 'email' => 'jan@example.test', 'pendingEmail' => 'nieuw@example.test', 'displayName' => 'Jan B', 'phone' => '2', 'address' => null, 'locale' => 'nl', 'jobTitle' => null, 'accountType' => 'b2c'],
			$profile
		);
		$stored = $this->repository->find('crmPortalAccount', 'acc-1');
		$this->assertSame([hash('sha256', $token), '2026-05-01T12:30:00+00:00'], [$stored['emailVerifyTokenHash'], $stored['emailVerifyExpiresAt']]);
		$this->assertArrayNotHasKey('status', $stored);

		$this->calls = [];
		$service->update(account: $this->repository->find('crmPortalAccount', 'acc-1'), tenantId: 't-1', changes: ['email' => ' JAN@example.test ']);
		$service->update(account: $this->repository->find('crmPortalAccount', 'acc-1'), tenantId: 't-1', changes: ['email' => ' ']);
		$this->assertSame([], $this->calls);
	}//end testEveryWayAnUpdateEnds()

	/**
	 * Every way an email verification ends: the tenant's valid, unexpired
	 * token moves the pending address into place, clears the token and is
	 * audited; a wrong token, another tenant, an expired token and an empty
	 * token are refused and change nothing.
	 *
	 * @return void
	 */
	public function testEveryWayAVerificationEnds(): void {
		$cases = [
			['tok-other', 't-1', '2026-05-01T12:30:00+00:00'],
			['tok', 't-2', '2026-05-01T12:30:00+00:00'],
			['tok', 't-1', '2026-05-01T11:00:00+00:00'],
			['', 't-1', '2026-05-01T12:30:00+00:00'],
		];
		foreach ($cases as [$token, $tenant, $expires]) {
			$service = $this->service();
			$seeded = $this->repository->seed('crmPortalAccount', 'acc-1', ['email' => 'jan@example.test', 'tenantId' => 't-1', 'pendingEmail' => 'Nieuw@Example.test', 'emailVerifyTokenHash' => hash('sha256', 'tok'), 'emailVerifyExpiresAt' => $expires]);
			$this->calls = [];
			$this->assertFalse($service->verifyEmail(token: $token, tenantId: $tenant));
			$this->assertSame($seeded, $this->repository->find('crmPortalAccount', 'acc-1'));
			$this->assertSame([], $this->calls);
		}

		$service = $this->service();
		$this->repository->seed('crmPortalAccount', 'acc-1', ['email' => 'jan@example.test', 'tenantId' => 't-1', 'pendingEmail' => 'Nieuw@Example.test', 'emailVerifyTokenHash' => hash('sha256', 'tok'), 'emailVerifyExpiresAt' => '2026-05-01T12:30:00+00:00']);
		$this->assertTrue($service->verifyEmail(token: 'tok', tenantId: 't-1'));
		$this->assertSame(
			['email' => 'nieuw@example.test', 'tenantId' => 't-1', 'pendingEmail' => null, 'emailVerifyTokenHash' => null, 'emailVerifyExpiresAt' => null, '@self' => ['id' => 'acc-1']],
			$this->repository->find('crmPortalAccount', 'acc-1')
		);
		$this->assertSame([['audit', ['acc-1', 't-1', 'email-change', 'success', ['newValue' => 'nieuw@example.test']]]], $this->calls);
	}//end testEveryWayAVerificationEnds()
}//end class
