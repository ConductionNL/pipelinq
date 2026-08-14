<?php

/**
 * Unit tests for MessagingController.
 *
 * Covers the auth guard, per-object guard, outcome→HTTP-status mapping and
 * error hygiene of the outbound messaging send surface.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-004--server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MessagingController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * MessagingController unit coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class MessagingControllerTest extends TestCase {
	private MessagingService $messagingService;
	private ChannelProviderRepository $providerRepo;
	private ConsentService $consentService;
	private IUserSession $userSession;
	private MessagingController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->messagingService = $this->createMock(MessagingService::class);
		$this->providerRepo = $this->createMock(ChannelProviderRepository::class);
		$this->consentService = $this->createMock(ConsentService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->controller = new MessagingController(
			$this->createMock(IRequest::class),
			$this->messagingService,
			$this->providerRepo,
			$this->consentService,
			$this->userSession,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true]),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Stub the session to return a user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * An unauthenticated send is rejected with 401.
	 *
	 * @return void
	 */
	public function testSendUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->send(contactId: 'c1', channel: 'sms', body: 'hi');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testSendUnauthorized()

	/**
	 * An invalid channel is rejected with 400.
	 *
	 * @return void
	 */
	public function testSendInvalidChannel(): void {
		$this->signIn('agent-1');
		$response = $this->controller->send(contactId: 'c1', channel: 'carrier-pigeon', body: 'hi');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testSendInvalidChannel()

	/**
	 * A caller without access to the contact is refused before dispatch (404).
	 *
	 * @return void
	 */
	public function testSendUnauthorizedContactRejectedBeforeDispatch(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(null);
		$this->messagingService->expects($this->never())->method('send');

		$response = $this->controller->send(contactId: 'c-nope', channel: 'sms', body: 'hi');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testSendUnauthorizedContactRejectedBeforeDispatch()

	/**
	 * A sent outcome maps to HTTP 200 with the envelope.
	 *
	 * @return void
	 */
	public function testSendSuccess(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
		$this->messagingService->method('send')->willReturn(['status' => 'sent', 'messageId' => 'm1']);

		$response = $this->controller->send(contactId: 'c1', channel: 'sms', body: 'hi');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('sent', $response->getData()['status']);
		$this->assertSame('m1', $response->getData()['messageId']);
	}//end testSendSuccess()

	/**
	 * A consent-missing outcome maps to HTTP 422 (business refusal).
	 *
	 * @return void
	 */
	public function testSendConsentMissingMaps422(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
		$this->messagingService->method('send')->willReturn(['status' => 'consent-missing']);

		$response = $this->controller->send(contactId: 'c1', channel: 'whatsapp', templateId: 'tpl-1');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('consent-missing', $response->getData()['status']);
	}//end testSendConsentMissingMaps422()

	/**
	 * A failed outcome maps to HTTP 502 and never leaks a vendor error.
	 *
	 * @return void
	 */
	public function testSendFailureMaps502(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
		$this->messagingService->method('send')->willReturn(['status' => 'failed']);

		$response = $this->controller->send(contactId: 'c1', channel: 'sms', body: 'hi');
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertArrayNotHasKey('error', $response->getData());
	}//end testSendFailureMaps502()

	/**
	 * Consent recording requires evidence + legal basis.
	 *
	 * @return void
	 */
	public function testConsentRequiresEvidenceAndLegalBasis(): void {
		$this->signIn('agent-1');
		$response = $this->controller->consent(contactId: 'c1', channel: 'whatsapp', action: 'opt-in');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testConsentRequiresEvidenceAndLegalBasis()

	/**
	 * A valid opt-in is recorded and the new state returned.
	 *
	 * @return void
	 */
	public function testConsentOptInRecorded(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
		$this->consentService->expects($this->once())->method('recordOptIn');
		$this->consentService->method('latestState')->willReturn('opted-in');

		$response = $this->controller->consent(
			contactId: 'c1',
			channel: 'whatsapp',
			action: 'opt-in',
			source: 'manual',
			evidence: 'customer confirmed by phone',
			legalBasis: 'consent'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('recorded', $response->getData()['status']);
		$this->assertSame('opted-in', $response->getData()['state']);
	}//end testConsentOptInRecorded()

	/**
	 * testProvider returns 404 for an unknown provider.
	 *
	 * @return void
	 */
	public function testProviderNotFound(): void {
		$this->providerRepo->method('findById')->willReturn(null);
		$response = $this->controller->testProvider(id: 'nope');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testProviderNotFound()

	/**
	 * testProvider runs the connectivity test for a known provider.
	 *
	 * @return void
	 */
	public function testProviderRunsTest(): void {
		$this->providerRepo->method('findById')->willReturn(['uuid' => 'p1', 'sourceId' => 'messagebird-sms']);
		$this->messagingService->method('runProviderTest')->willReturn(['reachable' => true, 'mock' => true]);

		$response = $this->controller->testProvider(id: 'p1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['reachable']);
		$this->assertTrue($response->getData()['mock']);
	}//end testProviderRunsTest()

	// ------------------------------------------------------------------
	// preflight — GET /api/messaging/preflight/{contactId}
	// ------------------------------------------------------------------

	/**
	 * An anonymous preflight is refused with 401 and never loads a contact.
	 *
	 * @return void
	 */
	public function testPreflightRejectsAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->messagingService->expects($this->never())->method('preflight');

		$response = $this->controller->preflight(contactId: 'c1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['status' => 'unauthorized'], $response->getData());
	}//end testPreflightRejectsAnonymousCaller()

	/**
	 * A contact the caller cannot load is a 404 and the preflight facts are
	 * never computed — the per-object guard (no-admin-idor) runs first.
	 *
	 * @return void
	 */
	public function testPreflightRefusesAContactTheCallerCannotLoad(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(null);
		$this->messagingService->expects($this->never())->method('preflight');

		$response = $this->controller->preflight(contactId: 'c-not-mine');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['status' => 'not-found'], $response->getData());
	}//end testPreflightRefusesAContactTheCallerCannotLoad()

	/**
	 * An empty contactId is refused with 404 rather than answering a
	 * blanket preflight for no contact at all.
	 *
	 * @return void
	 */
	public function testPreflightRefusesAnEmptyContactId(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(null);

		$response = $this->controller->preflight(contactId: '');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['status' => 'not-found'], $response->getData());
	}//end testPreflightRefusesAnEmptyContactId()

	/**
	 * The happy path returns 200 with the four-key preflight envelope, and the
	 * facts are computed for the requested contact id.
	 *
	 * @return void
	 */
	public function testPreflightReturnsTheFourKeyFactsEnvelope(): void {
		$this->signIn('agent-1');
		$this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
		$this->messagingService->expects($this->once())
			->method('preflight')
			->with('c1')
			->willReturn(
				[
					'channels' => ['sms' => true, 'whatsapp' => false],
					'whatsappSessionOpen' => false,
					'consent' => ['sms' => 'opted-in', 'whatsapp' => 'unknown'],
					'templates' => [['id' => 'tpl-1', 'name' => 'appointment-reminder']],
				]
			);

		$response = $this->controller->preflight(contactId: 'c1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['channels', 'whatsappSessionOpen', 'consent', 'templates'],
			array_keys($data)
		);
		$this->assertTrue($data['channels']['sms']);
		$this->assertFalse($data['channels']['whatsapp']);
		$this->assertSame('opted-in', $data['consent']['sms']);
		$this->assertSame('tpl-1', $data['templates'][0]['id']);
	}//end testPreflightReturnsTheFourKeyFactsEnvelope()

	/**
	 * End-to-end through the REAL MessagingService + ConsentService: a SEEDED
	 * consent record for the contact must actually be FOUND.
	 *
	 * This is the "healthy 200 over nothing" probe. The consent lookup is an
	 * OpenRegister findAll(); if its register/schema were hoisted out of
	 * `filters` the call returns [] forever and preflight answers a cheerful
	 * `unknown` for every contact — which the send surface reads as "allowed",
	 * so an opted-OUT citizen would be messaged.
	 *
	 * @return void
	 */
	public function testPreflightFindsASeededConsentRecordThroughTheRealLookup(): void {
		$seenConfigs = [];
		$seeded = [
			[
				'contactId' => 'c1',
				'channel' => 'sms',
				'state' => 'opted-out',
				'recordedAt' => '2026-08-01T10:00:00Z',
			],
			[
				'contactId' => 'c1',
				'channel' => 'sms',
				'state' => 'opted-in',
				'recordedAt' => '2026-01-01T10:00:00Z',
			],
		];

		$objectService = new class($seenConfigs, $seeded) {
			/**
			 * @param array<int, array<string, mixed>> $seen Captured configs, by reference.
			 * @param array<int, array<string, mixed>> $seeded The seeded consent rows.
			 */
			public function __construct(
				private array &$seen,
				private array $seeded,
			) {
			}//end __construct()

			/**
			 * Capture the config and return the seeded rows.
			 *
			 * @param array<string, mixed> $config The findAll config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config = []): array {
				$this->seen[] = $config;
				return $this->seeded;
			}//end findAll()

			/**
			 * Resolve the one seeded contact so the per-object guard passes.
			 *
			 * @param string $id The object id.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed>|null The contact, or null.
			 */
			public function find(string $id, string $register = '', string $schema = ''): ?array {
				if ($id === 'c1') {
					return ['id' => 'c1', 'name' => 'Jan Jansen', 'phone' => '+31612345678'];
				}

				return null;
			}//end find()
		};

		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$cfg = [
					'register' => 'reg-1',
					'contact_schema' => 'schema-contact',
					'messagingConsentRecord_schema' => 'schema-consent',
				];
				return $cfg[$key] ?? $default;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$consent = new ConsentService($container, $appConfig, $logger);

		$whatsApp = $this->createMock(\OCA\Pipelinq\Service\WhatsAppAdapter::class);
		$whatsApp->method('isWithinSessionWindow')->willReturn(false);

		$providerRepo = $this->createMock(ChannelProviderRepository::class);
		$providerRepo->method('listActive')->willReturn([]);

		$service = new MessagingService(
			$container,
			$appConfig,
			$providerRepo,
			$this->createMock(\OCA\Pipelinq\Service\SmsAdapter::class),
			$whatsApp,
			$consent,
			$logger,
		);

		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('agent-1');
		$userSession->method('getUser')->willReturn($user);

		$controller = new MessagingController(
			$this->createMock(IRequest::class),
			$service,
			$providerRepo,
			$consent,
			$userSession,
		);

		$response = $controller->preflight(contactId: 'c1');
		$facts = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotEmpty($seenConfigs, 'the consent lookup never issued a findAll()');
		$this->assertArrayHasKey(
			'register',
			$seenConfigs[0]['filters'],
			'register must live INSIDE filters or OpenRegister silently returns []'
		);
		$this->assertArrayHasKey('schema', $seenConfigs[0]['filters']);
		$this->assertSame('c1', $seenConfigs[0]['filters']['contactId']);

		$this->assertSame(
			'opted-out',
			$facts['consent']['sms'],
			'the seeded opted-out consent record was not found — preflight would '
			. 'report "unknown", which the send surface reads as allowed'
		);
		$this->assertSame('unknown', $facts['consent']['whatsapp']);
		$this->assertSame(
			['channels', 'whatsappSessionOpen', 'consent', 'templates'],
			array_keys($facts)
		);
	}//end testPreflightFindsASeededConsentRecordThroughTheRealLookup()
}//end class
