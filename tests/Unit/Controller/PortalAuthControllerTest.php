<?php

/**
 * Contract tests for PortalAuthController.
 *
 * Every endpoint here is reachable unauthenticated from the internet, so the
 * tests pin the properties that matter on such a surface: a uniform
 * password-reset acknowledgement that cannot be used to enumerate accounts, a
 * logout that revokes the session bound to the presented token rather than any
 * client-named session, single-use reset tokens, and the MFA enrolment /
 * verification state machine. The password-reset flow is driven through the REAL
 * PasswordResetService and the REAL token service over a mocked store, because a
 * mocked reset service could not demonstrate either property.
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

use OCA\Pipelinq\Controller\PortalAuthController;
use OCA\Pipelinq\Service\Portal\PasswordResetService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalAuthService;
use OCA\Pipelinq\Service\Portal\PortalException;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCA\Pipelinq\Service\Portal\PortalMfaService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Security\ICrypto;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PortalAuthController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) An auth controller legitimately
 *  aggregates the whole auth-flow collaborator set; the tests must wire all of it.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One contract per endpoint state.
 */
class PortalAuthControllerTest extends TestCase {

	/**
	 * Fixed clock used by every token/TOTP calculation under test.
	 *
	 * @var int
	 */
	private const NOW = 1800000000;

	/**
	 * The authenticated account fixture.
	 *
	 * @var array<string, mixed>
	 */
	private const ACCOUNT = [
		'@self' => ['id' => 'acct-1'],
		'email' => 'own@example.com',
		'status' => 'active',
		'passwordHash' => 'argon2id$stored',
	];

	/**
	 * Request parameters the mocked IRequest serves.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The repository mock.
	 *
	 * @var PortalObjectRepository&MockObject
	 */
	private $repository;

	/**
	 * The session manager mock.
	 *
	 * @var PortalSessionManager&MockObject
	 */
	private $sessions;

	/**
	 * The guard mock.
	 *
	 * @var PortalRequestGuard&MockObject
	 */
	private $guard;

	/**
	 * The MFA service mock (replaced by a real instance where the enrolment
	 * material's shape is under test).
	 *
	 * @var PortalMfaService&MockObject
	 */
	private $mfa;

	/**
	 * Reset the per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->params = [];
		$this->repository = $this->createMock(PortalObjectRepository::class);
		$this->sessions = $this->createMock(PortalSessionManager::class);
		$this->guard = $this->createMock(PortalRequestGuard::class);
		$this->mfa = $this->createMock(PortalMfaService::class);

		$this->repository->method('idOf')->willReturnCallback(
			static fn (array $object): ?string => ($object['@self']['id'] ?? $object['id'] ?? null)
		);
	}//end setUp()

	/**
	 * A request mock serving $this->params.
	 *
	 * @return IRequest&MockObject The request.
	 */
	private function request() {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		return $request;
	}//end request()

	/**
	 * A time factory pinned to self::NOW. getDateTime() hands out a fresh
	 * instance each call because callers mutate it with modify().
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
	 * A real token service over the fixed clock and a deterministic CSPRNG.
	 *
	 * @return PortalTokenService The token service.
	 */
	private function tokenService(): PortalTokenService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static fn (int $length): string => str_repeat('A', $length)
		);
		return new PortalTokenService($random, $this->timeFactory());
	}//end tokenService()

	/**
	 * A localisation mock that echoes its subject.
	 *
	 * @return IL10N&MockObject The l10n mock.
	 */
	private function l10n() {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return $l10n;
	}//end l10n()

	/**
	 * Build the controller.
	 *
	 * @param PortalAuthService|null $auth The auth service (mocked by default).
	 * @param PasswordResetService|null $reset The reset service (mocked by default).
	 * @param PortalMfaService|null $mfa The MFA service (the shared mock by default).
	 *
	 * @return PortalAuthController The controller.
	 */
	private function build(
		?PortalAuthService $auth = null,
		?PasswordResetService $reset = null,
		?PortalMfaService $mfa = null,
	): PortalAuthController {
		return new PortalAuthController($this->request(),
			$this->guard,
			$this->createMock(LoggerInterface::class),
			($auth ?? $this->createMock(PortalAuthService::class)),
			$this->sessions,
			($mfa ?? $this->mfa),
			($reset ?? $this->createMock(PasswordResetService::class)),
			$this->repository
		);
	}//end build()

	/**
	 * Program the guard to authenticate as the fixture account.
	 *
	 * @param array<string, mixed>|null $account An account override.
	 *
	 * @return void
	 */
	private function authenticate(?array $account = null): void {
		$this->guard->method('authenticate')->willReturn(
			[
				'account' => ($account ?? self::ACCOUNT),
				'accountId' => 'acct-1',
				'session' => ['@self' => ['id' => 'sess-live'], 'accountId' => 'acct-1'],
				'tenantId' => 'tenant-a',
			]
		);
		$this->guard->method('resolveTenant')->willReturn('tenant-a');
	}//end authenticate()

	/**
	 * A successful login answers 200 with a session token and the completed
	 * status. The credentials are read from the body and the tenant from the
	 * server-resolved guard, never from a client-supplied tenant field.
	 *
	 * @return void
	 */
	public function testLoginReturnsASessionTokenOnSuccess(): void {
		$this->authenticate();
		$auth = $this->createMock(PortalAuthService::class);
		$auth->expects($this->once())->method('login')
			->with(
				email: 'own@example.com',
				password: 'correct-horse',
				tenantId: 'tenant-a',
				totpCode: null,
				ipHash: $this->anything(),
				userAgentHash: $this->anything()
			)
			->willReturn(
				[
					'status' => 'authenticated',
					'mfaRequired' => false,
					'accountId' => 'acct-1',
					'token' => 'session-token',
					'sessionId' => 'sess-new',
				]
			);

		$this->params = [
			'email' => 'own@example.com',
			'password' => 'correct-horse',
			'tenantId' => 'tenant-of-another-customer',
		];
		$response = $this->build(auth: $auth)->login();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('authenticated', $body['status']);
		$this->assertFalse($body['mfaRequired']);
		$this->assertSame('session-token', $body['token']);
	}//end testLoginReturnsASessionTokenOnSuccess()

	/**
	 * A rejected login answers 401 with the uniform invalidCredentials code and
	 * hands out no token.
	 *
	 * @return void
	 */
	public function testLoginReturnsUnauthorizedWithoutATokenOnFailure(): void {
		$this->authenticate();
		$auth = $this->createMock(PortalAuthService::class);
		$auth->method('login')->willThrowException(
			new PortalException(
				Http::STATUS_UNAUTHORIZED,
				'invalidCredentials',
				'E-mailadres of wachtwoord is onjuist.'
			)
		);

		$this->params = ['email' => 'own@example.com', 'password' => 'wrong'];
		$response = $this->build(auth: $auth)->login();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalidCredentials', $response->getData()['errorCode']);
		$this->assertArrayNotHasKey('token', $response->getData());
	}//end testLoginReturnsUnauthorizedWithoutATokenOnFailure()

	/**
	 * Logout answers 200 with the acknowledgement body.
	 *
	 * @return void
	 */
	public function testLogoutRevokesTheSessionAndReturnsOk(): void {
		$this->authenticate();
		$this->sessions->expects($this->once())->method('revokeSession')
			->with(sessionId: 'sess-live', reason: 'logout');

		$response = $this->build()->logout();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'logged-out'], $response->getData());
	}//end testLogoutRevokesTheSessionAndReturnsOk()

	/**
	 * Logout must revoke the session bound to the presented bearer token, never
	 * a session id supplied in the request body — otherwise any caller could
	 * log any other customer out.
	 *
	 * @return void
	 */
	public function testLogoutIgnoresAClientSuppliedSessionId(): void {
		$this->authenticate();
		$this->params = ['sessionId' => 'sess-of-another-customer', 'id' => 'sess-of-another-customer'];
		$revoked = [];
		$this->sessions->method('revokeSession')->willReturnCallback(
			static function (string $sessionId, string $reason) use (&$revoked): void {
				$revoked[] = $sessionId;
			}
		);

		$response = $this->build()->logout();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['sess-live'], $revoked);
	}//end testLogoutIgnoresAClientSuppliedSessionId()

	/**
	 * Without a valid bearer session logout is 401 and revokes nothing.
	 *
	 * @return void
	 */
	public function testLogoutReturnsUnauthorizedWithoutASession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);
		$this->sessions->expects($this->never())->method('revokeSession');

		$response = $this->build()->logout();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testLogoutReturnsUnauthorizedWithoutASession()

	/**
	 * `extendSession()` refuses without a session, and extends nothing.
	 *
	 * This is the same fail-closed property already pinned for `logout()` and
	 * `mfaVerify()`, and `extendSession()` was the one session-mutating endpoint
	 * on this controller with no test at all. It is `#[PublicPage]`, so the ONLY
	 * thing standing between an anonymous caller and a TTL extension is
	 * `requireSession()` throwing — an unasserted guard is indistinguishable
	 * from an absent one.
	 *
	 * The `never()` expectation is the load-bearing half: a 401 status with the
	 * session already extended would still look like a pass on status alone.
	 *
	 * @return void
	 */
	public function testExtendSessionReturnsUnauthorizedWithoutASession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);
		$this->sessions->expects($this->never())->method('extendSessionOrThrow');

		$response = $this->build()->extendSession();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testExtendSessionReturnsUnauthorizedWithoutASession()

	/**
	 * Build a real PasswordResetService over a mocked store.
	 *
	 * @param array<string, mixed>|null $stored The account findOneBy returns, or null.
	 * @param PortalMailService|null $mail The mail service mock.
	 * @param array<string, mixed>|null $written Captured save payload (by reference).
	 *
	 * @return PasswordResetService The service.
	 */
	private function realResetService(?array $stored, ?PortalMailService $mail = null, ?array &$written = null): PasswordResetService {
		$repository = $this->createMock(PortalObjectRepository::class);
		$repository->method('idOf')->willReturn('acct-1');
		$repository->method('findOneBy')->willReturn($stored);
		$repository->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $id = null) use (&$written): array {
				$written = $data;
				return $data;
			}
		);

		$hasher = $this->createMock(IHasher::class);
		$hasher->method('hash')->willReturnCallback(static fn (string $plain): string => 'argon2id$' . $plain);

		return new PasswordResetService($repository,
			$this->tokenService(),
			$hasher,
			($mail ?? $this->createMock(PortalMailService::class)),
			$this->sessions,
			$this->createMock(PortalAuditService::class),
			$this->l10n()
		);
	}//end realResetService()

	/**
	 * The reset-request acknowledgement must be byte-identical — same status and
	 * same body — for a known and an unknown address, so the endpoint cannot be
	 * used to discover which email addresses hold portal accounts.
	 *
	 * @return void
	 */
	public function testPasswordResetRequestIsIdenticalForKnownAndUnknownEmail(): void {
		$this->authenticate();

		$this->params = ['email' => 'own@example.com'];
		$known = $this->build(reset: $this->realResetService(self::ACCOUNT))->passwordResetRequest();

		$this->params = ['email' => 'nobody@example.com'];
		$unknown = $this->build(reset: $this->realResetService(null))->passwordResetRequest();

		$this->assertSame(Http::STATUS_OK, $known->getStatus());
		$this->assertSame($known->getStatus(), $unknown->getStatus());
		$this->assertSame(['status' => 'ok'], $known->getData());
		$this->assertSame($known->getData(), $unknown->getData());
		$this->assertSame(
			json_encode($known->getData()),
			json_encode($unknown->getData()),
			'The reset acknowledgement must not vary with account existence.'
		);
	}//end testPasswordResetRequestIsIdenticalForKnownAndUnknownEmail()

	/**
	 * A closed account must be treated exactly like an unknown one: no token is
	 * minted, no mail is sent, and the acknowledgement is unchanged.
	 *
	 * @return void
	 */
	public function testPasswordResetRequestTreatsAClosedAccountLikeAnUnknownOne(): void {
		$this->authenticate();
		$mail = $this->createMock(PortalMailService::class);
		$mail->expects($this->never())->method('sendTokenLink');

		$closed = array_merge(self::ACCOUNT, ['status' => 'closed']);
		$this->params = ['email' => 'own@example.com'];
		$response = $this->build(reset: $this->realResetService($closed, $mail))->passwordResetRequest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'ok'], $response->getData());
	}//end testPasswordResetRequestTreatsAClosedAccountLikeAnUnknownOne()

	/**
	 * For a live account the request stores only the token HASH and mails the
	 * plaintext out of band — the plaintext is never persisted.
	 *
	 * @return void
	 */
	public function testPasswordResetRequestPersistsOnlyTheTokenHash(): void {
		$this->authenticate();
		$mailed = [];
		$mail = $this->createMock(PortalMailService::class);
		$mail->method('sendTokenLink')->willReturnCallback(
			static function (string $to, string $path, string $token) use (&$mailed): bool {
				$mailed[] = $token;
				return true;
			}
		);

		$written = null;
		$this->params = ['email' => 'own@example.com'];
		$response = $this->build(reset: $this->realResetService(self::ACCOUNT, $mail, $written))->passwordResetRequest();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $mailed);
		$this->assertIsArray($written);
		$this->assertSame(hash('sha256', $mailed[0]), $written['passwordResetTokenHash']);
		$this->assertNotContains($mailed[0], $written);
		$this->assertNotEmpty($written['passwordResetExpiresAt']);
	}//end testPasswordResetRequestPersistsOnlyTheTokenHash()

	/**
	 * Completing a reset with a valid, unexpired token answers 200, clears the
	 * token, and revokes every live session for the account.
	 *
	 * @return void
	 */
	public function testPasswordResetCompletesAndRevokesEverySession(): void {
		$this->authenticate();
		$token = 'plain-reset-token';
		$stored = array_merge(
			self::ACCOUNT,
			[
				'passwordResetTokenHash' => hash('sha256', $token),
				'passwordResetExpiresAt' => (new \DateTime('@' . (self::NOW + 600)))->format(DATE_ATOM),
			]
		);
		$this->sessions->expects($this->once())->method('revokeAllForAccount')
			->with(accountId: 'acct-1', reason: 'password-reset');

		$written = null;
		$this->params = ['token' => $token, 'password' => 'a-long-enough-password'];
		$response = $this->build(reset: $this->realResetService($stored, null, $written))->passwordReset();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'password-reset'], $response->getData());
		$this->assertNull($written['passwordResetTokenHash']);
		$this->assertSame('argon2id$a-long-enough-password', $written['passwordHash']);
	}//end testPasswordResetCompletesAndRevokesEverySession()

	/**
	 * A reset token is single-use: replaying it after the reset has cleared the
	 * stored hash must answer 400 invalidToken, not reset the password again.
	 *
	 * @return void
	 */
	public function testPasswordResetTokenCannotBeReplayed(): void {
		$this->authenticate();
		$token = 'plain-reset-token';

		// Second presentation: the stored hash was nulled by the first reset, so
		// the lookup finds nothing.
		$this->params = ['token' => $token, 'password' => 'a-long-enough-password'];
		$response = $this->build(reset: $this->realResetService(null))->passwordReset();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidToken', $response->getData()['errorCode']);
	}//end testPasswordResetTokenCannotBeReplayed()

	/**
	 * An expired token is rejected 400 even though its hash still matches.
	 *
	 * @return void
	 */
	public function testPasswordResetRejectsAnExpiredToken(): void {
		$this->authenticate();
		$token = 'plain-reset-token';
		$stored = array_merge(
			self::ACCOUNT,
			[
				'passwordResetTokenHash' => hash('sha256', $token),
				'passwordResetExpiresAt' => (new \DateTime('@' . (self::NOW - 60)))->format(DATE_ATOM),
			]
		);

		$this->params = ['token' => $token, 'password' => 'a-long-enough-password'];
		$response = $this->build(reset: $this->realResetService($stored))->passwordReset();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidToken', $response->getData()['errorCode']);
	}//end testPasswordResetRejectsAnExpiredToken()

	/**
	 * The minimum-length policy rejects with 422 weakPassword and is evaluated
	 * before the token, so a weak password never consumes a valid token.
	 *
	 * @return void
	 */
	public function testPasswordResetRejectsAWeakPasswordWithUnprocessableEntity(): void {
		$this->authenticate();
		$this->sessions->expects($this->never())->method('revokeAllForAccount');

		$this->params = ['token' => 'plain-reset-token', 'password' => 'short'];
		$response = $this->build(reset: $this->realResetService(self::ACCOUNT))->passwordReset();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('weakPassword', $response->getData()['errorCode']);
	}//end testPasswordResetRejectsAWeakPasswordWithUnprocessableEntity()

	/**
	 * A real MFA service over deterministic crypto.
	 *
	 * @return PortalMfaService The service.
	 */
	private function realMfaService(): PortalMfaService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			static fn (int $length): string => str_repeat('K', $length)
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . $plain);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => substr($cipher, 4));

		return new PortalMfaService($random, $crypto, $this->timeFactory());
	}//end realMfaService()

	/**
	 * Enrolment answers 200 with exactly the enrolment material the client needs
	 * to render a QR code — and nothing else.
	 *
	 * @return void
	 */
	public function testMfaEnrollReturnsTheEnrolmentMaterial(): void {
		$this->authenticate();

		$response = $this->build(mfa: $this->realMfaService())->mfaEnroll();
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['secret', 'otpauthUri'], array_keys($body));
		$this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $body['secret']);
		$this->assertStringStartsWith('otpauth://totp/Pipelinq:own%40example.com?', $body['otpauthUri']);
		$this->assertStringContainsString('secret=' . $body['secret'], $body['otpauthUri']);
		$this->assertStringContainsString('digits=6', $body['otpauthUri']);
		$this->assertStringContainsString('period=30', $body['otpauthUri']);
	}//end testMfaEnrollReturnsTheEnrolmentMaterial()

	/**
	 * The plaintext secret must never be written to the account record; only the
	 * encrypted form may be persisted — and it goes to the PENDING field, so an
	 * unverified enrolment cannot become a live second factor.
	 *
	 * @return void
	 */
	public function testMfaEnrollPersistsOnlyTheEncryptedSecret(): void {
		$this->authenticate();
		$written = null;
		$this->repository->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $id = null) use (&$written): array {
				$written = $data;
				return $data;
			}
		);

		$body = $this->build(mfa: $this->realMfaService())->mfaEnroll()->getData();

		$this->assertIsArray($written);
		$this->assertSame('enc:' . $body['secret'], $written['mfaPendingSecret']);
		$this->assertNotSame($body['secret'], $written['mfaPendingSecret']);
		// The live factor is not touched by a proposal.
		$this->assertArrayNotHasKey('mfaSecret', $written);
	}//end testMfaEnrollPersistsOnlyTheEncryptedSecret()

	/**
	 * Beginning an enrolment must not weaken an account that ALREADY has a
	 * verified second factor. The endpoint's own contract says the new secret is
	 * pending until verification, so neither the live `mfaSecret` nor the
	 * `mfaEnabled` flag may change before a code is verified.
	 *
	 * @return void
	 */
	public function testMfaEnrollDoesNotDisableAnAlreadyVerifiedSecondFactor(): void {
		$enrolled = array_merge(self::ACCOUNT, ['mfaEnabled' => true, 'mfaSecret' => 'enc:LIVESECRET']);
		$this->authenticate($enrolled);
		$written = null;
		$this->repository->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $id = null) use (&$written): array {
				$written = $data;
				return $data;
			}
		);

		$response = $this->build(mfa: $this->realMfaService())->mfaEnroll();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($written['mfaEnabled'], 'Enrolment must not clear an active second factor.');
		$this->assertSame('enc:LIVESECRET', $written['mfaSecret'], 'The live secret must survive until verification.');
	}//end testMfaEnrollDoesNotDisableAnAlreadyVerifiedSecondFactor()

	/**
	 * End-to-end consequence of the enrolment write: an account that required a
	 * second factor before enrolment must still require one afterwards. Driven
	 * through the REAL auth service so the login policy is the one that ships.
	 *
	 * @return void
	 */
	public function testMfaEnrollDoesNotDowngradeASubsequentPasswordLogin(): void {
		$store = array_merge(self::ACCOUNT, ['mfaEnabled' => true, 'mfaSecret' => 'enc:LIVESECRET']);
		$account = static function () use (&$store): array {
			return $store;
		};

		$repository = $this->createMock(PortalObjectRepository::class);
		$repository->method('idOf')->willReturn('acct-1');
		$repository->method('find')->willReturnCallback($account);
		$repository->method('findOneBy')->willReturnCallback($account);
		$repository->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $id = null) use (&$store): array {
				$store = $data;
				return $data;
			}
		);

		$hasher = $this->createMock(IHasher::class);
		$hasher->method('verify')->willReturn(true);

		$tenant = $this->createMock(PortalTenantService::class);
		$tenant->method('mfaEnforced')->willReturn(false);
		$tenant->method('sessionTtlHours')->willReturn(24);

		$sessions = $this->createMock(PortalSessionManager::class);
		$sessions->method('createSession')->willReturn(
			['token' => 'full-session-token', 'session' => ['@self' => ['id' => 'sess-new']]]
		);

		$mfa = $this->realMfaService();
		$auth = new PortalAuthService($repository,
			$hasher,
			$sessions,
			$mfa,
			$this->createMock(PortalAuditService::class),
			$tenant,
			$this->timeFactory()
		);

		$this->guard->method('resolveTenant')->willReturn('tenant-a');
		$this->guard->method('authenticate')->willReturnCallback(
			static function () use (&$store): array {
				return [
					'account' => $store,
					'accountId' => 'acct-1',
					'session' => ['@self' => ['id' => 'sess-live'], 'accountId' => 'acct-1'],
					'tenantId' => 'tenant-a',
				];
			}
		);
		$this->repository = $repository;

		$this->params = ['email' => 'own@example.com', 'password' => 'correct-horse'];
		$before = $this->build(auth: $auth, mfa: $mfa)->login()->getData();
		$this->assertSame('mfa-required', $before['status'], 'Precondition: the account is MFA-protected.');

		$this->build(auth: $auth, mfa: $mfa)->mfaEnroll();

		$after = $this->build(auth: $auth, mfa: $mfa)->login()->getData();

		$this->assertSame('mfa-required', $after['status'], 'Abandoning an enrolment must not disable MFA.');
		$this->assertArrayNotHasKey('token', $after, 'A password-only login must not mint a full session.');
	}//end testMfaEnrollDoesNotDowngradeASubsequentPasswordLogin()

	/**
	 * Enrolment requires a live session; anonymous callers get 401 and nothing
	 * is written to any account.
	 *
	 * @return void
	 */
	public function testMfaEnrollReturnsUnauthorizedWithoutASession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);
		$this->repository->expects($this->never())->method('save');

		$response = $this->build(mfa: $this->realMfaService())->mfaEnroll();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testMfaEnrollReturnsUnauthorizedWithoutASession()

	/**
	 * A correct code activates the factor: 200, the acknowledgement body, and
	 * mfaEnabled persisted as true.
	 *
	 * @return void
	 */
	public function testMfaVerifyActivatesTheSecondFactor(): void {
		$this->authenticate(array_merge(self::ACCOUNT, ['mfaSecret' => 'enc:PENDING']));
		$this->mfa->method('verifyCode')->willReturn(true);
		$written = null;
		$this->repository->method('save')->willReturnCallback(
			static function (string $schema, array $data, ?string $id = null) use (&$written): array {
				$written = $data;
				return $data;
			}
		);

		$this->params = ['code' => '123456'];
		$response = $this->build()->mfaVerify();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'mfa-enabled'], $response->getData());
		$this->assertTrue($written['mfaEnabled']);
	}//end testMfaVerifyActivatesTheSecondFactor()

	/**
	 * A wrong code answers 400 invalidMfaCode and must NOT flip mfaEnabled.
	 *
	 * @return void
	 */
	public function testMfaVerifyRejectsAnInvalidCodeWithoutEnablingMfa(): void {
		$this->authenticate(array_merge(self::ACCOUNT, ['mfaSecret' => 'enc:PENDING']));
		$this->mfa->method('verifyCode')->willReturn(false);
		$this->repository->expects($this->never())->method('save');

		$this->params = ['code' => '000000'];
		$response = $this->build()->mfaVerify();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidMfaCode', $response->getData()['errorCode']);
	}//end testMfaVerifyRejectsAnInvalidCodeWithoutEnablingMfa()

	/**
	 * Verification must fail closed when no secret was ever staged, rather than
	 * enabling a factor with no secret behind it.
	 *
	 * @return void
	 */
	public function testMfaVerifyFailsClosedWhenNoSecretIsStaged(): void {
		$this->authenticate();
		$this->repository->expects($this->never())->method('save');

		$this->params = ['code' => '123456'];
		$response = $this->build(mfa: $this->realMfaService())->mfaVerify();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalidMfaCode', $response->getData()['errorCode']);
	}//end testMfaVerifyFailsClosedWhenNoSecretIsStaged()

	/**
	 * Verification requires a live session.
	 *
	 * @return void
	 */
	public function testMfaVerifyReturnsUnauthorizedWithoutASession(): void {
		$this->guard->method('authenticate')->willThrowException(
			new PortalException(Http::STATUS_UNAUTHORIZED, 'unauthenticated', 'Niet ingelogd.')
		);

		$this->params = ['code' => '123456'];
		$response = $this->build()->mfaVerify();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('unauthenticated', $response->getData()['errorCode']);
	}//end testMfaVerifyReturnsUnauthorizedWithoutASession()
}//end class
