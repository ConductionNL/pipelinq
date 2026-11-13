<?php

/**
 * Unit tests for SmsAdapter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Tests\Unit\Support\FakeMessagingAccount;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\SmsProviderFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SmsAdapter — priority failover, provider hint, consent
 * gate, inbound webhook signature verification.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class SmsAdapterTest extends TestCase {

	/**
	 * The ticket intake, when a test registers one.
	 *
	 * @var \OCA\Pipelinq\Service\InboundTicketService|null
	 */
	private ?\OCA\Pipelinq\Service\InboundTicketService $intake = null;
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private ChannelProviderRepository $providerRepo;
	private SmsProviderFactory $providerFactory;
	private ConsentService $consentService;
	private BudgetService $budgetService;
	private NotificationService $notificationService;
	private LoggerInterface $logger;
	private ContactmomentService $contactmoments;
	private FakeMessagingAccount $messaging;
	private object $objectService;
	private SmsAdapter $adapter;

	/**
	 * setUp.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->providerRepo = $this->createMock(ChannelProviderRepository::class);
		$this->providerFactory = $this->createMock(SmsProviderFactory::class);
		$this->consentService = $this->createMock(ConsentService::class);
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->contactmoments = $this->createMock(ContactmomentService::class);
		$this->messaging = (new FakeMessagingAccount($this))->usable();

		$this->objectService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * Every call with the access flags it was made with.
			 *
			 * @var array<int, array{call: string, _rbac: bool, _multitenancy: bool}>
			 */
			public array $access = [];

			/**
			 * The messaging account world, to stamp each call with the acting user.
			 *
			 * @var FakeMessagingAccount|null
			 */
			public ?FakeMessagingAccount $world = null;

			/**
			 * Contacts by phone number, for the contact lookup.
			 *
			 * @var array<string, string>
			 */
			public array $contacts = [];

			/**
			 * Mock saveObject.
			 *
			 * @param array $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->access[] = ['call' => 'saveObject', '_rbac' => $_rbac, '_multitenancy' => $_multitenancy, 'as' => $this->world?->actingUid()];
				$object['uuid'] = ($uuid ?? ('row-' . count($this->saved)));
				$this->saved[] = $object;
				return $object;
			}

			/**
			 * Mock findAll — always empty (no conversation / contact match).
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config).
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->access[] = ['call' => 'findAll', '_rbac' => $_rbac, '_multitenancy' => $_multitenancy, 'as' => $this->world?->actingUid()];
				// The contact schema stores the number under `phone`.
				$phone = (string)($config['filters']['phone'] ?? '');
				if ($phone !== '' && isset($this->contacts[$phone]) === true) {
					return [['uuid' => $this->contacts[$phone], 'phone' => $phone]];
				}

				return [];
			}
		};

		$this->objectService->world = $this->messaging;

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}
				if ($id === 'OCA\\Pipelinq\\Service\\ContactmomentService') {
					return $this->contactmoments;
				}
				if ($id === 'OCA\\Pipelinq\\Service\\MessagingServiceAccount') {
					return $this->messaging->account;
				}
				if ($id === 'OCA\\Pipelinq\\Service\\InboundTicketService' && $this->intake !== null) {
					return $this->intake;
				}
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'tenant_id' => 'tenant-1',
					default => $default,
				};
			}
		);

		$this->adapter = new SmsAdapter($this->container,
			$this->appConfig,
			$this->providerRepo,
			$this->providerFactory,
			$this->consentService,
			$this->budgetService,
			$this->notificationService,
			$this->logger,
			new PhoneNormaliser($this->appConfig, $this->logger),
		);
	}//end setUp()

	/**
	 * Build a stub SMS provider client that returns the configured
	 * outcome on send / verifySignature.
	 *
	 * @param string $vendor Vendor key.
	 * @param string $sendOutcome 'success' / 'transient' / 'permanent'.
	 * @param string $externalId Provider id on success.
	 * @param bool $sigVerifies Signature outcome.
	 *
	 * @return SmsProviderClientInterface
	 */
	private function buildClient(
		string $vendor,
		string $sendOutcome,
		string $externalId = 'ext-1',
		bool $sigVerifies = true,
	): SmsProviderClientInterface {
		return new class($vendor, $sendOutcome, $externalId, $sigVerifies) implements SmsProviderClientInterface {
			/** @var array<int, array{to: string, body: string}> */
			public array $calls = [];
			private string $vendor;
			private string $sendOutcome;
			private string $externalId;
			private bool $sigVerifies;

			public function __construct(string $vendor, string $sendOutcome, string $externalId, bool $sigVerifies) {
				$this->vendor = $vendor;
				$this->sendOutcome = $sendOutcome;
				$this->externalId = $externalId;
				$this->sigVerifies = $sigVerifies;
			}

			public function send(string $toNumber, string $body): array {
				$this->calls[] = ['to' => $toNumber, 'body' => $body];
				if ($this->sendOutcome === 'transient') {
					throw new TransientSmsProviderException('simulated 5xx');
				}
				if ($this->sendOutcome === 'permanent') {
					throw new PermanentSmsProviderException('simulated 4xx');
				}
				return ['externalMessageId' => $this->externalId, 'vendor' => $this->vendor];
			}

			public function verifySignature(string $rawBody, string $signature): bool {
				return $this->sigVerifies;
			}

			public function getVendor(): string {
				return $this->vendor;
			}
		};
	}//end buildClient()

	/**
	 * Send fails over from primary (transient) to secondary
	 * (success) without exposing the failover to the caller.
	 *
	 * @return void
	 */
	public function testSendFailsOverOnTransient(): void {
		$primary = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird', 'priority' => 1];
		$secondary = ['uuid' => 'prov-2', 'kind' => 'sms', 'vendor' => 'twilio',      'priority' => 2];

		$this->providerRepo->method('listActive')->willReturn([$primary, $secondary]);
		$this->consentService->method('canSend')->willReturn(true);
		$this->budgetService->method('canSend')->willReturn(true);

		$this->providerFactory
			->expects($this->exactly(2))
			->method('create')
			->willReturnOnConsecutiveCalls($this->buildClient('messagebird', 'transient'),
				$this->buildClient('twilio', 'success', 'twilio-sid'),
			);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('twilio', $result['vendor']);
		$this->assertSame('twilio-sid', $result['externalMessageId']);
	}//end testSendFailsOverOnTransient()

	/**
	 * Both providers transient → persisted as failed + admin
	 * notified.
	 *
	 * @return void
	 */
	public function testSendAllProvidersTransientPersistsFailedAndAlerts(): void {
		$primary = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird', 'priority' => 1];
		$secondary = ['uuid' => 'prov-2', 'kind' => 'sms', 'vendor' => 'twilio',      'priority' => 2];

		$this->providerRepo->method('listActive')->willReturn([$primary, $secondary]);
		$this->consentService->method('canSend')->willReturn(true);
		$this->budgetService->method('canSend')->willReturn(true);

		$this->providerFactory
			->method('create')
			->willReturnOnConsecutiveCalls($this->buildClient('messagebird', 'transient'),
				$this->buildClient('twilio', 'transient'),
			);

		$this->notificationService->expects($this->once())
			->method('sendNotification');

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
		);

		$this->assertSame('failed', $result['status']);
	}//end testSendAllProvidersTransientPersistsFailedAndAlerts()

	/**
	 * providerHint pins a vendor and skips failover.
	 *
	 * @return void
	 */
	public function testSendProviderHintIsPinned(): void {
		$cmcom = ['uuid' => 'prov-3', 'kind' => 'sms', 'vendor' => 'cm-com', 'priority' => 3];
		$this->providerRepo->method('findByVendor')->willReturn($cmcom);
		$this->consentService->method('canSend')->willReturn(true);
		$this->budgetService->method('canSend')->willReturn(true);

		$this->providerFactory
			->expects($this->once())
			->method('create')
			->with($this->equalTo($cmcom))
			->willReturn($this->buildClient('cm-com', 'success', 'cm-sid'));

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
			'cm-com',
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('cm-com', $result['vendor']);
	}//end testSendProviderHintIsPinned()

	/**
	 * Consent-missing returns consentMissing without calling a provider.
	 *
	 * @return void
	 */
	public function testSendRefusedWhenConsentMissing(): void {
		$this->consentService->method('canSend')->willReturn(false);

		$this->providerFactory->expects($this->never())->method('create');

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
		);

		$this->assertSame('consentMissing', $result['status']);
	}//end testSendRefusedWhenConsentMissing()

	/**
	 * Inbound webhook with invalid signature returns invalidSignature
	 * (the controller maps this to HTTP 400).
	 *
	 * @return void
	 */
	public function testHandleInboundWebhookInvalidSignature(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio'];
		$this->providerRepo->method('findByIdForWebhook')->willReturn($row);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('twilio', 'success', 'ext-1', false));

		$result = $this->adapter->handleInboundWebhook('body', 'bad-sig', 'prov-1');

		$this->assertSame('invalidSignature', $result['status']);
	}//end testHandleInboundWebhookInvalidSignature()

	/**
	 * The inbound webhook refuses, in order: an unknown provider, a vendor
	 * without a client, a bad signature (logged), a payload without a
	 * sender or a body; nothing is written for any of them.
	 *
	 * @return void
	 */
	public function testTheInboundWebhookRefusalsInOrder(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio'];
		$rows = [null, $row, $row, $row, $row];
		$this->providerRepo->method('findByIdForWebhook')->willReturnCallback(static function () use (&$rows): ?array {
			return array_shift($rows);
		});
		$clients = [null, $this->buildClient('twilio', 'success', 'ext-1', false), $this->buildClient('twilio', 'success', 'ext-1', true), $this->buildClient('twilio', 'success', 'ext-1', true)];
		$this->providerFactory->method('create')->willReturnCallback(static function () use (&$clients) {
			return array_shift($clients);
		});
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(static function (string $message, array $context = []) use (&$warnings): void {
			$warnings[] = [$message, $context];
		});

		$this->assertSame(['status' => 'providerUnknown'], $this->adapter->handleInboundWebhook('{}', 's', 'prov-0'));
		$this->assertSame(['status' => 'vendorUnsupported'], $this->adapter->handleInboundWebhook('{}', 's', 'prov-1'));
		$this->assertSame(['status' => 'invalidSignature'], $this->adapter->handleInboundWebhook('{}', 'bad', 'prov-1'));
		$this->assertSame(['status' => 'invalidPayload'], $this->adapter->handleInboundWebhook((string)json_encode(['Body' => 'hi']), 's', 'prov-1'));
		$this->assertSame(['status' => 'invalidPayload'], $this->adapter->handleInboundWebhook((string)json_encode(['From' => '+31600000000', 'body' => '']), 's', 'prov-1'));
		$this->assertSame([['SmsAdapter.handleInboundWebhook: invalid signature', ['providerId' => 'prov-1']]], $warnings);
		$this->assertSame([], $this->objectService->saved);
	}//end testTheInboundWebhookRefusalsInOrder()

	/**
	 * Inbound webhook with valid signature persists the message. The OR mock
	 * holds no contact, so the sender is unknown and no contact is created.
	 *
	 * @return void
	 */
	public function testHandleInboundWebhookPersistsAndRoutes(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio'];
		$this->providerRepo->method('findByIdForWebhook')->willReturn($row);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('twilio', 'success', 'ext-1', true));

		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);

		$rawBody = json_encode(['From' => '+31600000000', 'Body' => 'hello']);
		$result = $this->adapter->handleInboundWebhook($rawBody, 'sig', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertTrue($result['unknownSender']);
		$this->assertArrayNotHasKey('placeholderCreated', $result);
	}//end testHandleInboundWebhookPersistsAndRoutes()

	/**
	 * STOP keyword on inbound triggers opt-out recording.
	 *
	 * @return void
	 */
	public function testHandleInboundWebhookStopKeywordOptsOut(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio'];
		$this->providerRepo->method('findByIdForWebhook')->willReturn($row);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('twilio', 'success', 'ext-1', true));

		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->once())
			->method('recordOptOut')
			->with($this->isType('string'),
				$this->equalTo('sms'),
				$this->equalTo('keyword-stop'),
				$this->stringContains('STOP'),
			)
			->willReturn(['store' => 'integriq']);

		$rawBody = json_encode(['From' => '+31600000000', 'Body' => 'STOP']);
		$result = $this->adapter->handleInboundWebhook($rawBody, 'sig', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertTrue($result['optOutRecorded']);
	}//end testHandleInboundWebhookStopKeywordOptsOut()

	/**
	 * A STOP from a known contact's number is recorded on that contact: the
	 * lookup asks for the schema's `phone` field.
	 *
	 * @return void
	 */
	public function testInboundStopFindsTheContactByPhone(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->once())
			->method('recordOptOut')
			->with($this->equalTo('c-gert'), $this->equalTo('sms'))
			->willReturn(['store' => 'integriq']);

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '+31611119999', 'body' => 'STOP']), 'sig', 'prov-1');

		$this->assertFalse($result['unknownSender']);
		$this->assertTrue($result['optOutRecorded']);
	}//end testInboundStopFindsTheContactByPhone()

	/**
	 * optOutRecorded reports what happened, not that a STOP arrived.
	 *
	 * @return void
	 */
	public function testAStopThatIsNotRecordedIsNotReportedAsRecorded(): void {
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->method('recordOptOut')->willReturn(null);

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '+31611119999', 'body' => 'STOP']), 'sig', 'prov-1');

		$this->assertFalse($result['optOutRecorded']);
	}//end testAStopThatIsNotRecordedIsNotReportedAsRecorded()

	/**
	 * The provider's webhook is a PublicPage: no user is logged in, so a
	 * read or write under OpenRegister's RBAC finds nothing ("not found in
	 * any magic table") and the webhook answered providerUnknown to every
	 * real callback. The provider lookup and every write after the
	 * signature check run as the system.
	 *
	 * @return void
	 */
	public function testInboundWebhookWritesAsTheMessagingServiceAccount(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird'];
		$this->providerRepo->expects($this->once())
			->method('findByIdForWebhook')
			->with('prov-1')
			->willReturn($row);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->once())
			->method('recordOptOut')
			->with(
				$this->isType('string'),
				$this->equalTo('sms'),
				$this->equalTo('keyword-stop'),
				$this->stringContains('STOP'),
				$this->anything(),
				$this->equalTo('+31611119999'),
			);

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '+31611119999', 'body' => 'STOP']), 'sig', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertNotSame([], $this->objectService->access);
		$writes = 0;
		foreach ($this->objectService->access as $access) {
			if ($access['call'] !== 'saveObject') {
				// A lookup is a read and may skip RBAC.
				continue;
			}

			$writes++;
			$this->assertTrue($access['_rbac'], 'a webhook write skipped RBAC');
			$this->assertTrue($access['_multitenancy'], 'a webhook write skipped multitenancy');
			$this->assertSame(FakeMessagingAccount::UID, $access['as'], 'a webhook write ran as somebody else');
		}

		$this->assertGreaterThan(0, $writes);
		$this->assertNull($this->messaging->actingUid(), 'the caller was not restored');
	}//end testInboundWebhookWritesAsTheMessagingServiceAccount()

	/**
	 * A STOP from a number that matches no contact is recorded in integriq on
	 * the number itself, in E.164 (MessageBird sends it without '+'). No
	 * contact is created and nothing is logged for a person.
	 *
	 * @return void
	 */
	public function testAStopFromAnUnknownNumberIsRecordedOnTheNumber(): void {
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->once())
			->method('recordOptOut')
			->with(
				$this->equalTo(''),
				$this->equalTo('sms'),
				$this->equalTo('keyword-stop'),
				$this->anything(),
				$this->anything(),
				$this->equalTo('+31699990000'),
			)
			->willReturn(['store' => 'integriq']);
		$this->contactmoments->expects($this->never())->method('recordInboundFromUnknownNumber');

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '31699990000', 'body' => 'STOP']), 'sig', 'prov-1');

		$this->assertTrue($result['optOutRecorded']);
		$this->assertTrue($result['unknownSender']);
		foreach ($this->objectService->saved as $row) {
			$this->assertArrayNotHasKey('placeholder', $row, 'a placeholder contact was created');
		}
	}//end testAStopFromAnUnknownNumberIsRecordedOnTheNumber()

	/**
	 * Any other SMS from an unknown number is logged as a new contact moment
	 * for a person to pick up; no contact is created.
	 *
	 * @return void
	 */
	public function testAnotherSmsFromAnUnknownNumberIsLoggedForAPerson(): void {
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);
		$this->contactmoments->expects($this->once())
			->method('recordInboundFromUnknownNumber')
			->with(
				$this->equalTo('sms'),
				$this->equalTo('+31699990000'),
				$this->equalTo('Wie is dit?'),
				$this->isType('string'),
			)
			->willReturn('cm-1');

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '+31699990000', 'body' => 'Wie is dit?']), 'sig', 'prov-1');

		$this->assertTrue($result['unknownSender']);
		$this->assertSame('cm-1', $result['contactMomentId']);
		foreach ($this->objectService->saved as $row) {
			$this->assertArrayNotHasKey('placeholder', $row, 'a placeholder contact was created');
		}
	}//end testAnotherSmsFromAnUnknownNumberIsLoggedForAPerson()

	/**
	 * A message from a known contact is not logged as an unknown sender.
	 *
	 * @return void
	 */
	public function testAnSmsFromAKnownContactIsNotLoggedAsUnknown(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);
		$this->contactmoments->expects($this->never())->method('recordInboundFromUnknownNumber');

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '31611119999', 'body' => 'hallo']), 'sig', 'prov-1');

		$this->assertFalse($result['unknownSender']);
	}//end testAnSmsFromAKnownContactIsNotLoggedAsUnknown()

	/**
	 * An opt-in keyword from a known contact records the opt-in, and the
	 * inbound row is stored as a delivered message of that contact.
	 *
	 * @return void
	 */
	public function testAnOptInSmsFromAKnownContactIsRecordedAndStored(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(true);
		$this->consentService->expects($this->never())->method('recordOptOut');
		$this->consentService->expects($this->once())
			->method('recordOptIn')
			->with('c-gert', 'sms', 'chat-reply', 'Inbound SMS body "START" matched opt-in keyword', 'consent', '+31611119999');

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '31611119999', 'body' => 'START']), 'sig', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertFalse($result['unknownSender']);
		$this->assertFalse($result['optOutRecorded']);
		$this->assertArrayNotHasKey('contactMomentId', $result);
		$inbound = array_values(array_filter(
			$this->objectService->saved,
			static fn (array $row): bool => ($row['direction'] ?? '') === 'inbound'
		));
		$this->assertCount(1, $inbound);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $inbound[0]['sentAt']);
		unset($inbound[0]['sentAt']);
		$this->assertSame(
			['contactId', 'conversationId', 'channel', 'direction', 'body', 'providerId', 'deliveryStatus'],
			array_keys(array_intersect_key($inbound[0], array_flip(['contactId', 'conversationId', 'channel', 'direction', 'body', 'providerId', 'deliveryStatus'])))
		);
		$this->assertSame('c-gert', $inbound[0]['contactId']);
		$this->assertNotSame('', $inbound[0]['conversationId']);
		$this->assertSame('sms', $inbound[0]['channel']);
		$this->assertSame('START', $inbound[0]['body']);
		$this->assertSame('prov-1', $inbound[0]['providerId']);
		$this->assertSame('delivered', $inbound[0]['deliveryStatus']);
	}//end testAnOptInSmsFromAKnownContactIsRecordedAndStored()

	/**
	 * An opt-in keyword from an unknown number records no opt-in: it is
	 * logged for a person like any other message from that number.
	 *
	 * @return void
	 */
	public function testAnOptInSmsFromAnUnknownNumberIsOnlyLogged(): void {
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(true);
		$this->consentService->expects($this->never())->method('recordOptIn');
		$this->contactmoments->expects($this->once())
			->method('recordInboundFromUnknownNumber')
			->willReturn('cm-2');

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '+31699990000', 'body' => 'START']), 'sig', 'prov-1');

		$this->assertTrue($result['unknownSender']);
		$this->assertSame('cm-2', $result['contactMomentId']);
	}//end testAnOptInSmsFromAnUnknownNumberIsOnlyLogged()

	/**
	 * Without a usable messaging service account the webhook answers
	 * serviceUnavailable (503, so the provider retries) and writes nothing:
	 * no message, no conversation, no opt-out, no contact moment.
	 *
	 * @return void
	 */
	public function testWithoutAServiceAccountNothingIsWritten(): void {
		$this->messaging->config = [];
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->never())->method('recordOptOut');
		$this->contactmoments->expects($this->never())->method('recordInboundFromUnknownNumber');

		$result = $this->adapter->handleInboundWebhook(json_encode(['from' => '+31611119999', 'body' => 'STOP']), 'sha256=ok', 'prov-1');

		$this->assertSame('serviceUnavailable', $result['status']);
		foreach ($this->objectService->access as $access) {
			$this->assertNotSame('saveObject', $access['call'], 'something was written without the service account');
		}
	}//end testWithoutAServiceAccountNothingIsWritten()
	/**
	 * Send one inbound message from a known contact.
	 *
	 * @param string $body The text.
	 *
	 * @return array<string, mixed> The webhook outcome.
	 */
	private function inboundFromKnownContact(string $body): array {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird']);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('messagebird', 'success', 'ext-1', true));

		return $this->adapter->handleInboundWebhook((string)json_encode(['from' => '+31611119999', 'body' => $body]), 'sig', 'prov-1');
	}//end inboundFromKnownContact()

	/**
	 * A known contact's message goes to the ticket intake on its conversation's thread.
	 *
	 * @return void
	 */
	public function testAKnownContactsMessageReachesTheTicketIntake(): void {
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);
		$sent = [];
		$this->intake = $this->createMock(\OCA\Pipelinq\Service\InboundTicketService::class);
		$this->intake->expects($this->once())->method('tryReceive')->willReturnCallback(
			static function (array $message) use (&$sent): string {
				$sent = $message;
				return 'ticket-7';
			}
		);

		$result = $this->inboundFromKnownContact(body: 'Is my permit ready?');

		$this->assertSame('received', $result['status']);
		$this->assertSame('ticket-7', $result['ticket']);
		$this->assertNotSame('', $result['conversationId']);
		$this->assertSame(
			[
				'channel' => 'sms',
				'threadKey' => 'conversation:' . $result['conversationId'],
				'contact' => 'c-gert',
				'text' => 'Is my permit ready?',
				'fromAddress' => '+31611119999',
			],
			$sent
		);
	}//end testAKnownContactsMessageReachesTheTicketIntake()

	/**
	 * A STOP or an opt-in keyword opens no ticket.
	 *
	 * @return void
	 */
	public function testKeywordsOpenNoTicket(): void {
		$this->consentService->method('isOptOutKeyword')->willReturnCallback(static fn (string $body): bool => $body === 'STOP');
		$this->consentService->method('isOptInKeyword')->willReturnCallback(static fn (string $body): bool => $body === 'START');
		$this->consentService->method('recordOptOut')->willReturn(['store' => 'integriq']);
		$this->intake = $this->createMock(\OCA\Pipelinq\Service\InboundTicketService::class);
		$this->intake->expects($this->never())->method('tryReceive');

		$this->assertArrayNotHasKey('ticket', $this->inboundFromKnownContact(body: 'STOP'));
		$this->assertArrayNotHasKey('ticket', $this->inboundFromKnownContact(body: 'START'));
	}//end testKeywordsOpenNoTicket()

	/**
	 * A failed intake still answers received, so the provider does not deliver the message again.
	 *
	 * @return void
	 */
	public function testAFailedIntakeStillAnswersReceived(): void {
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);
		$this->intake = $this->createMock(\OCA\Pipelinq\Service\InboundTicketService::class);
		$this->intake->method('tryReceive')->willReturn('');

		$result = $this->inboundFromKnownContact(body: 'hallo');

		$this->assertSame('received', $result['status']);
		$this->assertSame('', $result['ticket']);
	}//end testAFailedIntakeStillAnswersReceived()
}//end class
