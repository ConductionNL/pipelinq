<?php

/**
 * Unit tests for WhatsAppAdapter.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WhatsAppAdapter — template lookup + parameter
 * validation, session-window enforcement, inbound signature
 * verification, placeholder contact creation.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.1
 */
class WhatsAppAdapterTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private ChannelProviderRepository $providerRepo;
	private WhatsAppProviderClient $providerClient;
	private ConsentService $consentService;
	private BudgetService $budgetService;
	private NotificationService $notificationService;
	private LoggerInterface $logger;
	private ContactmomentService $contactmoments;
	private object $objectService;
	private WhatsAppAdapter $adapter;

	/**
	 * setUp.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->providerRepo = $this->createMock(ChannelProviderRepository::class);
		$this->providerClient = $this->createMock(WhatsAppProviderClient::class);
		$this->consentService = $this->createMock(ConsentService::class);
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->contactmoments = $this->createMock(ContactmomentService::class);

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $inboundMessages = [];

			/**
			 * Every call with the access flags it was made with.
			 *
			 * @var array<int, array{call: string, _rbac: bool, _multitenancy: bool}>
			 */
			public array $access = [];

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
				$this->access[] = ['call' => 'saveObject', '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				if ($uuid === null || $uuid === '') {
					$uuid = (string)($object['uuid'] ?? '');
				}
				if ($uuid === '') {
					$uuid = ('row-' . count($this->store));
				}
				$object['uuid'] = $uuid;
				$this->store[$uuid] = $object;
				return $object;
			}

			/**
			 * Mock find.
			 *
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?array {
				$this->access[] = ['call' => 'find', '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return ($this->store[$id] ?? null);
			}

			/**
			 * Mock findAll. For 'inbound' filter return the seeded
			 * inbound messages.
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config): the
			 * data-property filters travel inside $config['filters'].
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->access[] = ['call' => 'findAll', '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				$filters = $config['filters'] ?? [];
				// The contact schema stores the number under `phone`.
				$phone = (string)($filters['phone'] ?? '');
				if ($phone !== '' && isset($this->contacts[$phone]) === true) {
					return [['uuid' => $this->contacts[$phone], 'phone' => $phone]];
				}
				if (($filters['direction'] ?? '') === 'inbound') {
					return $this->inboundMessages;
				}
				return [];
			}
		};

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}
				if ($id === 'OCA\\Pipelinq\\Service\\ContactmomentService') {
					return $this->contactmoments;
				}
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'tenant_id' => 'tenant-1',
					'whatsapp.default_language' => 'nl',
					default => $default,
				};
			}
		);

		$this->adapter = new WhatsAppAdapter($this->container,
			$this->appConfig,
			$this->providerRepo,
			$this->providerClient,
			$this->consentService,
			$this->budgetService,
			$this->notificationService,
			$this->logger,
			new PhoneNormaliser($this->appConfig, $this->logger),
		);
	}//end setUp()

	/**
	 * parseTemplatePlaceholders counts the distinct {{N}} positions.
	 *
	 * @return void
	 */
	public function testParseTemplatePlaceholders(): void {
		$this->assertSame(0, $this->adapter->parseTemplatePlaceholders('plain text'));
		$this->assertSame(3, $this->adapter->parseTemplatePlaceholders('Beste {{1}}, op {{2}} om {{3}}'));
		// Duplicate placeholders count once.
		$this->assertSame(2, $this->adapter->parseTemplatePlaceholders('{{1}} {{2}} {{1}}'));
	}//end testParseTemplatePlaceholders()

	/**
	 * Template with mismatched parameter count returns
	 * templateParameterMismatch.
	 *
	 * @return void
	 */
	public function testSendTemplateWithMismatchedParameters(): void {
		$template = [
			'uuid' => 'tpl-1',
			'providerId' => 'prov-1',
			'status' => 'approved',
			'externalId' => 'afspraak_bevestiging_nl',
			'language' => 'nl',
			'body' => 'Beste {{1}}, op {{2}} om {{3}}',
		];
		$this->objectService->saveObject($template);

		// Template sends are business-initiated: they now require an
		// opted-in record (canSendBusinessInitiated), gated before the
		// parameter-count check reached below.
		$this->consentService->method('canSend')->willReturn(true);
		$this->consentService->method('canSendBusinessInitiated')->willReturn(true);
		$this->providerRepo->method('listActive')->willReturn([
			['uuid' => 'prov-1', 'kind' => 'whatsapp-cloud-api', 'vendor' => 'meta'],
		]);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'',
			'tpl-1',
			['Jan', 'vrijdag'],
		);

		$this->assertSame('templateParameterMismatch', $result['status']);
		$this->assertSame(3, $result['expected']);
		$this->assertSame(2, $result['given']);
	}//end testSendTemplateWithMismatchedParameters()

	/**
	 * Template with `status: pending` is refused.
	 *
	 * @return void
	 */
	public function testSendRefusesPendingTemplate(): void {
		$template = [
			'uuid' => 'tpl-2',
			'providerId' => 'prov-1',
			'status' => 'pending',
			'externalId' => 'pending_tpl',
			'language' => 'nl',
			'body' => 'hi {{1}}',
		];
		$this->objectService->saveObject($template);

		$this->consentService->method('canSend')->willReturn(true);
		$this->consentService->method('canSendBusinessInitiated')->willReturn(true);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'',
			'tpl-2',
			['Jan'],
		);

		$this->assertSame('templateNotApproved', $result['status']);
	}//end testSendRefusesPendingTemplate()

	/**
	 * Free-form send within the 24h session window succeeds.
	 *
	 * @return void
	 */
	public function testSendFreeFormWithinSessionWindow(): void {
		$this->objectService->inboundMessages = [
			[
				'uuid' => 'msg-1',
				'channel' => 'whatsapp',
				'direction' => 'inbound',
				'sentAt' => gmdate('Y-m-d\TH:i:s\Z', (time() - 3600)),
			],
		];

		$this->consentService->method('canSend')->willReturn(true);
		$this->budgetService->method('canSend')->willReturn(true);
		$this->providerRepo->method('listActive')->willReturn([
			['uuid' => 'prov-1', 'kind' => 'whatsapp-cloud-api', 'vendor' => 'meta'],
		]);

		$this->providerClient->method('sendFreeForm')
			->willReturn(['externalMessageId' => 'wamid.1', 'vendor' => 'meta']);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hi there',
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('wamid.1', $result['externalMessageId']);
	}//end testSendFreeFormWithinSessionWindow()

	/**
	 * Free-form send outside the 24h session window is refused.
	 *
	 * @return void
	 */
	public function testSendFreeFormOutsideSessionWindow(): void {
		$this->objectService->inboundMessages = [
			[
				'uuid' => 'msg-1',
				'channel' => 'whatsapp',
				'direction' => 'inbound',
				'sentAt' => gmdate('Y-m-d\TH:i:s\Z', (time() - 90000)),
			],
		];

		$this->consentService->method('canSend')->willReturn(true);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hi there',
		);

		$this->assertSame('sessionWindowExpired', $result['status']);
	}//end testSendFreeFormOutsideSessionWindow()

	/**
	 * Free-form send with no prior inbound (no session) is refused.
	 *
	 * @return void
	 */
	public function testSendFreeFormNoSessionRefused(): void {
		$this->consentService->method('canSend')->willReturn(true);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hi',
		);

		$this->assertSame('sessionWindowExpired', $result['status']);
	}//end testSendFreeFormNoSessionRefused()

	/**
	 * Inbound webhook with an invalid signature is rejected.
	 *
	 * @return void
	 */
	public function testInboundWebhookInvalidSignature(): void {
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'webhookSecret' => 'secret']);
		$this->providerClient->method('verifySignature')->willReturn(false);

		$result = $this->adapter->handleInboundWebhook('{}', 'sha256=bad', 'prov-1');

		$this->assertSame('invalidSignature', $result['status']);
	}//end testInboundWebhookInvalidSignature()

	/**
	 * A Meta `messages` callback from the given number with the given text.
	 *
	 * @param string $from Sender as Meta sends it (international, no '+').
	 * @param string $text Message text.
	 *
	 * @return string Raw body.
	 */
	private function metaMessage(string $from, string $text): string {
		return (string)json_encode([
			'entry' => [
				[
					'changes' => [
						[
							'value' => [
								'messages' => [
									['from' => $from, 'text' => ['body' => $text]],
								],
							],
						],
					],
				],
			],
		]);
	}//end metaMessage()

	/**
	 * Stub a signed callback for provider prov-1, found by the webhook lookup.
	 *
	 * @return void
	 */
	private function stubSignedProvider(): void {
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'webhookSecret' => 'secret']);
		$this->providerClient->method('verifySignature')->willReturn(true);
	}//end stubSignedProvider()

	/**
	 * The webhook is a PublicPage with no user. Under OpenRegister's RBAC the
	 * provider lookup found nothing, so every real Meta callback, STOP
	 * included, answered providerUnknown. The provider lookup and every read
	 * and write after the signature check run as the system, as the SMS
	 * webhook does since pipelinq#2169.
	 *
	 * @return void
	 */
	public function testInboundWebhookReadsAndWritesAsTheSystem(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->providerRepo->expects($this->once())
			->method('findByIdForWebhook')
			->with('prov-1')
			->willReturn(['uuid' => 'prov-1', 'webhookSecret' => 'secret']);
		$this->providerRepo->expects($this->never())->method('findById');
		$this->providerClient->method('verifySignature')->willReturn(true);
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);

		$result = $this->adapter->handleInboundWebhook($this->metaMessage('31611119999', 'hallo'), 'sha256=ok', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertNotSame([], $this->objectService->access);
		foreach ($this->objectService->access as $access) {
			$this->assertFalse($access['_rbac'], $access['call'] . ' ran under RBAC');
			$this->assertFalse($access['_multitenancy'], $access['call'] . ' ran under multitenancy');
		}
	}//end testInboundWebhookReadsAndWritesAsTheSystem()

	/**
	 * A STOP from a known contact is recorded on that contact for the WhatsApp
	 * channel. Meta sends the sender without '+', the lookup asks the schema's
	 * `phone` field for the E.164 form, and optOutRecorded reports the
	 * outcome.
	 *
	 * @return void
	 */
	public function testAStopFromAKnownContactIsRecordedOnTheContact(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->stubSignedProvider();
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->once())
			->method('recordOptOut')
			->with(
				$this->equalTo('c-gert'),
				$this->equalTo('whatsapp'),
				$this->equalTo('keyword-stop'),
				$this->stringContains('STOP'),
				$this->anything(),
				$this->equalTo('+31611119999'),
			)
			->willReturn(['store' => 'integriq']);
		$this->contactmoments->expects($this->never())->method('recordInboundFromUnknownNumber');

		$result = $this->adapter->handleInboundWebhook($this->metaMessage('31611119999', 'STOP'), 'sha256=ok', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertTrue($result['optOutRecorded']);
		$this->assertFalse($result['unknownSender']);
	}//end testAStopFromAKnownContactIsRecordedOnTheContact()

	/**
	 * optOutRecorded reports what happened, not that a STOP arrived.
	 *
	 * @return void
	 */
	public function testAStopThatIsNotRecordedIsNotReportedAsRecorded(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->stubSignedProvider();
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->method('recordOptOut')->willReturn(null);

		$result = $this->adapter->handleInboundWebhook($this->metaMessage('31611119999', 'STOP'), 'sha256=ok', 'prov-1');

		$this->assertFalse($result['optOutRecorded']);
	}//end testAStopThatIsNotRecordedIsNotReportedAsRecorded()

	/**
	 * The opt-out acknowledgement goes to the number that sent the STOP.
	 *
	 * @return void
	 */
	public function testTheOptOutAcknowledgementGoesToTheSender(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->stubSignedProvider();
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->method('recordOptOut')->willReturn(['store' => 'integriq']);
		$this->providerClient->expects($this->once())
			->method('sendFreeForm')
			->with($this->anything(), $this->equalTo('+31611119999'), $this->anything());

		$this->adapter->handleInboundWebhook($this->metaMessage('31611119999', 'STOP'), 'sha256=ok', 'prov-1');
	}//end testTheOptOutAcknowledgementGoesToTheSender()

	/**
	 * A STOP from a number that matches no contact is recorded in integriq on
	 * the number itself (E.164, WhatsApp channel), so it is never messaged
	 * again. No contact is created for it.
	 *
	 * @return void
	 */
	public function testAStopFromAnUnknownNumberIsRecordedOnTheNumber(): void {
		$this->stubSignedProvider();
		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->once())
			->method('recordOptOut')
			->with(
				$this->equalTo(''),
				$this->equalTo('whatsapp'),
				$this->equalTo('keyword-stop'),
				$this->anything(),
				$this->anything(),
				$this->equalTo('+31699990000'),
			)
			->willReturn(['store' => 'integriq']);
		$this->contactmoments->expects($this->never())->method('recordInboundFromUnknownNumber');

		$result = $this->adapter->handleInboundWebhook($this->metaMessage('31699990000', 'STOP'), 'sha256=ok', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertTrue($result['optOutRecorded']);
		$this->assertTrue($result['unknownSender']);
		$this->assertArrayNotHasKey('placeholderCreated', $result);
		foreach ($this->objectService->store as $row) {
			$this->assertArrayNotHasKey('placeholder', $row, 'a placeholder contact was created');
		}
	}//end testAStopFromAnUnknownNumberIsRecordedOnTheNumber()

	/**
	 * Any other message from an unknown number is logged as a new contact
	 * moment for a person to pick up; no contact is created.
	 *
	 * @return void
	 */
	public function testAnotherMessageFromAnUnknownNumberIsLoggedForAPerson(): void {
		$this->stubSignedProvider();
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);
		$this->consentService->expects($this->never())->method('recordOptOut');
		$this->contactmoments->expects($this->once())
			->method('recordInboundFromUnknownNumber')
			->with(
				$this->equalTo('whatsapp'),
				$this->equalTo('+31699990000'),
				$this->equalTo('Hallo, wie is dit?'),
				$this->isType('string'),
			)
			->willReturn('cm-1');

		$result = $this->adapter->handleInboundWebhook($this->metaMessage('31699990000', 'Hallo, wie is dit?'), 'sha256=ok', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertTrue($result['unknownSender']);
		$this->assertSame('cm-1', $result['contactMomentId']);
		foreach ($this->objectService->store as $row) {
			$this->assertArrayNotHasKey('placeholder', $row, 'a placeholder contact was created');
		}
	}//end testAnotherMessageFromAnUnknownNumberIsLoggedForAPerson()

	/**
	 * A message from a known contact is not logged as an unknown sender.
	 *
	 * @return void
	 */
	public function testAMessageFromAKnownContactIsNotLoggedAsUnknown(): void {
		$this->objectService->contacts = ['+31611119999' => 'c-gert'];
		$this->stubSignedProvider();
		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);
		$this->contactmoments->expects($this->never())->method('recordInboundFromUnknownNumber');

		$result = $this->adapter->handleInboundWebhook($this->metaMessage('31611119999', 'hallo'), 'sha256=ok', 'prov-1');

		$this->assertFalse($result['unknownSender']);
	}//end testAMessageFromAKnownContactIsNotLoggedAsUnknown()
}//end class
