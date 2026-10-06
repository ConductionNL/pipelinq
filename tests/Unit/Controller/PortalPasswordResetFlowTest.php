<?php

/**
 * End-to-end tests for the portal password reset, through the real path.
 *
 * Every portal endpoint is a PublicPage, so OpenRegister sees each request as
 * Anonymous: it reads no portal account and refuses every save. The reset
 * answered 200 and no mail ever left, and a resident could not log in at all.
 * The portal now writes as a configured portal service account, with
 * OpenRegister's access checks on, and refuses with 503 when that account is
 * missing, disabled or outside its group.
 *
 * These tests drive the REAL controller, the REAL reset, auth, session, audit,
 * token and mail services, the REAL PortalServiceAccount and the REAL
 * PortalObjectRepository over a store that behaves the way OpenRegister does:
 * a write with RBAC on is allowed only when the session user is a member of
 * the service group. Every write is recorded with the acting user and flags,
 * so a write that switched the checks off, or ran as anybody else, fails.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\PortalAuthController;
use OCA\Pipelinq\Service\Portal\PasswordResetService;
use OCA\Pipelinq\Service\Portal\PortalAuditService;
use OCA\Pipelinq\Service\Portal\PortalAuthService;
use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCA\Pipelinq\Service\Portal\PortalMfaService;
use OCA\Pipelinq\Service\Portal\PortalObjectRepository;
use OCA\Pipelinq\Service\Portal\PortalRequestGuard;
use OCA\Pipelinq\Service\Portal\PortalServiceAccount;
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCA\Pipelinq\Tests\Unit\Service\Portal\InstalledAppConfig;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the portal password reset flow.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The flow spans the whole portal
 *  auth collaborator set; the test must wire every real piece of it.
 */
class PortalPasswordResetFlowTest extends TestCase {

	/**
	 * The resident's address.
	 *
	 * @var string
	 */
	private const EMAIL = 'piet@example.nl';

	/**
	 * The resident's account id.
	 *
	 * @var string
	 */
	private const ACCOUNT_ID = '81876ed5-facd-451a-ab0d-077bdcfec793';

	/**
	 * The store, by object id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Every mail the mailer accepted, as [to, body].
	 *
	 * @var array<int, array{to: array<int|string, string>, body: string}>
	 */
	private array $sent = [];

	/**
	 * Request parameters the mocked IRequest serves.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The clock every service reads, as a unix timestamp.
	 *
	 * @var int
	 */
	private int $now = 1800000000;

	/**
	 * The uid the admin picked as portal service account ('' for none).
	 *
	 * @var string
	 */
	private string $serviceUid = 'portal-service';

	/**
	 * Whether the service account is enabled.
	 *
	 * @var bool
	 */
	private bool $serviceEnabled = true;

	/**
	 * The members of the portal service group.
	 *
	 * @var array<int, string>
	 */
	private array $members = ['portal-service'];

	/**
	 * The user on the session (null: an anonymous portal request).
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * Every write the store received: the acting uid and the two flags.
	 *
	 * @var array<int, array{user: string|null, rbac: bool, multitenancy: bool}>
	 */
	private array $writes = [];

	/**
	 * Whether the store fails every write (a database outage).
	 *
	 * @var bool
	 */
	private bool $storeDown = false;

	/**
	 * Seed one active account, created by an administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [
			self::ACCOUNT_ID => [
				'email' => self::EMAIL,
				'tenantId' => 'default',
				'accountType' => 'b2c',
				'status' => 'active',
				'passwordHash' => 'hash:Oud-wachtwoord-123',
				'failedLoginAttempts' => 0,
				'@schema' => InstalledAppConfig::schemaId(slug: 'crmPortalAccount'),
			],
		];
		$this->sent = [];
		$this->params = [];
		$this->writes = [];
	}//end setUp()

	/**
	 * A store that answers like OpenRegister does. Reads: with RBAC or
	 * organisation scoping on, an anonymous caller reads nothing; both off,
	 * the portal register is served. Writes: with RBAC on, only a member of the
	 * portal service group may write.
	 *
	 * @return ObjectServiceInterface The store.
	 */
	private function objectService(): ObjectServiceInterface {
		$service = $this->createMock(ObjectServiceInterface::class);

		$service->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($_rbac === true || $_multitenancy === true) {
					return [];
				}

				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);
				unset($filters['register'], $filters['schema']);

				$found = [];
				foreach ($this->store as $id => $object) {
					if (($object['@schema'] ?? null) !== $schema) {
						continue;
					}

					foreach ($filters as $field => $value) {
						if (($object[$field] ?? null) !== $value) {
							continue 2;
						}
					}

					$found[] = $this->entity(id: $id, object: $object);
				}

				return $found;
			}
		);

		$service->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?ObjectEntityInterface {
				if ($_rbac === true || $_multitenancy === true || isset($this->store[(string)$id]) === false) {
					return null;
				}

				return $this->entity(id: (string)$id, object: $this->store[(string)$id]);
			}
		);

		$service->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntityInterface {
				$actor = $this->sessionUser?->getUID();
				$this->writes[] = ['user' => $actor, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];

				if ($this->storeDown === true) {
					throw new RuntimeException('The database is not available.');
				}

				if ($_rbac === true && in_array($actor, $this->members, true) === false) {
					$action = 'create';
					if ($uuid !== null) {
						$action = 'update';
					}

					$name = ($actor ?? 'Anonymous');
					throw new RuntimeException("User '{$name}' does not have permission to '{$action}' objects in schema '{$schema}'");
				}

				$id = ($uuid ?? bin2hex(random_bytes(16)));
				unset($object['@self']);
				$object['@schema'] = $schema;
				$this->store[$id] = $object;

				return $this->entity(id: $id, object: $object);
			}
		);

		return $service;
	}//end anonymousObjectService()

	/**
	 * An entity that serialises the way OpenRegister's does.
	 *
	 * @param string               $id     The object id.
	 * @param array<string, mixed> $object The stored data.
	 *
	 * @return ObjectEntityInterface The entity, its data under an @self envelope.
	 */
	private function entity(string $id, array $object): ObjectEntityInterface {
		$schema = ($object['@schema'] ?? null);
		unset($object['@schema']);
		$object['@self'] = ['id' => $id, 'schema' => $schema];

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($object);
		$entity->method('getUuid')->willReturn($id);
		return $entity;
	}//end entity()

	/**
	 * A clock that reads $this->now on every call.
	 *
	 * @return ITimeFactory The clock.
	 */
	private function clock(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$time->method('getDateTime')->willReturnCallback(fn (): \DateTime => new \DateTime('@' . $this->now));
		return $time;
	}//end clock()

	/**
	 * A mailer that records every accepted message.
	 *
	 * @return IMailer The mailer.
	 */
	private function mailer(): IMailer {
		$drafts = [];
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturnCallback(
			function () use (&$drafts): IMessage {
				$message = $this->createMock(IMessage::class);
				$key = spl_object_id($message);
				$drafts[$key] = ['to' => [], 'body' => ''];
				$message->method('setTo')->willReturnCallback(
					function (array $to) use (&$drafts, $key, $message): IMessage {
						$drafts[$key]['to'] = $to;
						return $message;
					}
				);
				$message->method('setSubject')->willReturnSelf();
				$message->method('setPlainBody')->willReturnCallback(
					function (string $body) use (&$drafts, $key, $message): IMessage {
						$drafts[$key]['body'] = $body;
						return $message;
					}
				);
				return $message;
			}
		);
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message) use (&$drafts): array {
				$this->sent[] = $drafts[spl_object_id($message)];
				return [];
			}
		);
		return $mailer;
	}//end mailer()

	/**
	 * A Nextcloud account.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether it is enabled.
	 *
	 * @return IUser The account.
	 */
	private function user(string $uid, bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}//end user()

	/**
	 * The real service account over a user manager, groups and a session.
	 *
	 * @param IAppConfig $appConfig The app config holding the chosen uid.
	 *
	 * @return PortalServiceAccount The service account.
	 */
	private function serviceAccount(IAppConfig $appConfig): PortalServiceAccount {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => ($uid === 'portal-service' ? $this->user(uid: $uid, enabled: $this->serviceEnabled) : null)
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $group): bool => $group === PortalServiceAccount::GROUP && in_array($uid, $this->members, true)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUser = $user;
			}
		);

		return new PortalServiceAccount($appConfig, $users, $groups, $session, $this->createMock(LoggerInterface::class));
	}//end serviceAccount()

	/**
	 * Every write ran as the portal service account with RBAC and organisation
	 * scoping on, and the session holds the caller again afterwards.
	 *
	 * @param string|null $caller The uid that was on the session before.
	 *
	 * @return void
	 */
	private function assertEveryWriteRanAsTheServiceAccount(?string $caller = null): void {
		$this->assertNotEmpty($this->writes);
		foreach ($this->writes as $write) {
			$this->assertSame(['user' => 'portal-service', 'rbac' => true, 'multitenancy' => true], $write);
		}

		$this->assertSame($caller, $this->sessionUser?->getUID(), 'The session must hold the caller again.');
	}//end assertEveryWriteRanAsTheServiceAccount()

	/**
	 * Build the real controller over the real services and repository.
	 *
	 * @return PortalAuthController The controller.
	 */
	private function controller(): PortalAuthController {
		$appConfig = InstalledAppConfig::wire(config: $this->createMock(IAppConfig::class));
		$appConfig->setValueString('pipelinq', PortalServiceAccount::CONFIG_KEY, $this->serviceUid);
		$logger = $this->createMock(LoggerInterface::class);
		$repository = new PortalObjectRepository($appConfig, $logger, $this->objectService(), $this->serviceAccount(appConfig: $appConfig));

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $length): string => random_bytes($length));
		$tokens = new PortalTokenService($random, $this->clock());

		$hasher = $this->createMock(IHasher::class);
		$hasher->method('hash')->willReturnCallback(static fn (string $plain): string => 'hash:' . $plain);
		$hasher->method('verify')->willReturnCallback(
			static fn (string $plain, string $hash): bool => hash_equals($hash, 'hash:' . $plain)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://portal.example.nl' . $path);

		$mail = new PortalMailService($this->mailer(), $urls, $l10n, $logger);
		$sessions = new PortalSessionManager($repository, $tokens, $this->clock());
		$audit = new PortalAuditService($repository, $this->clock(), $logger);

		$tenant = $this->createMock(PortalTenantService::class);
		$tenant->method('sessionTtlHours')->willReturn(8);
		$tenant->method('mfaEnforced')->willReturn(false);

		$auth = new PortalAuthService(
			$repository,
			$hasher,
			$sessions,
			$this->createMock(PortalMfaService::class),
			$audit,
			$tenant,
			$this->clock()
		);
		$reset = new PasswordResetService($repository, $tokens, $hasher, $mail, $sessions, $audit, $l10n);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default)
		);
		$guard = $this->createMock(PortalRequestGuard::class);
		$guard->method('resolveTenant')->willReturn('default');

		return new PortalAuthController($request, $guard, $logger, $auth, $sessions, $this->createMock(PortalMfaService::class), $reset, $repository);
	}//end controller()

	/**
	 * Request a reset and return the token from the mailed link.
	 *
	 * @return string The plaintext token in the link.
	 */
	private function requestResetAndReadTheLink(): string {
		$this->params = ['email' => self::EMAIL];
		$response = $this->controller()->passwordResetRequest();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'ok'], $response->getData());

		$this->assertCount(1, $this->sent, 'The reset mail must go out.');
		$this->assertSame([self::EMAIL], $this->sent[0]['to']);
		$matched = preg_match(
			'#https://portal\.example\.nl/index\.php/apps/pipelinq/portal/password-reset\?token=([A-Za-z0-9_-]+)#',
			$this->sent[0]['body'],
			$link
		);
		$this->assertSame(1, $matched, 'The mail must carry a link to the reset page with the token.');

		return rawurldecode($link[1]);
	}//end requestResetAndReadTheLink()

	/**
	 * Complete a reset with the given token.
	 *
	 * @param string $token    The token.
	 * @param string $password The new password.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The response.
	 */
	private function reset(string $token, string $password) {
		$this->params = ['token' => $token, 'password' => $password];
		return $this->controller()->passwordReset();
	}//end reset()

	/**
	 * Log in with the given password.
	 *
	 * @param string $password The password.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The response.
	 */
	private function login(string $password) {
		$this->params = ['email' => self::EMAIL, 'password' => $password];
		return $this->controller()->login();
	}//end login()

	/**
	 * The request saves the token hash on the account, then mails the link.
	 *
	 * @return void
	 */
	public function testTheRequestSavesTheTokenAndMailsTheLink(): void {
		$token = $this->requestResetAndReadTheLink();

		$account = $this->store[self::ACCOUNT_ID];
		$this->assertSame(hash('sha256', $token), $account['passwordResetTokenHash']);
		$this->assertSame(
			(new \DateTime('@' . ($this->now + 1800)))->format(DATE_ATOM),
			$account['passwordResetExpiresAt']
		);
		$this->assertStringNotContainsString($token, json_encode($account));
		$this->assertEveryWriteRanAsTheServiceAccount();
	}//end testTheRequestSavesTheTokenAndMailsTheLink()

	/**
	 * The mailed link sets a new password, and the resident logs in with it.
	 *
	 * @return void
	 */
	public function testTheLinkSetsANewPasswordAndTheResidentLogsIn(): void {
		$token = $this->requestResetAndReadTheLink();

		$response = $this->reset(token: $token, password: 'Nieuw-wachtwoord-456');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'password-reset'], $response->getData());
		$this->assertSame('hash:Nieuw-wachtwoord-456', $this->store[self::ACCOUNT_ID]['passwordHash']);

		$login = $this->login(password: 'Nieuw-wachtwoord-456');
		$this->assertSame(Http::STATUS_OK, $login->getStatus());
		$this->assertSame('authenticated', $login->getData()['status']);
		$this->assertSame(self::ACCOUNT_ID, $login->getData()['accountId']);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->login(password: 'Oud-wachtwoord-123')->getStatus());
		$this->assertEveryWriteRanAsTheServiceAccount();
	}//end testTheLinkSetsANewPasswordAndTheResidentLogsIn()

	/**
	 * The link works once: the second use is refused and the password stays.
	 *
	 * @return void
	 */
	public function testTheLinkWorksOnce(): void {
		$token = $this->requestResetAndReadTheLink();
		$this->assertSame(Http::STATUS_OK, $this->reset(token: $token, password: 'Nieuw-wachtwoord-456')->getStatus());

		$replay = $this->reset(token: $token, password: 'Derde-wachtwoord-789');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $replay->getStatus());
		$this->assertSame('invalidToken', $replay->getData()['errorCode']);
		$this->assertSame('hash:Nieuw-wachtwoord-456', $this->store[self::ACCOUNT_ID]['passwordHash']);
		$this->assertEveryWriteRanAsTheServiceAccount();
	}//end testTheLinkWorksOnce()

	/**
	 * The link expires after thirty minutes.
	 *
	 * @return void
	 */
	public function testTheLinkExpiresAfterThirtyMinutes(): void {
		$token = $this->requestResetAndReadTheLink();
		$this->now += 1801;

		$late = $this->reset(token: $token, password: 'Nieuw-wachtwoord-456');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $late->getStatus());
		$this->assertSame('invalidToken', $late->getData()['errorCode']);
		$this->assertSame('hash:Oud-wachtwoord-123', $this->store[self::ACCOUNT_ID]['passwordHash']);
	}//end testTheLinkExpiresAfterThirtyMinutes()

	/**
	 * The refusal every request gets when the portal cannot write.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return void
	 */
	private function assertUnavailableAndUntouched($response): void {
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('portalUnavailable', $response->getData()['errorCode']);
		$this->assertArrayNotHasKey('reason', $response->getData(), 'An anonymous caller does not learn why.');
		$this->assertSame([], $this->writes, 'Nothing may be written.');
		$this->assertSame([], $this->sent, 'No mail may go out.');
		$this->assertSame('hash:Oud-wachtwoord-123', $this->store[self::ACCOUNT_ID]['passwordHash']);
		$this->assertArrayNotHasKey('passwordResetTokenHash', $this->store[self::ACCOUNT_ID]);
	}//end assertUnavailableAndUntouched()

	/**
	 * Without a service account every portal call answers 503 and writes
	 * nothing, for a known and an unknown address alike (no enumeration).
	 *
	 * @return void
	 */
	public function testWithoutAServiceAccountThePortalAnswers503AndWritesNothing(): void {
		$this->serviceUid = '';

		$this->params = ['email' => self::EMAIL];
		$this->assertUnavailableAndUntouched($this->controller()->passwordResetRequest());

		$this->params = ['email' => 'nobody@example.nl'];
		$this->assertUnavailableAndUntouched($this->controller()->passwordResetRequest());

		$this->assertUnavailableAndUntouched($this->reset(token: 'any-token', password: 'Nieuw-wachtwoord-456'));
		$this->assertUnavailableAndUntouched($this->login(password: 'Oud-wachtwoord-123'));
	}//end testWithoutAServiceAccountThePortalAnswers503AndWritesNothing()

	/**
	 * A disabled service account answers 503 and writes nothing.
	 *
	 * @return void
	 */
	public function testADisabledServiceAccountAnswers503(): void {
		$this->serviceEnabled = false;

		$this->params = ['email' => self::EMAIL];
		$this->assertUnavailableAndUntouched($this->controller()->passwordResetRequest());
		$this->assertUnavailableAndUntouched($this->login(password: 'Oud-wachtwoord-123'));
	}//end testADisabledServiceAccountAnswers503()

	/**
	 * A service account outside the service group answers 503: the portal
	 * schemas grant only that group.
	 *
	 * @return void
	 */
	public function testAServiceAccountOutsideTheGroupAnswers503(): void {
		$this->members = [];

		$this->params = ['email' => self::EMAIL];
		$this->assertUnavailableAndUntouched($this->controller()->passwordResetRequest());
	}//end testAServiceAccountOutsideTheGroupAnswers503()

	/**
	 * A logged-in Nextcloud user on the request keeps their session: the
	 * writes run as the service account and the user is back afterwards.
	 *
	 * @return void
	 */
	public function testTheCallersSessionUserIsRestored(): void {
		$this->sessionUser = $this->user(uid: 'alice');

		$this->requestResetAndReadTheLink();

		$this->assertEveryWriteRanAsTheServiceAccount(caller: 'alice');
	}//end testTheCallersSessionUserIsRestored()

	/**
	 * The caller is restored even when the write fails.
	 *
	 * @return void
	 */
	public function testTheCallerIsRestoredWhenTheWriteFails(): void {
		$this->sessionUser = $this->user(uid: 'alice');
		$this->storeDown = true;

		$this->params = ['email' => self::EMAIL];
		$response = $this->controller()->passwordResetRequest();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame([], $this->sent);
		$this->assertSame('alice', $this->sessionUser?->getUID());
	}//end testTheCallerIsRestoredWhenTheWriteFails()
}//end class
