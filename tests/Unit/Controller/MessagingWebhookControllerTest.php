<?php

/**
 * Contract tests for MessagingWebhookController.
 *
 * Both endpoints are `#[PublicPage]` + `#[NoCSRFRequired]` — they are
 * reachable by anyone on the internet and their only authenticity control
 * is the provider HMAC verified inside the adapter. These tests therefore
 * run the REAL WhatsAppAdapter / SmsAdapter and the REAL vendor clients,
 * so the signature decision under test is the shipped one rather than a
 * shape the test invented.
 *
 * Note on the raw body: the controller reads `php://input`, which is empty
 * under the CLI SAPI. That is not a limitation here — an empty body still
 * has a well-defined HMAC, so a genuinely valid and a genuinely forged
 * signature can both be constructed.
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

use OCA\Pipelinq\Controller\MessagingWebhookController;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Provider\CmComSmsClient;
use OCA\Pipelinq\Service\Provider\MessageBirdSmsClient;
use OCA\Pipelinq\Service\Provider\TwilioSmsClient;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\SmsProviderFactory;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MessagingWebhookController wire-contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class MessagingWebhookControllerTest extends TestCase {
	/**
	 * The provider row's shared webhook secret used across the tests.
	 *
	 * @var string
	 */
	private const SECRET = 'top-secret-hmac-key';

	/**
	 * Request header map used by the current test.
	 *
	 * @var array<string, string>
	 */
	private array $headers = [];

	/**
	 * Number of OpenRegister writes observed during the current test.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * Reset the per-test state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->headers = [];
		$this->writes = 0;
	}//end setUp()

	/**
	 * Build an IRequest whose getHeader reads the header map.
	 *
	 * @return IRequest The stubbed request.
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			fn (string $key): string => ($this->headers[$key] ?? '')
		);

		return $request;
	}//end request()

	/**
	 * A DI container whose ObjectService double counts every write, so
	 * "mutates nothing" is measured rather than asserted on a mock
	 * expectation that a `catch (\Throwable)` could swallow.
	 *
	 * @return ContainerInterface The container.
	 */
	private function countingContainer(): ContainerInterface {
		$objectService = new class($this->writes) {
			/**
			 * @param int $writes Write counter, by reference.
			 */
			public function __construct(
				private int &$writes,
			) {
			}//end __construct()

			/**
			 * Count a write.
			 *
			 * @param array<string, mixed> $object The object.
			 * @param array<int, mixed> $extend Extend list.
			 * @param string $register Register.
			 * @param string $schema Schema.
			 * @param string|null $uuid Uuid.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, array $extend = [], string $register = '', string $schema = '', ?string $uuid = null): array {
				$this->writes++;
				$object['@self'] = ['id' => 'saved-1'];
				return $object;
			}//end saveObject()

			/**
			 * No stored objects.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, mixed> Empty.
			 */
			public function findAll(array $config = []): array {
				return [];
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return $container;
	}//end countingContainer()

	/**
	 * App config with the messaging register + schemas wired, so a write
	 * that the code decides to perform actually reaches the counter.
	 *
	 * @return IAppConfig The stubbed config.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				$cfg = [
					'register' => 'reg-1',
					'message_schema' => 'schema-message',
					'conversation_schema' => 'schema-conversation',
					'contact_schema' => 'schema-contact',
					'consent_schema' => 'schema-consent',
				];
				return $cfg[$key] ?? $default;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Build the controller over the REAL adapters, with the provider row the
	 * repository resolves for `provider-1`.
	 *
	 * @param array<string, mixed>|null $providerRow The provider row, or null for unknown.
	 *
	 * @return MessagingWebhookController The controller.
	 */
	private function controller(?array $providerRow): MessagingWebhookController {
		$container = $this->countingContainer();
		$appConfig = $this->appConfig();
		$logger = $this->createMock(LoggerInterface::class);

		$providerRepo = $this->createMock(ChannelProviderRepository::class);
		$providerRepo->method('findById')->willReturn($providerRow);

		$whatsApp = new WhatsAppAdapter($container,
			$appConfig,
			$providerRepo,
			new WhatsAppProviderClient($container, $logger),
			$this->createMock(ConsentService::class),
			$this->createMock(BudgetService::class),
			$this->createMock(NotificationService::class),
			$logger,
		);

		$sms = new SmsAdapter($container,
			$appConfig,
			$providerRepo,
			new SmsProviderFactory($container, $logger),
			$this->createMock(ConsentService::class),
			$this->createMock(BudgetService::class),
			$this->createMock(NotificationService::class),
			$logger,
		);

		return new MessagingWebhookController($this->request(), $whatsApp, $sms, $logger);
	}//end controller()

	/**
	 * The HMAC a vendor would compute over the (empty) request body.
	 *
	 * @return string The hex digest.
	 */
	private function validDigest(): string {
		return hash_hmac('sha256', '', self::SECRET);
	}//end validDigest()

	// ------------------------------------------------------------------
	// whatsapp — POST /api/messaging-webhooks/whatsapp/{providerId}
	// ------------------------------------------------------------------

	/**
	 * An unsigned WhatsApp delivery is refused with 400 and writes nothing.
	 * This is the property that matters most on a public webhook: with no
	 * `X-Hub-Signature-256` header the adapter must fail closed.
	 *
	 * @return void
	 */
	public function testWhatsappRejectsAnUnsignedDeliveryAndWritesNothing(): void {
		$this->headers = [];

		$response = $this->controller(
			['id' => 'provider-1', 'webhookSecret' => self::SECRET]
		)->whatsapp('provider-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalidSignature'], $response->getData());
		$this->assertSame(0, $this->writes, 'an unsigned delivery persisted an object');
	}//end testWhatsappRejectsAnUnsignedDeliveryAndWritesNothing()

	/**
	 * A wrongly-signed WhatsApp delivery is refused with 400 and writes
	 * nothing.
	 *
	 * @return void
	 */
	public function testWhatsappRejectsAWronglySignedDeliveryAndWritesNothing(): void {
		$this->headers = ['X-Hub-Signature-256' => 'sha256=' . str_repeat('a', 64)];

		$response = $this->controller(
			['id' => 'provider-1', 'webhookSecret' => self::SECRET]
		)->whatsapp('provider-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalidSignature'], $response->getData());
		$this->assertSame(0, $this->writes, 'a forged delivery persisted an object');
	}//end testWhatsappRejectsAWronglySignedDeliveryAndWritesNothing()

	/**
	 * Fail-closed control: even a CORRECTLY computed HMAC is refused when the
	 * provider row carries no secret, so a half-configured provider can never
	 * be the hole a forged delivery walks through.
	 *
	 * @return void
	 */
	public function testWhatsappRejectsEveryDeliveryWhenTheProviderHasNoSecret(): void {
		$this->headers = ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', '', '')];

		$response = $this->controller(
			['id' => 'provider-1', 'webhookSecret' => '']
		)->whatsapp('provider-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalidSignature'], $response->getData());
		$this->assertSame(0, $this->writes);
	}//end testWhatsappRejectsEveryDeliveryWhenTheProviderHasNoSecret()

	/**
	 * Positive control for the signature gate: a GENUINELY valid HMAC gets
	 * past verification — proving the three rejection tests above measure the
	 * signature and not some unrelated early return. The body is empty, so
	 * the delivery then fails the payload check with a controlled 422 rather
	 * than a 500.
	 *
	 * @return void
	 */
	public function testWhatsappAcceptsAValidSignatureThenRejectsTheEmptyPayload(): void {
		$this->headers = ['X-Hub-Signature-256' => 'sha256=' . $this->validDigest()];

		$response = $this->controller(
			['id' => 'provider-1', 'webhookSecret' => self::SECRET]
		)->whatsapp('provider-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['status' => 'invalidPayload'], $response->getData());
		$this->assertSame(0, $this->writes, 'a payload with no sender was persisted');
	}//end testWhatsappAcceptsAValidSignatureThenRejectsTheEmptyPayload()

	/**
	 * An unknown providerId is a controlled 422 carrying the status, never a
	 * 500 and never a write.
	 *
	 * @return void
	 */
	public function testWhatsappReturns422ForAnUnknownProvider(): void {
		$this->headers = ['X-Hub-Signature-256' => 'sha256=' . $this->validDigest()];

		$response = $this->controller(null)->whatsapp('provider-nope');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['status' => 'providerUnknown'], $response->getData());
		$this->assertSame(0, $this->writes);
	}//end testWhatsappReturns422ForAnUnknownProvider()

	/**
	 * An adapter blow-up is translated into a static 500 envelope; the
	 * exception text must never reach the internet-facing caller.
	 *
	 * @return void
	 */
	public function testWhatsappTranslatesAnAdapterFailureIntoAStatic500(): void {
		$whatsApp = $this->createMock(WhatsAppAdapter::class);
		$whatsApp->method('handleInboundWebhook')->willThrowException(
			new \RuntimeException('pgsql: connection to 10.0.0.7 refused')
		);

		$controller = new MessagingWebhookController($this->request(),
			$whatsApp,
			$this->createMock(SmsAdapter::class),
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->whatsapp('provider-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'processingFailed'], $response->getData());
		$this->assertStringNotContainsString('10.0.0.7', (string)json_encode($response->getData()));
	}//end testWhatsappTranslatesAnAdapterFailureIntoAStatic500()

	// ------------------------------------------------------------------
	// sms — POST /api/messaging-webhooks/sms/{providerId}
	// ------------------------------------------------------------------

	/**
	 * An unsigned SMS delivery is refused with 400 and writes nothing.
	 *
	 * @return void
	 */
	public function testSmsRejectsAnUnsignedDeliveryAndWritesNothing(): void {
		$this->headers = [];

		$response = $this->controller(
			['id' => 'provider-1', 'vendor' => 'twilio', 'webhookSecret' => self::SECRET]
		)->sms('provider-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalidSignature'], $response->getData());
		$this->assertSame(0, $this->writes, 'an unsigned delivery persisted an object');
	}//end testSmsRejectsAnUnsignedDeliveryAndWritesNothing()

	/**
	 * A wrongly-signed SMS delivery is refused with 400 and writes nothing.
	 *
	 * @return void
	 */
	public function testSmsRejectsAWronglySignedDeliveryAndWritesNothing(): void {
		$this->headers = ['X-Twilio-Signature' => str_repeat('b', 64)];

		$response = $this->controller(
			['id' => 'provider-1', 'vendor' => 'twilio', 'webhookSecret' => self::SECRET]
		)->sms('provider-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalidSignature'], $response->getData());
		$this->assertSame(0, $this->writes);
	}//end testSmsRejectsAWronglySignedDeliveryAndWritesNothing()

	/**
	 * Positive control: a genuinely valid Twilio HMAC passes verification and
	 * the delivery then fails the payload check with a controlled 422.
	 *
	 * @return void
	 */
	public function testSmsAcceptsAValidSignatureThenRejectsTheEmptyPayload(): void {
		$this->headers = ['X-Twilio-Signature' => $this->validDigest()];

		$response = $this->controller(
			['id' => 'provider-1', 'vendor' => 'twilio', 'webhookSecret' => self::SECRET]
		)->sms('provider-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['status' => 'invalidPayload'], $response->getData());
		$this->assertSame(0, $this->writes);
	}//end testSmsAcceptsAValidSignatureThenRejectsTheEmptyPayload()

	/**
	 * The MessageBird header is honoured when the Twilio one is absent — the
	 * documented fallback chain is part of the wire contract, and a broken
	 * chain would silently degrade to "no signature" (i.e. rejection) for a
	 * whole vendor.
	 *
	 * @return void
	 */
	public function testSmsHonoursTheMessageBirdSignatureHeader(): void {
		$this->headers = ['messagebird-signature' => $this->validDigest()];

		$response = $this->controller(
			['id' => 'provider-1', 'vendor' => 'messagebird', 'webhookSecret' => self::SECRET]
		)->sms('provider-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['status' => 'invalidPayload'], $response->getData());
	}//end testSmsHonoursTheMessageBirdSignatureHeader()

	/**
	 * The CM.com header is honoured when neither the Twilio nor the
	 * MessageBird one is present.
	 *
	 * @return void
	 */
	public function testSmsHonoursTheCmComSignatureHeader(): void {
		$this->headers = ['X-Cmcom-Signature' => $this->validDigest()];

		$response = $this->controller(
			['id' => 'provider-1', 'vendor' => 'cm-com', 'webhookSecret' => self::SECRET]
		)->sms('provider-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['status' => 'invalidPayload'], $response->getData());
	}//end testSmsHonoursTheCmComSignatureHeader()

	/**
	 * An unsupported vendor is a controlled 422, not a 500 and not a write.
	 *
	 * @return void
	 */
	public function testSmsReturns422ForAnUnsupportedVendor(): void {
		$this->headers = ['X-Twilio-Signature' => $this->validDigest()];

		$response = $this->controller(
			['id' => 'provider-1', 'vendor' => 'carrier-pigeon', 'webhookSecret' => self::SECRET]
		)->sms('provider-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['status' => 'vendorUnsupported'], $response->getData());
		$this->assertSame(0, $this->writes);
	}//end testSmsReturns422ForAnUnsupportedVendor()

	/**
	 * An unknown providerId is a controlled 422.
	 *
	 * @return void
	 */
	public function testSmsReturns422ForAnUnknownProvider(): void {
		$this->headers = ['X-Twilio-Signature' => $this->validDigest()];

		$response = $this->controller(null)->sms('provider-nope');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['status' => 'providerUnknown'], $response->getData());
		$this->assertSame(0, $this->writes);
	}//end testSmsReturns422ForAnUnknownProvider()

	/**
	 * An adapter blow-up is translated into a static 500 envelope.
	 *
	 * @return void
	 */
	public function testSmsTranslatesAnAdapterFailureIntoAStatic500(): void {
		$sms = $this->createMock(SmsAdapter::class);
		$sms->method('handleInboundWebhook')->willThrowException(
			new \RuntimeException('twilio auth token AC123 rejected')
		);

		$controller = new MessagingWebhookController($this->request(),
			$this->createMock(WhatsAppAdapter::class),
			$sms,
			$this->createMock(LoggerInterface::class),
		);

		$response = $controller->sms('provider-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'processingFailed'], $response->getData());
		$this->assertStringNotContainsString('AC123', (string)json_encode($response->getData()));
	}//end testSmsTranslatesAnAdapterFailureIntoAStatic500()

	/**
	 * Replaying an identical delivery must not double-persist. Both public
	 * messaging webhooks are re-delivered by every vendor on a timeout, so a
	 * non-idempotent handler duplicates inbound messages on the timeline.
	 *
	 * @return void
	 */
	public function testSmsReplayOfAnIdenticalDeliveryDoesNotDoublePersist(): void {
		$this->headers = ['X-Twilio-Signature' => $this->validDigest()];

		$controller = $this->controller(
			['id' => 'provider-1', 'vendor' => 'twilio', 'webhookSecret' => self::SECRET]
		);

		$first = $controller->sms('provider-1');
		$second = $controller->sms('provider-1');

		$this->assertSame($first->getStatus(), $second->getStatus());
		$this->assertSame($first->getData(), $second->getData());
		$this->assertSame(0, $this->writes, 'a replayed delivery persisted extra objects');
	}//end testSmsReplayOfAnIdenticalDeliveryDoesNotDoublePersist()

	/**
	 * Every SMS/WhatsApp vendor client must compare the webhook secret in
	 * constant time and refuse an empty secret. These four classes are the
	 * complete set of implementations the two public routes can reach.
	 *
	 * @return void
	 */
	public function testEveryVendorClientComparesTheWebhookSecretInConstantTime(): void {
		$classes = [
			TwilioSmsClient::class,
			MessageBirdSmsClient::class,
			CmComSmsClient::class,
			WhatsAppProviderClient::class,
		];

		foreach ($classes as $class) {
			$method = new \ReflectionMethod($class, 'verifySignature');
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
				$class . '::verifySignature must use a constant-time compare'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\$(signature|compare)\s*(===|==|!=|!==)\s*\$expected/',
				$body,
				$class . '::verifySignature must not compare the digest with =='
			);
			$this->assertMatchesRegularExpression(
				'/(secret|webhookSecret)\s*===\s*\'\'/',
				$body,
				$class . '::verifySignature must fail closed on an unset secret'
			);
		}
	}//end testEveryVendorClientComparesTheWebhookSecretInConstantTime()
}//end class
