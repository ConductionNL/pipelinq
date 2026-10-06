<?php

/**
 * The SMS and WhatsApp adapters ask integriq through the real ConsentService.
 *
 * Wired from the caller: a real SmsAdapter and WhatsAppAdapter, a real
 * ConsentService and IntegriqConsentClient, and an in-memory integriq behind
 * a real IEventDispatcher. Only the provider transport is faked.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\SmsProviderFactory;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Opt-outs in integriq stop pipelinq's SMS and WhatsApp; replies still go.
 */
class MessagingOptOutWiringTest extends TestCase {

	private FakeIntegriq $integriq;

	private object $store;

	private ConsentService $consent;

	private ChannelProviderRepository $providerRepo;

	private string $flag = 'integriq';

	/** @var array<int, array{to: string, body: string}> */
	public array $smsSent = [];

	protected function setUp(): void {
		$this->integriq = new FakeIntegriq();
		$this->store = new class {
			/** @var array<string, array{schema: string, row: array<string, mixed>}> */
			public array $rows = [];

			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$uuid = ($uuid ?? ($object['uuid'] ?? ('obj-'.count($this->rows))));
				$object['uuid'] = $uuid;
				$this->rows[$uuid] = ['schema' => (string)$schema, 'row' => $object];
				return $object;
			}

			public function find(string $id, $register = null, $schema = null): ?array {
				$hit = ($this->rows[$id] ?? null);
				if ($hit === null || ($schema !== null && $hit['schema'] !== $schema)) {
					return null;
				}
				return $hit['row'];
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = ($config['filters'] ?? []);
				$schema = ($filters['schema'] ?? null);
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach ($this->rows as $hit) {
					if ($schema !== null && $hit['schema'] !== $schema) {
						continue;
					}
					foreach ($filters as $key => $value) {
						if (($hit['row'][$key] ?? null) !== $value) {
							continue 2;
						}
					}
					$out[] = $hit['row'];
				}
				return $out;
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function ofSchema(string $schema): array {
				return array_values(array_map(static fn ($hit) => $hit['row'], array_filter($this->rows, static fn ($hit) => $hit['schema'] === $schema)));
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => $this->store);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => match ($key) {
				'register' => 'pipelinq',
				'consent.store' => $this->flag,
				default => $default,
			}
		);

		$logger = new NullLogger();
		$this->consent = new ConsentService(
			$container,
			$appConfig,
			$logger,
			FakeIntegriq::client($appConfig, $this->integriq),
			new ContactAddressLookup($container, $appConfig, $logger)
		);

		$this->providerRepo = $this->createMock(ChannelProviderRepository::class);
		$this->providerRepo->method('findById')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio']);
		$this->providerRepo->method('findByIdForWebhook')->willReturn(['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio']);
		$this->providerRepo->method('listActive')->willReturnCallback(
			static fn (string $kind) => [['uuid' => 'prov-'.$kind, 'kind' => $kind, 'vendor' => 'twilio', 'priority' => 1]]
		);

		$budget = $this->createMock(BudgetService::class);
		$budget->method('canSend')->willReturn(true);

		$this->smsAdapter = new SmsAdapter(
			$container,
			$appConfig,
			$this->providerRepo,
			$this->smsFactory(),
			$this->consent,
			$budget,
			$this->createMock(NotificationService::class),
			$logger,
			new \OCA\Pipelinq\Service\PhoneNormaliser($appConfig, $logger),
		);

		$this->whatsAppClient = $this->createMock(WhatsAppProviderClient::class);
		$this->whatsAppClient->method('sendFreeForm')->willReturn(['externalMessageId' => 'wamid-1', 'vendor' => 'meta']);
		$this->whatsAppAdapter = new WhatsAppAdapter(
			$container,
			$appConfig,
			$this->providerRepo,
			$this->whatsAppClient,
			$this->consent,
			$budget,
			$this->createMock(NotificationService::class),
			$logger,
			new \OCA\Pipelinq\Service\PhoneNormaliser($appConfig, $logger),
		);
	}//end setUp()

	private SmsAdapter $smsAdapter;

	private WhatsAppAdapter $whatsAppAdapter;

	private WhatsAppProviderClient $whatsAppClient;

	private function smsFactory(): SmsProviderFactory {
		$test = $this;
		$client = new class($test) implements SmsProviderClientInterface {
			public function __construct(private MessagingOptOutWiringTest $test) {
			}

			public function send(string $toNumber, string $body): array {
				$this->test->smsSent[] = ['to' => $toNumber, 'body' => $body];
				return ['externalMessageId' => 'ext-1', 'vendor' => 'twilio'];
			}

			public function verifySignature(string $rawBody, string $signature): bool {
				return true;
			}

			public function getVendor(): string {
				return 'twilio';
			}
		};
		$factory = $this->createMock(SmsProviderFactory::class);
		$factory->method('create')->willReturn($client);
		return $factory;
	}//end smsFactory()

	public function testAnSmsToAContactOptedOutInIntegriqIsNotSent(): void {
		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms', 'source' => 'unsubscribe-link']);

		$outcome = $this->smsAdapter->send(['uuid' => 'c-1', 'phone' => '+31612345678'], 'Uw afspraak is morgen');

		self::assertSame('consentMissing', $outcome['status']);
		self::assertSame([], $this->smsSent);
		self::assertSame('sms', $this->integriq->decisionEvents[0]->getChannel());
		self::assertSame('service', $this->integriq->decisionEvents[0]->getCategory());
	}//end testAnSmsToAContactOptedOutInIntegriqIsNotSent()

	public function testANormalContactGetsTheSms(): void {
		$outcome = $this->smsAdapter->send(['uuid' => 'c-2', 'phone' => '+31600000002'], 'Hallo');
		self::assertSame('sent', $outcome['status']);
		self::assertCount(1, $this->smsSent);
	}//end testANormalContactGetsTheSms()

	public function testStopBySmsReachesIntegriqAndWritesNoPipelinqRecord(): void {
		$result = $this->smsAdapter->handleInboundWebhook((string)json_encode(['From' => '+31612345678', 'Body' => 'STOP']), 'sig', 'prov-1');

		self::assertTrue($result['optOutRecorded']);
		$rows = $this->integriq->rowsFor('+31612345678');
		self::assertCount(1, $rows);
		self::assertSame('opted-out', $rows[0]['state']);
		self::assertSame('sms', $rows[0]['channel']);
		self::assertSame('keyword-stop', $rows[0]['source']);
		self::assertSame([], $this->store->ofSchema('messagingConsentRecord'));
	}//end testStopBySmsReachesIntegriqAndWritesNoPipelinqRecord()

	public function testAnSmsReplyToTheContactsOwnMessagePassesTheOptOut(): void {
		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms']);
		$this->store->saveObject(['uuid' => 'in-1', 'contactId' => 'c-1', 'channel' => 'sms', 'direction' => 'inbound', 'body' => 'Wanneer?'], 'pipelinq', 'channelMessage');
		$this->store->saveObject(['uuid' => 'in-x', 'contactId' => 'someone-else', 'channel' => 'sms', 'direction' => 'inbound'], 'pipelinq', 'channelMessage');

		$foreign = $this->smsAdapter->send(['uuid' => 'c-1', 'phone' => '+31612345678'], 'Antwoord', null, ['inReplyTo' => 'in-x']);
		self::assertSame('consentMissing', $foreign['status'], 'a message of somebody else is no reply');

		$reply = $this->smsAdapter->send(['uuid' => 'c-1', 'phone' => '+31612345678'], 'Morgen om tien uur', null, ['inReplyTo' => 'in-1']);
		self::assertSame('sent', $reply['status']);
		$last = end($this->integriq->decisionEvents);
		self::assertSame('reply', $last->getCategory());
		self::assertSame('in-1', $last->getInReplyTo());
	}//end testAnSmsReplyToTheContactsOwnMessagePassesTheOptOut()

	public function testAWhatsAppAnswerInsideTheSessionIsSentAsReply(): void {
		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'whatsapp']);
		$this->store->saveObject(
			['uuid' => 'wa-in-1', 'contactId' => 'c-1', 'channel' => 'whatsapp', 'direction' => 'inbound', 'sentAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 600)],
			'pipelinq',
			'channelMessage'
		);

		$this->whatsAppClient->expects($this->once())->method('sendFreeForm');
		$outcome = $this->whatsAppAdapter->send(['uuid' => 'c-1', 'phone' => '+31612345678'], 'Graag gedaan');

		self::assertSame('sent', $outcome['status']);
		$event = $this->integriq->decisionEvents[0];
		self::assertSame('reply', $event->getCategory());
		self::assertSame('wa-in-1', $event->getInReplyTo());
	}//end testAWhatsAppAnswerInsideTheSessionIsSentAsReply()

	public function testAWhatsAppTemplateIsNotAReplyAndNeedsConsent(): void {
		$this->store->saveObject(['uuid' => 'tpl-1', 'status' => 'approved', 'externalId' => 'reminder', 'language' => 'nl', 'body' => 'Hoi'], 'pipelinq', 'messageTemplate');
		$this->whatsAppClient->expects($this->never())->method('sendTemplate');

		$outcome = $this->whatsAppAdapter->send(['uuid' => 'c-3', 'phone' => '+31600000003'], '', 'tpl-1', []);

		self::assertSame('consentMissing', $outcome['status']);
		self::assertTrue($this->integriq->decisionEvents[0]->requiresConsent());
		self::assertNull($this->integriq->decisionEvents[0]->getInReplyTo());
	}//end testAWhatsAppTemplateIsNotAReplyAndNeedsConsent()
}//end class
