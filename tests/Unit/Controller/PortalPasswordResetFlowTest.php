<?php

/**
 * End-to-end tests for the portal password reset, through the real path.
 *
 * Every portal endpoint is a PublicPage, so OpenRegister sees each request as
 * Anonymous. With OpenRegister's default RBAC and organisation scoping, an
 * anonymous caller reads no portal account and may not save one: the reset
 * answered 200 and no mail ever left, and a resident could not log in at all.
 * These tests drive the REAL controller, the REAL reset, auth, session, audit,
 * token and mail services and the REAL PortalObjectRepository over a store that
 * behaves the way OpenRegister does for an anonymous caller. A mocked
 * repository could not show the defect, because the defect lives in the
 * arguments the repository passes to OpenRegister.
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
use OCA\Pipelinq\Service\Portal\PortalSessionManager;
use OCA\Pipelinq\Service\Portal\PortalTenantService;
use OCA\Pipelinq\Service\Portal\PortalTokenService;
use OCA\Pipelinq\Tests\Unit\Service\Portal\InstalledAppConfig;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
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
	}//end setUp()

	/**
	 * A store that answers like OpenRegister does for an anonymous caller:
	 * with RBAC or organisation scoping on, it reads nothing and refuses every
	 * save. Both off, it serves the portal register.
	 *
	 * @return ObjectServiceInterface The store.
	 */
	private function anonymousObjectService(): ObjectServiceInterface {
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
				if ($_rbac === true) {
					$action = 'create';
					if ($uuid !== null) {
						$action = 'update';
					}

					throw new RuntimeException("User 'Anonymous' does not have permission to '{$action}' objects in schema '{$schema}'");
				}

				if ($_multitenancy === true) {
					throw new RuntimeException('Anonymous belongs to no organisation.');
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
	 * Build the real controller over the real services and repository.
	 *
	 * @return PortalAuthController The controller.
	 */
	private function controller(): PortalAuthController {
		$appConfig = InstalledAppConfig::wire(config: $this->createMock(IAppConfig::class));
		$logger = $this->createMock(LoggerInterface::class);
		$repository = new PortalObjectRepository($appConfig, $logger, $this->anonymousObjectService());

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
}//end class
