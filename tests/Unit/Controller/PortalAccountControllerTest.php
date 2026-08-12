<?php

/**
 * Contract tests for PortalAccountController.
 *
 * The self-service profile and account-lifecycle surface. The two token-bearing
 * endpoints (email verification and closure confirmation) are driven through the
 * REAL profile / account services and the REAL token service over a stateful
 * in-memory store, so the single-use property is demonstrated rather than
 * assumed: the same token is presented twice and the second presentation must be
 * refused. Profile reads and writes are checked for field leakage and for
 * mass-assignment of fields the wire contract does not expose.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PortalAccountController;
use OCA\Pipelinq\Service\Portal\PortalAccountService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalExportService;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalProfileService;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalAccountController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller fronts three
 *  services; testing it for real means wiring all of them.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One contract per endpoint state.
 */
class PortalAccountControllerTest extends TestCase {

	/**
	 * Fixed clock for token issue/expiry arithmetic.
	 *
	 * @var int
	 */
	private const NOW = 1800000000;

	/**
	 * The in-memory account record every mocked store operation reads/writes.
	 *
	 * @var array<string, mixed>
	 */
	private array $store = [];

	/**
	 * Request parameters the mocked IRequest serves.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Recorded token-link mails as [recipient, token].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $mails = [];

	/**
	 * The guard mock.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private $guard;

	/**
	 * The session manager mock.
	 *
	 * @var PortalSessionManager&MockObject
	 */
	private $sessions;

	/**
	 * The export service mock.
	 *
	 * @var PortalExportService&MockObject
	 */
	private $export;

	/**
	 * Reset per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->params = [];
		$this->mails = [];
		$this->store = [
			'@self' => ['id' => 'acct-1'],
			'email' => 'own@example.com',
			'status' => 'active',
			'displayName' => 'Old Name',
			'locale' => 'nl',
			'accountType' => 'b2c',
		];

		$this->guard = $this->createMock(PortalRequestGuard::class);
		$this->sessions = $this->createMock(PortalSessionManager::class);
		$this->export = $this->createMock(PortalExportService::class);
	}//end setUp()

	/**
	 * A time factory pinned to self::NOW.
	 *
	 * @return ITimeFactory&MockObject The time factory.
	 */
	private function timeFactory() {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturnCallback(
			static fn (): \DateTime => new \DateTime('@' . self::NOW)
		);
		return $time;
	}//end timeFactory()

	/**
	 * A repository mock backed by $this->store: findOneBy honours the equality
	 * filters it is given, so a token that was cleared really stops matching.
	 *
	 * @return PortalObjectRepository&MockObject The repository.
	 */
	private function statefulRepository() {
		$repository = $this->createMock(PortalObjectRepository::class);
		$repository->method('idOf')->willReturn('acct-1');
		$repository->method('find')->willReturnCallback(fn (): array => $this->store);
		$repository->method('findOneBy')->willReturnCallback(
			function (string $schema, array $filters): ?array {
				foreach ($filters as $field => $expected) {
					if ($field === 'tenantId') {
						continue;
					}

					if (($this->store[$field] ?? null) !== $expected) {
						return null;
					}
				}

				return $this->store;
			}
		);
		$repository->method('save')->willReturnCallback(
			function (string $schema, array $data, ?string $id = null): array {
				$this->store = $data;
				return $data;
			}
		);
		return $repository;
	}//end statefulRepository()

	/**
	 * A mail service mock that records the token links it is asked to send.
	 *
	 * @return PortalMailService&MockObject The mail service.
	 */
	private function mailService() {
		$mail = $this->createMock(PortalMailService::class);
		$mail->method('sendTokenLink')->willReturnCallback(
			function (string $recipient, string $route, string $token): bool {
				$this->mails[] = [$recipient, $token];
				return true;
			}
		);
		$mail->method('send')->willReturn(true);
		return $mail;
	}//end mailService()

	/**
	 * Build the controller over the real profile and account services.
	 *
	 * @return PortalAccountController The controller.
	 */
	private function build(): PortalAccountController {
		$repository = $this->statefulRepository();
		$tokens = new PortalTokenService($this->secureRandom(), $this->timeFactory());
		$mail = $this->mailService();
		$audit = $this->createMock(PortalAuditService::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);

		return new PortalAccountController(
			$request,
			$this->guard,
			$this->createMock(LoggerInterface::class),
			new PortalProfileService($repository, $tokens, $mail, $audit, $l10n),
			new PortalAccountService($repository, $tokens, $mail, $this->sessions, $audit, $l10n),
			$this->export
		);
	}//end build()

	/**
	 * A deterministic CSPRNG whose output differs per call, so two tokens issued
	 * in the same test are genuinely different values.
	 *
	 * @return ISecureRandom&MockObject The CSPRNG.
	 */
	private function secureRandom() {
		$counter = 0;
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static function (int $length) use (&$counter): string {
				$counter++;
				return substr(str_pad((string)$counter, $length, 'Z'), 0, $length);
			}
		);
		return $random;
	}//end secureRandom()

	/**
	 * Authenticate the guard as the stored account.
	 *
	 * @return void
	 */
	private function authenticate(): void {
		$this->guard->method('authenticate')->willReturnCallback(
			fn (): array => [
				'account' => $this->store,
				'accountId' => 'acct-1',
				'session' => ['@self' => ['id' => 'sess-live'], 'accountId' => 'acct-1'],
				'tenantId' => 'tenant-a',
			]
		);
		$this->guard->method('resolveTenant')->willReturn('tenant-a');
	}//end authenticate()

	/**
	 * Refuse authentication.
	 *
	 * @return void
	 */
	private function refuseAuthentication(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);
		$this->guard->method('resolveTenant')->willReturn('tenant-a');
	}//end refuseAuthentication()

	/**
	 * The profile read answers 200 with exactly the safe projection.
	 *
	 * @return void
	 */
	public function testProfileReturnsTheSafeProjection(): void {
		$this->authenticate();

		$response = $this->build()->profile();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['id', 'email', 'pendingEmail', 'displayName', 'phone', 'address', 'locale', 'jobTitle', 'accountType'],
			array_keys($body)
		);
		$this->assertSame('acct-1', $body['id']);
		$this->assertSame('own@example.com', $body['email']);
		$this->assertSame('Old Name', $body['displayName']);
	}//end testProfileReturnsTheSafeProjection()

	/**
	 * Credentials and second-factor material stored on the account record must
	 * never appear in the profile response.
	 *
	 * @return void
	 */
	public function testProfileNeverLeaksCredentialMaterial(): void {
		$this->store['passwordHash'] = 'argon2id$secret';
		$this->store['mfaSecret'] = 'enc:TOTPSECRET';
		$this->store['passwordResetTokenHash'] = 'deadbeef';
		$this->authenticate();

		$body = $this->build()->profile()->getData();

		$this->assertArrayNotHasKey('passwordHash', $body);
		$this->assertArrayNotHasKey('mfaSecret', $body);
		$this->assertArrayNotHasKey('passwordResetTokenHash', $body);
		$this->assertStringNotContainsString('TOTPSECRET', (string)json_encode($body));
		$this->assertStringNotContainsString('argon2id', (string)json_encode($body));
	}//end testProfileNeverLeaksCredentialMaterial()

	/**
	 * Without a bearer session the profile is 401.
	 *
	 * @return void
	 */
	public function testProfileReturnsUnauthorizedWithoutASession(): void {
		$this->refuseAuthentication();

		$response = $this->build()->profile();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testProfileReturnsUnauthorizedWithoutASession()

	/**
	 * A profile update answers 200 with the refreshed projection and trims the
	 * submitted strings.
	 *
	 * @return void
	 */
	public function testUpdateProfileAppliesAndTrimsEditableFields(): void {
		$this->authenticate();
		$this->params = ['displayName' => '  New Name  ', 'phone' => ' 0612345678 ', 'jobTitle' => 'Inkoper'];

		$response = $this->build()->updateProfile();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('New Name', $body['displayName']);
		$this->assertSame('0612345678', $body['phone']);
		$this->assertSame('Inkoper', $body['jobTitle']);
		$this->assertSame('New Name', $this->store['displayName']);
	}//end testUpdateProfileAppliesAndTrimsEditableFields()

	/**
	 * Mass-assignment guard: fields outside the editable allow-list submitted by
	 * the client must not reach the account record. Re-opening a closed account
	 * or overwriting the password hash through the profile endpoint would be an
	 * authentication bypass.
	 *
	 * @return void
	 */
	public function testUpdateProfileIgnoresFieldsOutsideTheAllowList(): void {
		$this->store['status'] = 'active';
		$this->store['passwordHash'] = 'argon2id$original';
		$this->authenticate();
		$this->params = [
			'displayName' => 'New Name',
			'status' => 'closed',
			'passwordHash' => 'argon2id$attacker',
			'mfaEnabled' => false,
			'linkedOrganisationId' => 'client-someone-else',
			'tenantId' => 'tenant-b',
		];

		$response = $this->build()->updateProfile();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('active', $this->store['status']);
		$this->assertSame('argon2id$original', $this->store['passwordHash']);
		$this->assertArrayNotHasKey('linkedOrganisationId', $this->store);
		$this->assertArrayNotHasKey('tenantId', $this->store);
		$this->assertArrayNotHasKey('mfaEnabled', $this->store);
	}//end testUpdateProfileIgnoresFieldsOutsideTheAllowList()

	/**
	 * An email change is staged, never applied in-band: the login email is
	 * unchanged, the new address is reported as pending, and the confirmation
	 * link goes to the NEW address.
	 *
	 * @return void
	 */
	public function testUpdateProfileStagesAnEmailChangeInsteadOfApplyingIt(): void {
		$this->authenticate();
		$this->params = ['email' => 'New@Example.com'];

		$body = $this->build()->updateProfile()->getData();

		$this->assertSame('own@example.com', $body['email']);
		$this->assertSame('new@example.com', $body['pendingEmail']);
		$this->assertSame('own@example.com', $this->store['email']);
		$this->assertCount(1, $this->mails);
		$this->assertSame('new@example.com', $this->mails[0][0]);
	}//end testUpdateProfileStagesAnEmailChangeInsteadOfApplyingIt()

	/**
	 * Updating the profile requires a session.
	 *
	 * @return void
	 */
	public function testUpdateProfileReturnsUnauthorizedWithoutASession(): void {
		$this->refuseAuthentication();
		$this->params = ['displayName' => 'New Name'];

		$response = $this->build()->updateProfile();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Old Name', $this->store['displayName']);
	}//end testUpdateProfileReturnsUnauthorizedWithoutASession()

	/**
	 * A valid verification token swaps the pending address into place and
	 * answers 200 with the acknowledgement.
	 *
	 * @return void
	 */
	public function testVerifyEmailAppliesThePendingAddress(): void {
		$this->authenticate();
		$controller = $this->build();
		$this->params = ['email' => 'new@example.com'];
		$controller->updateProfile();
		$token = $this->mails[0][1];

		$this->params = ['token' => $token];
		$response = $controller->verifyEmail();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'email-verified'], $response->getData());
		$this->assertSame('new@example.com', $this->store['email']);
		$this->assertNull($this->store['pendingEmail']);
	}//end testVerifyEmailAppliesThePendingAddress()

	/**
	 * The verification token is single-use: replaying it answers 400
	 * invalidToken and does not re-apply anything.
	 *
	 * @return void
	 */
	public function testVerifyEmailTokenCannotBeReplayed(): void {
		$this->authenticate();
		$controller = $this->build();
		$this->params = ['email' => 'new@example.com'];
		$controller->updateProfile();
		$token = $this->mails[0][1];

		$this->params = ['token' => $token];
		$first = $controller->verifyEmail();
		$second = $controller->verifyEmail();

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $second->getStatus());
		$this->assertSame('invalidToken', $second->getData()['errorCode']);
	}//end testVerifyEmailTokenCannotBeReplayed()

	/**
	 * An unknown token answers 400 invalidToken without disclosing whether any
	 * pending change exists.
	 *
	 * @return void
	 */
	public function testVerifyEmailRejectsAnUnknownToken(): void {
		$this->authenticate();
		$this->params = ['token' => 'not-a-real-token'];

		$response = $this->build()->verifyEmail();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidToken', $response->getData()['errorCode']);
		$this->assertSame('own@example.com', $this->store['email']);
	}//end testVerifyEmailRejectsAnUnknownToken()

	/**
	 * An empty token must be refused rather than matching an account whose
	 * token fields are unset.
	 *
	 * @return void
	 */
	public function testVerifyEmailRejectsAnEmptyToken(): void {
		$this->authenticate();
		$this->params = ['token' => ''];

		$response = $this->build()->verifyEmail();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidToken', $response->getData()['errorCode']);
		$this->assertSame('own@example.com', $this->store['email']);
	}//end testVerifyEmailRejectsAnEmptyToken()

	/**
	 * An export request is accepted with 202 and the download descriptor.
	 *
	 * @return void
	 */
	public function testRequestExportReturnsAcceptedWithTheDownloadDescriptor(): void {
		$this->authenticate();
		$this->export->method('requestExport')->willReturn(
			['downloadUrl' => '/portal/api/documents/tok.sig/download', 'expiresAt' => (self::NOW + 2592000)]
		);

		$response = $this->build()->requestExport();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
		$this->assertSame(['downloadUrl', 'expiresAt'], array_keys($body));
		$this->assertStringStartsWith('/portal/api/documents/', $body['downloadUrl']);
		$this->assertGreaterThan(self::NOW, $body['expiresAt']);
	}//end testRequestExportReturnsAcceptedWithTheDownloadDescriptor()

	/**
	 * The export is always built for the token's account: an anonymous caller
	 * gets 401 and no export is assembled.
	 *
	 * @return void
	 */
	public function testRequestExportReturnsUnauthorizedWithoutASession(): void {
		$this->refuseAuthentication();
		$this->export->expects($this->never())->method('requestExport');

		$response = $this->build()->requestExport();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testRequestExportReturnsUnauthorizedWithoutASession()

	/**
	 * Requesting closure answers 200, stages a token, and mails the
	 * confirmation link to the account's own address — never to an address
	 * supplied in the request.
	 *
	 * @return void
	 */
	public function testRequestCloseStagesATokenAndMailsTheAccountAddress(): void {
		$this->authenticate();
		$this->params = ['email' => 'attacker@example.com'];

		$response = $this->build()->requestClose();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'closure-requested'], $response->getData());
		$this->assertSame('active', $this->store['status']);
		$this->assertCount(1, $this->mails);
		$this->assertSame('own@example.com', $this->mails[0][0]);
		$this->assertSame(hash('sha256', $this->mails[0][1]), $this->store['closeTokenHash']);
	}//end testRequestCloseStagesATokenAndMailsTheAccountAddress()

	/**
	 * Requesting closure requires a session.
	 *
	 * @return void
	 */
	public function testRequestCloseReturnsUnauthorizedWithoutASession(): void {
		$this->refuseAuthentication();

		$response = $this->build()->requestClose();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertArrayNotHasKey('closeTokenHash', $this->store);
	}//end testRequestCloseReturnsUnauthorizedWithoutASession()

	/**
	 * Confirming with the emailed token closes the account, revokes every
	 * session, and answers 200.
	 *
	 * @return void
	 */
	public function testConfirmCloseClosesTheAccountAndRevokesSessions(): void {
		$this->authenticate();
		$controller = $this->build();
		$controller->requestClose();
		$token = $this->mails[0][1];

		$this->sessions->expects($this->once())->method('revokeAllForAccount')
			->with(accountId: 'acct-1', reason: 'account-closure');

		$this->params = ['token' => $token];
		$response = $controller->confirmClose();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'account-closed'], $response->getData());
		$this->assertSame('closed', $this->store['status']);
		$this->assertNull($this->store['closeTokenHash']);
	}//end testConfirmCloseClosesTheAccountAndRevokesSessions()

	/**
	 * The closure token is single-use: a replay answers 400 invalidToken.
	 *
	 * @return void
	 */
	public function testConfirmCloseTokenCannotBeReplayed(): void {
		$this->authenticate();
		$controller = $this->build();
		$controller->requestClose();
		$token = $this->mails[0][1];

		$this->params = ['token' => $token];
		$first = $controller->confirmClose();
		$second = $controller->confirmClose();

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $second->getStatus());
		$this->assertSame('invalidToken', $second->getData()['errorCode']);
	}//end testConfirmCloseTokenCannotBeReplayed()

	/**
	 * A forged closure token answers 400 and leaves the account open — this
	 * endpoint is reachable without a session, so it is the one that must not
	 * be brute-forceable into closing somebody's account.
	 *
	 * @return void
	 */
	public function testConfirmCloseRejectsAForgedToken(): void {
		$this->authenticate();
		$controller = $this->build();
		$controller->requestClose();

		$this->params = ['token' => 'forged-token-value'];
		$response = $controller->confirmClose();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidToken', $response->getData()['errorCode']);
		$this->assertSame('active', $this->store['status']);
	}//end testConfirmCloseRejectsAForgedToken()

	/**
	 * An empty token must not match an account that has no closure pending.
	 *
	 * @return void
	 */
	public function testConfirmCloseRejectsAnEmptyToken(): void {
		$this->authenticate();
		$this->params = ['token' => ''];

		$response = $this->build()->confirmClose();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidToken', $response->getData()['errorCode']);
		$this->assertSame('active', $this->store['status']);
	}//end testConfirmCloseRejectsAnEmptyToken()
}//end class
