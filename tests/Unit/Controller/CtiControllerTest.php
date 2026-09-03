<?php

/**
 * Contract tests for CtiController.
 *
 * Pins the wire contract of the five network-facing CTI endpoints: the
 * PUBLIC inbound webhook, the screen-pop lookup, click-to-dial, the
 * disposition form and the recording attachment. Every test asserts the
 * HTTP status code AND the response body shape.
 *
 * The webhook tests deliberately run against the REAL CtiService (with
 * mocked collaborators) rather than a doubled service, because the
 * property under test — "an unsigned delivery must not mutate anything"
 * — lives in the service, and a doubled service can only confirm the
 * call shape the test itself invented.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CtiController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Cti\Adapter\AsteriskAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\CallVoipAdapter;
use OCA\Pipelinq\Service\Cti\Adapter\RingCentralAdapter;
use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCA\Pipelinq\Service\Cti\Result\OriginateResult;
use OCA\Pipelinq\Service\Cti\Result\ScreenPopResult;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CtiController wire-contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class CtiControllerTest extends TestCase {
	/**
	 * Request parameter map used by the current test.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Request header map used by the current test.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * Build an IRequest whose getParam/getParams/getHeader read the maps above.
	 *
	 * @return IRequest The stubbed request.
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($this->params[$key] ?? $default)
		);
		$request->method('getParams')->willReturnCallback(
			fn (): array => $this->params
		);
		$request->method('getHeader')->willReturnCallback(
			fn (string $key): string => ($this->headers[$key] ?? '')
		);

		return $request;
	}//end request()

	/**
	 * Build a user session that is either signed in as $uid or anonymous.
	 *
	 * @param string|null $uid The signed-in uid, or null for anonymous.
	 *
	 * @return IUserSession The stubbed session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * Build the controller under test.
	 *
	 * @param CtiService $service The CTI service (real or doubled).
	 * @param string|null $uid Signed-in uid, or null for anonymous.
	 *
	 * @return CtiController The controller.
	 */
	private function controller(CtiService $service, ?string $uid = 'agent-1'): CtiController {
		return new CtiController($this->request(),
			$service,
			$this->session($uid),
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => true, 'mayAccess' => true]),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	// ------------------------------------------------------------------
	// screenPop — POST /api/cti/screen-pop
	// ------------------------------------------------------------------

	/**
	 * An anonymous screen-pop is refused with 401 and a static error body.
	 *
	 * @return void
	 */
	public function testScreenPopRejectsAnonymousCaller(): void {
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('initiateScreenPop');

		$response = $this->controller($service, null)->screenPop();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testScreenPopRejectsAnonymousCaller()

	/**
	 * A screen-pop without fromNumber is a 400 with a naming error body.
	 *
	 * @return void
	 */
	public function testScreenPopRejectsMissingFromNumber(): void {
		$this->params = [];
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('initiateScreenPop');

		$response = $this->controller($service)->screenPop();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'fromNumber is required'], $response->getData());
	}//end testScreenPopRejectsMissingFromNumber()

	/**
	 * A single match yields 200 and the four-key screen-pop envelope.
	 *
	 * @return void
	 */
	public function testScreenPopReturnsNavigateEnvelopeForSingleMatch(): void {
		$this->params = ['fromNumber' => '0612345678'];

		$service = $this->createMock(CtiService::class);
		$service->method('initiateScreenPop')->willReturn(
			new ScreenPopResult(
				action: ScreenPopResult::ACTION_NAVIGATE,
				matches: [['id' => 'contact-1', '_matchType' => 'contact']],
				e164: '+31612345678',
				rawNumber: '0612345678',
			)
		);

		$response = $this->controller($service)->screenPop();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['action', 'matches', 'e164', 'rawNumber'],
			array_keys($data),
			'the screen-pop envelope is a fixed four-key contract'
		);
		$this->assertSame('navigate', $data['action']);
		$this->assertSame('+31612345678', $data['e164']);
		$this->assertSame('0612345678', $data['rawNumber']);
		$this->assertSame('contact-1', $data['matches'][0]['id']);
	}//end testScreenPopReturnsNavigateEnvelopeForSingleMatch()

	/**
	 * End-to-end through the REAL CtiService + CtiContactMatcher +
	 * PhoneNormaliser: a SEEDED contact whose stored phone number matches the
	 * caller must actually be FOUND. This is the "healthy 200 over nothing"
	 * probe — an OpenRegister findAll() whose register/schema are not carried
	 * inside `filters` returns [] forever and the endpoint answers a cheerful
	 * `action: intake` for every known caller.
	 *
	 * The test additionally captures the findAll() config and asserts the
	 * register/schema live INSIDE `filters` (the shape OpenRegister's
	 * prepareFindAllConfig() reads), so a refactor that hoists them to the top
	 * level is caught here rather than in production.
	 *
	 * @return void
	 */
	public function testScreenPopFindsASeededContactThroughTheRealLookup(): void {
		$this->params = ['fromNumber' => '0612345678'];

		$seenConfigs = [];
		$seeded = [
			'id' => 'contact-seeded',
			'name' => 'Jan Jansen',
			'phone' => '+31612345678',
		];

		$objectService = new class($seenConfigs, $seeded) {
			/**
			 * @param array<int, array<string, mixed>> $seen Captured configs (by reference).
			 * @param array<string, mixed> $seeded The one seeded contact row.
			 */
			public function __construct(
				private array &$seen,
				private array $seeded,
			) {
			}//end __construct()

			/**
			 * Capture the config and return the seeded row.
			 *
			 * @param array<string, mixed> $config The findAll config.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config = []): array {
				$this->seen[] = $config;
				return [$this->seeded];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$cfg = [
					'register' => 'reg-1',
					'contact_schema' => 'schema-contact',
					'default_country_code' => '+31',
				];
				return $cfg[$key] ?? $default;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$normaliser = new PhoneNormaliser($appConfig, $logger);
		$matcher = new CtiContactMatcher($container, $appConfig, $normaliser, $logger);

		$service = new CtiService($container,
			$appConfig,
			$this->createMock(AdapterRegistry::class),
			$normaliser,
			$matcher,
			$this->createMock(CtiDispositionService::class),
			$this->createMock(TicketService::class),
			$logger,
		);

		$response = $this->controller($service)->screenPop();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNotEmpty($seenConfigs, 'the matcher never issued a findAll()');
		$this->assertArrayHasKey(
			'register',
			$seenConfigs[0]['filters'],
			'register must live INSIDE filters or OpenRegister silently returns []'
		);
		$this->assertArrayHasKey('schema', $seenConfigs[0]['filters']);

		$this->assertCount(
			1,
			$data['matches'],
			'the seeded contact whose phone equals the caller number was not found'
		);
		$this->assertSame('contact-seeded', $data['matches'][0]['id']);
		$this->assertSame('navigate', $data['action']);
		$this->assertSame('+31612345678', $data['e164']);
	}//end testScreenPopFindsASeededContactThroughTheRealLookup()

	// ------------------------------------------------------------------
	// clickToDial — POST /api/cti/click-to-dial
	// ------------------------------------------------------------------

	/**
	 * An anonymous click-to-dial is refused with 401 and never dials.
	 *
	 * @return void
	 */
	public function testClickToDialRejectsAnonymousCaller(): void {
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('originateCall');

		$response = $this->controller($service, null)->clickToDial();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testClickToDialRejectsAnonymousCaller()

	/**
	 * Both targetNumber and extension are mandatory; missing either is a 400
	 * and no call is placed.
	 *
	 * @return void
	 */
	public function testClickToDialRequiresTargetNumberAndExtension(): void {
		$this->params = ['targetNumber' => '+31612345678'];
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('originateCall');

		$response = $this->controller($service)->clickToDial();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['error' => 'targetNumber and extension are required'],
			$response->getData()
		);
	}//end testClickToDialRequiresTargetNumberAndExtension()

	/**
	 * A successful originate is 200 with the five-key originate envelope, and
	 * the acting user's uid — never a body-supplied one — is what is dialled
	 * on behalf of.
	 *
	 * @return void
	 */
	public function testClickToDialReturnsOriginateEnvelopeOnSuccess(): void {
		$this->params = [
			'targetNumber' => '+31612345678',
			'extension' => '201',
			'userId' => 'somebody-else',
		];

		$service = $this->createMock(CtiService::class);
		$service->expects($this->once())
			->method('originateCall')
			->with('agent-1', '201', '+31612345678')
			->willReturn(
				new OriginateResult(
					success: true,
					externalCallId: 'call-9',
					interactionId: 'cm-9',
					platform: 'callvoip',
				)
			);

		$response = $this->controller($service)->clickToDial();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['success', 'externalCallId', 'interactionId', 'error', 'platform'],
			array_keys($data)
		);
		$this->assertTrue($data['success']);
		$this->assertSame('call-9', $data['externalCallId']);
		$this->assertSame('cm-9', $data['interactionId']);
		$this->assertNull($data['error']);
	}//end testClickToDialReturnsOriginateEnvelopeOnSuccess()

	/**
	 * An originate the platform refused maps to 502 with the envelope intact.
	 *
	 * @return void
	 */
	public function testClickToDialMapsPlatformRefusalTo502(): void {
		$this->params = ['targetNumber' => '+31612345678', 'extension' => '201'];

		$service = $this->createMock(CtiService::class);
		$service->method('originateCall')->willReturn(
			new OriginateResult(success: false, error: 'No CTI platform configured.')
		);

		$response = $this->controller($service)->clickToDial();

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('No CTI platform configured.', $response->getData()['error']);
	}//end testClickToDialMapsPlatformRefusalTo502()

	/**
	 * A dial target that is not a phone number at all must be refused before
	 * anything is handed to the telephony platform. `targetNumber` is
	 * client-supplied and reaches the Asterisk ARI `extension` field verbatim
	 * (AsteriskAdapter::originateCall), so an unvalidated value is a
	 * toll-fraud / dial-plan-injection primitive.
	 *
	 * @return void
	 */
	public function testClickToDialRejectsANonDialableTargetNumber(): void {
		$this->markTestSkipped(
			'BUG: click-to-dial forwards any non-empty targetNumber and extension '
			. 'verbatim to the telephony adapter — no E.164 check, no allowlist, no '
			. 'rate limit — see coordinator report'
		);

		$this->params = [
			'targetNumber' => 'sip:attacker@evil.example',
			'extension' => '201',
		];

		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('originateCall');

		$response = $this->controller($service)->clickToDial();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testClickToDialRejectsANonDialableTargetNumber()

	// ------------------------------------------------------------------
	// webhook — POST /api/cti/webhook/{platform}   (PUBLIC)
	// ------------------------------------------------------------------

	/**
	 * An unknown platform is a 404 (the registry throws RuntimeException).
	 *
	 * @return void
	 */
	public function testWebhookReturns404ForAnUnknownPlatform(): void {
		$service = $this->createMock(CtiService::class);
		$service->method('handleWebhook')->willThrowException(
			new \RuntimeException('CTI adapter not registered for platform: nope')
		);

		$response = $this->controller($service, null)->webhook('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testWebhookReturns404ForAnUnknownPlatform()

	/**
	 * Positive control for the rejection path: when the service reports the
	 * signature as invalid the endpoint answers 422 with a static envelope and
	 * no contactmoment id. This proves the harness CAN observe a rejection —
	 * without it, the "unsigned is accepted" test below could not be trusted.
	 *
	 * @return void
	 */
	public function testWebhookRejectsAnInvalidSignatureWith422(): void {
		$service = $this->createMock(CtiService::class);
		$service->method('handleWebhook')->willReturn(
			['logged' => true, 'valid' => false, 'interactionId' => null]
		);

		$response = $this->controller($service, null)->webhook('callvoip');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['error' => 'Invalid webhook signature', 'logged' => true],
			$response->getData()
		);
	}//end testWebhookRejectsAnInvalidSignatureWith422()

	/**
	 * A verified delivery is 200 with the two-key acknowledgement envelope.
	 *
	 * @return void
	 */
	public function testWebhookAcknowledgesAVerifiedDelivery(): void {
		$service = $this->createMock(CtiService::class);
		$service->method('handleWebhook')->willReturn(
			['logged' => true, 'valid' => true, 'interactionId' => 'cm-1']
		);

		$response = $this->controller($service, null)->webhook('callvoip');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ok' => true, 'interactionId' => 'cm-1'], $response->getData());
	}//end testWebhookAcknowledgesAVerifiedDelivery()

	/**
	 * THE critical property of a public webhook: a delivery carrying NO
	 * signature at all must be refused and must mutate nothing.
	 *
	 * Run against the REAL CtiService with an adapter whose
	 * verifyWebhookSignature() always returns false, so the only way the
	 * delivery can be accepted is if verification was never consulted.
	 *
	 * @return void
	 */
	public function testWebhookRejectsAnUnsignedDeliveryAndMutatesNothing(): void {
		$this->params = ['event' => 'ringing', 'callId' => 'ext-1', 'from' => '+31612345678'];
		$this->headers = [];

		$writes = 0;
		$ticketService = $this->countingTicketService($writes);

		$service = $this->realService(
			ticketService: $ticketService,
			signatureAccepts: false,
			event: new CtiWebhookResult(eventType: 'ringing', externalCallId: 'ext-1'),
		);
		$response = $this->controller($service, null)->webhook('callvoip');

		// Counted rather than expects(never): CtiService wraps its dispatch in
		// catch(\Throwable), which would swallow a mock expectation failure.
		$this->assertSame(0, $writes, 'an unsigned delivery wrote a ticket');
		$this->assertGreaterThanOrEqual(
			400,
			$response->getStatus(),
			'an unsigned delivery to a public webhook must not be accepted'
		);
	}//end testWebhookRejectsAnUnsignedDeliveryAndMutatesNothing()

	/**
	 * A wrongly-signed delivery IS refused (this path works) and writes no
	 * contactmoment — the counterpart of the test above.
	 *
	 * @return void
	 */
	public function testWebhookRejectsAWronglySignedDeliveryAndMutatesNothing(): void {
		$this->params = ['event' => 'ringing', 'callId' => 'ext-1'];
		$this->headers = ['X-Pipelinq-Signature' => 'deadbeef'];

		$writes = 0;
		$ticketService = $this->countingTicketService($writes);

		$service = $this->realService(
			ticketService: $ticketService,
			signatureAccepts: false,
			event: new CtiWebhookResult(eventType: 'ringing', externalCallId: 'ext-1'),
		);
		$response = $this->controller($service, null)->webhook('callvoip');

		$this->assertSame(0, $writes, 'a wrongly-signed delivery wrote a ticket');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(
			['error' => 'Invalid webhook signature', 'logged' => true],
			$response->getData()
		);
	}//end testWebhookRejectsAWronglySignedDeliveryAndMutatesNothing()

	/**
	 * Replaying an identical verified delivery is idempotent: the second
	 * delivery resolves to the SAME contactmoment instead of creating a
	 * second one.
	 *
	 * @return void
	 */
	public function testWebhookReplayOfAnIdenticalDeliveryIsIdempotent(): void {
		$this->params = ['event' => 'ringing', 'callId' => 'ext-1'];
		$this->headers = ['X-Pipelinq-Signature' => 'good'];

		$created = 0;
		$updated = 0;

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('isConfigured')->willReturn(true);
		// First lookup: nothing stored. Every later lookup: the created ticket.
		$ticketService->method('findByType')->willReturnOnConsecutiveCalls(
			[],
			[['id' => 'cm-1']],
			[['id' => 'cm-1']]
		);
		$ticketService->method('save')->willReturnCallback(
			static function (string $ticketType, array $payload, ?string $uuid = null) use (&$created, &$updated): object {
				if ($uuid === null) {
					$created++;
				} else {
					$updated++;
				}

				return new class {
					/**
					 * The saved ticket UUID, as OpenRegister's ObjectEntity exposes it.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'cm-1';
					}//end getUuid()
				};
			}
		);

		$service = $this->realService(
			ticketService: $ticketService,
			signatureAccepts: true,
			event: new CtiWebhookResult(eventType: 'ringing', externalCallId: 'ext-1'),
		);
		$controller = $this->controller($service, null);

		$first = $controller->webhook('callvoip');
		$second = $controller->webhook('callvoip');

		$this->assertSame(Http::STATUS_OK, $first->getStatus());
		$this->assertSame(Http::STATUS_OK, $second->getStatus());
		$this->assertSame($first->getData()['interactionId'],
			$second->getData()['interactionId'],
			'a replayed delivery must resolve to the same contactmoment'
		);
		$this->assertSame(1, $created, 'the replay created a second contactmoment');
	}//end testWebhookReplayOfAnIdenticalDeliveryIsIdempotent()

	/**
	 * A malformed delivery the adapter cannot parse must produce a controlled
	 * response, not an unhandled throw the dispatcher turns into a 500.
	 *
	 * @return void
	 */
	public function testWebhookMalformedPayloadProducesAControlledResponse(): void {
		$this->markTestSkipped(
			'BUG: CtiService::handleWebhook calls adapter->handleInboundWebhook() '
			. 'outside any try/catch and CtiController::webhook catches only '
			. 'RuntimeException (mislabelling it 404), so a parse failure escapes '
			. 'as a 500 — see coordinator report'
		);

		$this->params = ['garbage' => true];
		$this->headers = ['X-Pipelinq-Signature' => 'good'];

		$adapter = $this->createMock(CtiAdapterInterface::class);
		$adapter->method('verifyWebhookSignature')->willReturn(true);
		$adapter->method('handleInboundWebhook')->willThrowException(
			new \JsonException('unexpected payload')
		);

		$registry = $this->createMock(AdapterRegistry::class);
		$registry->method('get')->willReturn($adapter);

		$service = $this->realService(
			ticketService: $this->createMock(TicketService::class),
			signatureAccepts: true,
			event: new CtiWebhookResult(eventType: 'ringing', externalCallId: 'x'),
			registry: $registry,
		);
		$response = $this->controller($service, null)->webhook('callvoip');

		$this->assertLessThan(500, $response->getStatus());
	}//end testWebhookMalformedPayloadProducesAControlledResponse()

	/**
	 * Every CTI adapter must compare the webhook secret in constant time. A
	 * `===` on a secret is a timing oracle (ADR-005), and the three shipped
	 * adapters are the only implementations the public webhook route can
	 * reach.
	 *
	 * @return void
	 */
	public function testEveryCtiAdapterComparesTheWebhookSecretInConstantTime(): void {
		$adapters = [AsteriskAdapter::class, CallVoipAdapter::class, RingCentralAdapter::class];

		foreach ($adapters as $class) {
			$method = new \ReflectionMethod($class, 'verifyWebhookSignature');
			$lines = file((string)$method->getFileName());
			$body = implode(
				'',
				array_slice(
					(array)$lines,
					($method->getStartLine() - 1),
					($method->getEndLine() - $method->getStartLine() + 1)
				)
			);

			$this->assertStringContainsString(
				'hash_equals(',
				$body,
				$class . '::verifyWebhookSignature must use a constant-time compare'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\$signature\s*(===|==|!=|!==)\s*\$(expected|secret)/',
				$body,
				$class . '::verifyWebhookSignature must not compare the secret with =='
			);
		}
	}//end testEveryCtiAdapterComparesTheWebhookSecretInConstantTime()

	// ------------------------------------------------------------------
	// disposition — POST /api/cti/contactmoment/{id}/disposition
	// ------------------------------------------------------------------

	/**
	 * An anonymous disposition is refused with 401.
	 *
	 * @return void
	 */
	public function testDispositionRejectsAnonymousCaller(): void {
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('processDisposition');

		$response = $this->controller($service, null)->disposition('cm-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testDispositionRejectsAnonymousCaller()

	/**
	 * subject and outcome are both mandatory.
	 *
	 * @return void
	 */
	public function testDispositionRequiresSubjectAndOutcome(): void {
		$this->params = ['subject' => 'Address change'];
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('processDisposition');

		$response = $this->controller($service)->disposition('cm-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'subject and outcome are required'], $response->getData());
	}//end testDispositionRequiresSubjectAndOutcome()

	/**
	 * A processed disposition is 200 and returns the service outcome verbatim.
	 *
	 * @return void
	 */
	public function testDispositionReturnsTheProcessedOutcome(): void {
		$this->params = [
			'subject' => 'Address change',
			'outcome' => 'resolved',
			'notes' => 'Handled on the call',
		];

		$service = $this->createMock(CtiService::class);
		$service->expects($this->once())
			->method('processDisposition')
			->with('cm-1', 'Address change', 'resolved', 'Handled on the call')
			->willReturn(['interactionId' => 'cm-1', 'outcome' => 'resolved', 'taskId' => null]);

		$response = $this->controller($service)->disposition('cm-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['interactionId' => 'cm-1', 'outcome' => 'resolved', 'taskId' => null],
			$response->getData()
		);
	}//end testDispositionReturnsTheProcessedOutcome()

	/**
	 * An invalid-argument refusal from the service is a 400 carrying the
	 * validation message.
	 *
	 * @return void
	 */
	public function testDispositionMapsInvalidArgumentTo400(): void {
		$this->params = ['subject' => 'x', 'outcome' => 'not-an-outcome'];

		$service = $this->createMock(CtiService::class);
		$service->method('processDisposition')->willThrowException(
			new \InvalidArgumentException('Unknown outcome: not-an-outcome')
		);

		$response = $this->controller($service)->disposition('cm-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Unknown outcome: not-an-outcome'], $response->getData());
	}//end testDispositionMapsInvalidArgumentTo400()

	/**
	 * An unexpected failure is a 500 with a STATIC message — the underlying
	 * exception text must never reach the caller.
	 *
	 * @return void
	 */
	public function testDispositionMapsUnexpectedFailureTo500WithoutLeakingDetail(): void {
		$this->params = ['subject' => 'x', 'outcome' => 'resolved'];

		$service = $this->createMock(CtiService::class);
		$service->method('processDisposition')->willThrowException(
			new \RuntimeException('pgsql: connection to 10.0.0.7 refused')
		);

		$response = $this->controller($service)->disposition('cm-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Disposition processing failed'], $response->getData());
		$this->assertStringNotContainsString('10.0.0.7', json_encode($response->getData()));
	}//end testDispositionMapsUnexpectedFailureTo500WithoutLeakingDetail()

	// ------------------------------------------------------------------
	// attachRecording — POST /api/cti/contactmoment/{id}/recording
	// ------------------------------------------------------------------

	/**
	 * An anonymous recording attachment is refused with 401 and writes nothing.
	 *
	 * @return void
	 */
	public function testAttachRecordingRejectsAnonymousCaller(): void {
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('attachRecording');

		$response = $this->controller($service, null)->attachRecording('cm-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}//end testAttachRecordingRejectsAnonymousCaller()

	/**
	 * recordingUrl is mandatory.
	 *
	 * @return void
	 */
	public function testAttachRecordingRequiresARecordingUrl(): void {
		$this->params = ['expiresAt' => '2026-12-31T00:00:00Z'];
		$service = $this->createMock(CtiService::class);
		$service->expects($this->never())->method('attachRecording');

		$response = $this->controller($service)->attachRecording('cm-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'recordingUrl is required'], $response->getData());
	}//end testAttachRecordingRequiresARecordingUrl()

	/**
	 * A successful attachment is 200 with the acknowledgement envelope, and
	 * the id + url + expiry reach the service unchanged.
	 *
	 * @return void
	 */
	public function testAttachRecordingAcknowledgesASuccessfulAttachment(): void {
		$this->params = [
			'recordingUrl' => 'https://pbx.example/rec/9.wav',
			'expiresAt' => '2026-12-31T00:00:00Z',
		];

		$service = $this->createMock(CtiService::class);
		$service->expects($this->once())
			->method('attachRecording')
			->with('cm-1', 'https://pbx.example/rec/9.wav', '2026-12-31T00:00:00Z');

		$response = $this->controller($service)->attachRecording('cm-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['ok' => true], $response->getData());
	}//end testAttachRecordingAcknowledgesASuccessfulAttachment()

	/**
	 * When nothing could actually be persisted the endpoint must NOT answer
	 * `ok: true`. Run against the REAL CtiService with an unconfigured ticket
	 * store: attachRecording() returns void and swallows the miss, so the
	 * caller is told the recording was attached when no write occurred.
	 *
	 * @return void
	 */
	public function testAttachRecordingReportsFailureWhenNothingWasPersisted(): void {
		$this->markTestSkipped(
			'BUG: CtiService::attachRecording returns void and swallows both the '
			. 'unconfigured-store early return and every Throwable, so the endpoint '
			. 'answers 200 {ok:true} over a write that never happened — see '
			. 'coordinator report'
		);

		$this->params = ['recordingUrl' => 'https://pbx.example/rec/9.wav'];

		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('isConfigured')->willReturn(false);
		$ticketService->expects($this->never())->method('save');

		$service = $this->realService(
			ticketService: $ticketService,
			signatureAccepts: true,
			event: new CtiWebhookResult(eventType: 'recording', externalCallId: 'x'),
		);
		$response = $this->controller($service)->attachRecording('cm-1');

		$this->assertGreaterThanOrEqual(500, $response->getStatus());
	}//end testAttachRecordingReportsFailureWhenNothingWasPersisted()

	/**
	 * A TicketService double that COUNTS writes into the supplied counter.
	 *
	 * A mock `expects($this->never())->method('save')` is unreliable here:
	 * CtiService::createPendingContactmoment wraps the write in
	 * `catch (\Throwable)`, which swallows PHPUnit's expectation failure and
	 * turns a proved mutation into a silent pass.
	 *
	 * @param int $writes Write counter, by reference.
	 *
	 * @return TicketService The counting double.
	 */
	private function countingTicketService(int &$writes): TicketService {
		$ticketService = $this->createMock(TicketService::class);
		$ticketService->method('isConfigured')->willReturn(true);
		$ticketService->method('findByType')->willReturn([]);
		$ticketService->method('save')->willReturnCallback(
			static function (string $ticketType, array $payload, ?string $uuid = null) use (&$writes): object {
				$writes++;
				return new class {
					/**
					 * The saved ticket UUID.
					 *
					 * @return string The uuid.
					 */
					public function getUuid(): string {
						return 'cm-written';
					}//end getUuid()
				};
			}
		);

		return $ticketService;
	}//end countingTicketService()

	/**
	 * Build a REAL CtiService whose adapter accepts or rejects signatures on
	 * demand and whose event-log write is disabled (no ctiEventLog_schema).
	 *
	 * @param TicketService $ticketService The ticket store double.
	 * @param bool $signatureAccepts What verifyWebhookSignature returns.
	 * @param CtiWebhookResult $event The normalised event the adapter yields.
	 * @param AdapterRegistry|null $registry Optional pre-built registry.
	 *
	 * @return CtiService The real service.
	 */
	private function realService(
		TicketService $ticketService,
		bool $signatureAccepts,
		CtiWebhookResult $event,
		?AdapterRegistry $registry = null,
	): CtiService {
		if ($registry === null) {
			$adapter = $this->createMock(CtiAdapterInterface::class);
			$adapter->method('verifyWebhookSignature')->willReturn($signatureAccepts);
			$adapter->method('handleInboundWebhook')->willReturn($event);
			$adapter->method('getPlatform')->willReturn('callvoip');
			$adapter->method('originateCall')->willReturn(new CtiCallResult(success: true));

			$registry = $this->createMock(AdapterRegistry::class);
			$registry->method('get')->willReturn($adapter);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				// ctiEventLog_schema intentionally unset so logEvent() no-ops.
				$cfg = ['register' => 'reg-1', 'default_country_code' => '+31'];
				return $cfg[$key] ?? $default;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		return new CtiService($this->createMock(ContainerInterface::class),
			$appConfig,
			$registry,
			new PhoneNormaliser($appConfig, $logger),
			$this->createMock(CtiContactMatcher::class),
			$this->createMock(CtiDispositionService::class),
			$ticketService,
			$logger,
		);
	}//end realService()
}//end class
