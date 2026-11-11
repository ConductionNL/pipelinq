<?php

/**
 * Golden test for PortalAuthService::login: every refusal and every success path.
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


declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalAuthService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalMfaService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\IHasher;
use PHPUnit\Framework\TestCase;

/**
 * Runs login() through every gate — not writable, unknown account, closed,
 * locked out, wrong password (counting and arming the lockout), MFA enrollment,
 * MFA code missing, wrong and right — and compares the calls made on the
 * repository, session manager, MFA service and audit log, plus the result or
 * the refusal, with a recorded golden file. Time is frozen.
 */
class PortalLoginGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/PortalLoginGolden.json';

	/**
	 * Frozen "now" (2026-03-01T12:00:00+00:00).
	 *
	 * @var int
	 */
	private const NOW = 1772366400;

	/**
	 * The calls made, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $trace = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$base = [
			'email' => 'jan@pietersen.nl',
			'tenantId' => 'tenant-a',
			'passwordHash' => 'h:secret-pw',
			'status' => 'active',
			'failedLoginAttempts' => 2,
			'lockedUntil' => '',
			'mfaEnabled' => false,
		];
		$mfa = ['mfaEnabled' => true, 'mfaSecret' => 'enc-secret'] + $base;
		$cases = [
			'portal not writable' => ['account' => $base, 'writable' => false],
			'unknown account' => ['account' => null],
			'closed account' => ['account' => ['status' => 'closed'] + $base],
			'locked out' => ['account' => ['lockedUntil' => '2026-03-01T12:10:00+00:00'] + $base],
			'lockout expired' => ['account' => ['lockedUntil' => '2026-03-01T11:59:59+00:00'] + $base],
			'unparsable lockout' => ['account' => ['lockedUntil' => 'not a date'] + $base],
			'wrong password' => ['account' => $base, 'password' => 'nope'],
			'wrong password arms the lockout' => ['account' => ['failedLoginAttempts' => 4] + $base, 'password' => 'nope'],
			'wrong password without a counter' => ['account' => array_diff_key($base, ['failedLoginAttempts' => 1]), 'password' => 'nope'],
			'no password hash' => ['account' => array_diff_key($base, ['passwordHash' => 1])],
			'success' => ['account' => $base, 'email' => '  Jan@Pietersen.NL '],
			'success without status' => ['account' => array_diff_key($base, ['status' => 1])],
			'tenant enforces mfa, not enrolled' => ['account' => $base, 'enforced' => true],
			'mfa enabled without a secret' => ['account' => ['mfaEnabled' => true] + $base],
			'mfa, no code' => ['account' => $mfa],
			'mfa, empty code' => ['account' => $mfa, 'code' => ''],
			'mfa, wrong code' => ['account' => $mfa, 'code' => '000000'],
			'mfa, right code' => ['account' => $mfa, 'code' => '123456'],
			'mfa enforced and enrolled, right code' => ['account' => ['mfaSecret' => 'enc-secret'] + $base, 'enforced' => true, 'code' => '123456'],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			$actual[$name] = $this->attempt(case: $case);
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('PORTAL_LOGIN_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()

	/**
	 * Run one login attempt and return its trace and outcome.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function attempt(array $case): array {
		$this->trace = [];
		$auth = new PortalAuthService(
			$this->repository(account: $case['account'], writable: ($case['writable'] ?? true)),
			$this->hasher(),
			$this->sessions(),
			$this->mfa(),
			$this->audit(),
			$this->tenant(enforced: ($case['enforced'] ?? false)),
			$this->time()
		);

		try {
			$outcome = ['result' => $auth->login(($case['email'] ?? 'jan@pietersen.nl'), ($case['password'] ?? 'secret-pw'), 'tenant-a', ($case['code'] ?? null), 'ip-hash', 'ua-hash')];
		} catch (PortalException $e) {
			$outcome = ['refused' => [$e->getStatus(), $e->getErrorCode(), $e->getMessage()]];
		}

		return ['calls' => $this->trace] + $outcome;
	}//end attempt()

	/**
	 * A repository serving one account, recording every call.
	 *
	 * @param array<string, mixed>|null $account  The stored account, or null.
	 * @param bool                      $writable Whether the portal can write.
	 *
	 * @return PortalObjectRepository
	 */
	private function repository(?array $account, bool $writable): PortalObjectRepository {
		$repository = $this->createMock(PortalObjectRepository::class);
		$repository->method('requireWritable')->willReturnCallback(
			function () use ($writable): void {
				$this->trace[] = ['requireWritable'];
				if ($writable === false) {
					throw new PortalException(503, 'portalUnavailable', 'Portal unavailable.');
				}
			}
		);
		$repository->method('findOneBy')->willReturnCallback(
			function (string $schema, array $filters) use ($account): ?array {
				$this->trace[] = ['findOneBy', $schema, $filters];
				return $account;
			}
		);
		$repository->method('idOf')->willReturnCallback(
			function (mixed $object): ?string {
				$this->trace[] = ['idOf', $object];
				return (string)($object['id'] ?? 'acc-1');
			}
		);
		$repository->method('save')->willReturnCallback(
			function (string $schema, array $data, ?string $id = null): array {
				$this->trace[] = ['save', $schema, $data, $id];
				return $data;
			}
		);
		return $repository;
	}//end repository()

	/**
	 * A hasher: hash = "h:" . password.
	 *
	 * @return IHasher
	 */
	private function hasher(): IHasher {
		$hasher = $this->createMock(IHasher::class);
		$hasher->method('verify')->willReturnCallback(
			function (string $message, string $hash): bool {
				$this->trace[] = ['verify', $message, $hash];
				return $hash === 'h:' . $message;
			}
		);
		return $hasher;
	}//end hasher()

	/**
	 * A session manager recording every session it creates.
	 *
	 * @return PortalSessionManager
	 */
	private function sessions(): PortalSessionManager {
		$sessions = $this->createMock(PortalSessionManager::class);
		$sessions->method('createSession')->willReturnCallback(
			function (string $accountId, string $tenantId, string $ipHash, string $userAgentHash, int $ttlHours = 8, bool $mfaPending = false): array {
				$this->trace[] = ['createSession', $accountId, $tenantId, $ipHash, $userAgentHash, $ttlHours, $mfaPending];
				return ['token' => 'tok-1', 'session' => ['id' => 'ses-1']];
			}
		);
		return $sessions;
	}//end sessions()

	/**
	 * An MFA service accepting only 123456.
	 *
	 * @return PortalMfaService
	 */
	private function mfa(): PortalMfaService {
		$mfa = $this->createMock(PortalMfaService::class);
		$mfa->method('verifyCode')->willReturnCallback(
			function (?string $encryptedSecret, ?string $code): bool {
				$this->trace[] = ['verifyCode', $encryptedSecret, $code];
				return $code === '123456';
			}
		);
		return $mfa;
	}//end mfa()

	/**
	 * An audit log recording every event.
	 *
	 * @return PortalAuditService
	 */
	private function audit(): PortalAuditService {
		$audit = $this->createMock(PortalAuditService::class);
		$audit->method('log')->willReturnCallback(
			function (?string $accountId, string $tenantId, string $eventType, string $outcome, array $details = []): void {
				$this->trace[] = ['audit', $accountId, $tenantId, $eventType, $outcome, $details];
			}
		);
		return $audit;
	}//end audit()

	/**
	 * A tenant service with an 8-hour session and optional MFA enforcement.
	 *
	 * @param bool $enforced Whether the tenant enforces MFA.
	 *
	 * @return PortalTenantService
	 */
	private function tenant(bool $enforced): PortalTenantService {
		$tenant = $this->createMock(PortalTenantService::class);
		$tenant->method('sessionTtlHours')->willReturnCallback(
			function (string $tenantId): int {
				$this->trace[] = ['sessionTtlHours', $tenantId];
				return 8;
			}
		);
		$tenant->method('mfaEnforced')->willReturnCallback(
			function (string $tenantId) use ($enforced): bool {
				$this->trace[] = ['mfaEnforced', $tenantId];
				return $enforced;
			}
		);
		return $tenant;
	}//end tenant()

	/**
	 * A frozen clock in UTC.
	 *
	 * @return ITimeFactory
	 */
	private function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('@' . self::NOW));
		return $time;
	}//end time()
}//end class
