<?php

/**
 * Unit tests for ConsentService.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ConsentService — opt-out gate, append-only history,
 * keyword detection, and GDPR erasure cascade.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.3
 */
class ConsentServiceTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private object $objectService;
	private ConsentService $service;
	private FakeIntegriq $integriq;
	private string $store = 'pipelinq';
	private string $cutoverAt = '0';

	/**
	 * Build a tiny OR mock — find / findAll / saveObject /
	 * deleteObject — that mirrors the BlastServiceTest pattern.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var int */
			public int $nextId = 0;

			/** @var int */
			public int $deleted = 0;

			/** @var array<string, array<string, mixed>> Contacts by id. */
			public array $contacts = [];

			/**
			 * Mock find() for contacts.
			 *
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				if ($schema === 'contact') {
					return ($this->contacts[$id] ?? null);
				}
				return null;
			}

			/**
			 * Mock saveObject().
			 *
			 * @param array $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Existing id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($uuid === null || $uuid === '') {
					$uuid = ('row-' . (++$this->nextId));
				}
				$object['uuid'] = $uuid;
				$this->store[$uuid] = $object;
				return $object;
			}

			/**
			 * Mock findAll() — mirrors OR's real ObjectService::findAll(array $config).
			 *
			 * The register/schema context travels INSIDE $config['filters']; OR
			 * treats both as reserved params, never as object-field filters, so
			 * they are stripped before the row match.
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = $config['filters'] ?? [];
				unset($filters['register'], $filters['schema']);

				$out = [];
				foreach ($this->store as $row) {
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) !== $v) {
							continue 2;
						}
					}
					$out[] = $row;
				}
				return $out;
			}

			/**
			 * Mock deleteObject().
			 *
			 * @param string $uuid Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return void
			 */
			public function deleteObject(string $uuid, $register = null, $schema = null): void {
				if (isset($this->store[$uuid]) === true) {
					unset($this->store[$uuid]);
					$this->deleted++;
				}
			}
		};

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'messagingConsentRecord_schema' => 'messagingConsentRecord',
					'consent.store' => $this->store,
					'consent.cutover_at' => $this->cutoverAt,
					default => $default,
				};
			}
		);

		$this->integriq = new FakeIntegriq();
		$this->objectService->contacts['contact-1'] = ['uuid' => 'contact-1', 'phone' => '+31612345678'];
		$this->service = new ConsentService($this->container, $this->appConfig, $this->logger, FakeIntegriq::client($this->appConfig, $this->integriq), new ContactAddressLookup($this->container, $this->appConfig, $this->logger));
	}//end setUp()

	/**
	 * Opt-out keyword detection is case-insensitive and ignores
	 * whitespace.
	 *
	 * @return void
	 */
	public function testIsOptOutKeyword(): void {
		$this->assertTrue($this->service->isOptOutKeyword('STOP'));
		$this->assertTrue($this->service->isOptOutKeyword('stop'));
		$this->assertTrue($this->service->isOptOutKeyword(' StopAll '));
		$this->assertTrue($this->service->isOptOutKeyword('UITSCHRIJVEN'));
		$this->assertFalse($this->service->isOptOutKeyword('STOP NOW'));
		$this->assertFalse($this->service->isOptOutKeyword('YES'));
	}//end testIsOptOutKeyword()

	/**
	 * Opt-in keyword detection.
	 *
	 * @return void
	 */
	public function testIsOptInKeyword(): void {
		$this->assertTrue($this->service->isOptInKeyword('JA'));
		$this->assertTrue($this->service->isOptInKeyword('ja'));
		$this->assertTrue($this->service->isOptInKeyword('Start'));
		$this->assertFalse($this->service->isOptInKeyword('STOP'));
	}//end testIsOptInKeyword()

	/**
	 * canSend returns true when no consent record exists (the
	 * default-allow policy lets agents capture explicit consent
	 * later).
	 *
	 * @return void
	 */
	public function testCanSendDefaultsToTrueWithoutRecord(): void {
		$this->assertTrue($this->service->canSend('contact-1', 'sms'));
	}//end testCanSendDefaultsToTrueWithoutRecord()

	/**
	 * canSend returns false when the most recent record is opted-out.
	 *
	 * @return void
	 */
	public function testCanSendIsFalseForOptedOut(): void {
		$this->service->recordOptOut('contact-1', 'sms', 'keyword-stop', 'stop body');
		$this->assertFalse($this->service->canSend('contact-1', 'sms'));
	}//end testCanSendIsFalseForOptedOut()

	/**
	 * canSend on a different channel for the same contact is
	 * independent.
	 *
	 * @return void
	 */
	public function testCanSendIsPerChannel(): void {
		$this->service->recordOptOut('contact-1', 'sms', 'keyword-stop', 'stop sms');
		$this->assertFalse($this->service->canSend('contact-1', 'sms'));
		$this->assertTrue($this->service->canSend('contact-1', 'whatsapp'));
	}//end testCanSendIsPerChannel()

	/**
	 * History is append-only — an opt-in following an opt-out keeps
	 * the older row in the store and the latest wins.
	 *
	 * @return void
	 */
	public function testOptInAfterOptOutKeepsHistory(): void {
		// NO sleep(1) here, deliberately. There used to be one, with the comment
		// "Force the next record to land later — usort uses recordedAt and
		// gmdate seconds", and it was a workaround for a real ordering bug that
		// it did not reliably work around: this test failed roughly one run in
		// five. recordedAt now carries microseconds and the comparison parses
		// instants, so two changes in the same second order correctly and the
		// back-to-back calls below are the stronger test.
		$this->service->recordOptOut('contact-1', 'sms', 'keyword-stop', 'stop');
		$this->service->recordOptIn('contact-1', 'sms', 'chat-reply', 'JA');

		$this->assertTrue($this->service->canSend('contact-1', 'sms'));
		$this->assertCount(2, $this->objectService->store);
	}//end testOptInAfterOptOutKeepsHistory()

	/**
	 * deleteForContact removes every record (GDPR erasure).
	 *
	 * @return void
	 */
	public function testDeleteForContactRemovesAllRecords(): void {
		$this->service->recordOptOut('contact-1', 'sms', 'keyword-stop', 'stop');
		$this->service->recordOptIn('contact-1', 'sms', 'admin-override', 'admin');
		$this->service->recordOptOut('contact-2', 'whatsapp', 'admin-override', 'admin');

		$deleted = $this->service->deleteForContact('contact-1');

		$this->assertSame(2, $deleted);
		$this->assertCount(1, $this->objectService->store);
		$this->assertTrue($this->service->canSend('contact-1', 'sms'));
	}//end testDeleteForContactRemovesAllRecords()

	/**
	 * canSendBusinessInitiated requires an explicit opted-in record —
	 * absent record does NOT pass (Meta business-messaging policy).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-005--consent-gating-and-recording
	 */
	public function testBusinessInitiatedRequiresOptIn(): void {
		// No record → blocked (unlike canSend which defaults to allow).
		$this->assertFalse($this->service->canSendBusinessInitiated('contact-1', 'whatsapp'));

		$this->service->recordOptIn('contact-1', 'whatsapp', 'webform', 'signed up', 'consent');
		$this->assertTrue($this->service->canSendBusinessInitiated('contact-1', 'whatsapp'));

		// A later opt-out flips it back to blocked.
		sleep(1);
		$this->service->recordOptOut('contact-1', 'whatsapp', 'admin-override', 'withdrew', 'consent');
		$this->assertFalse($this->service->canSendBusinessInitiated('contact-1', 'whatsapp'));
	}//end testBusinessInitiatedRequiresOptIn()

	/**
	 * latestState reflects the newest record, or `unknown` when absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-005--consent-gating-and-recording
	 */
	public function testLatestStateReflectsNewestRecord(): void {
		$this->assertSame('unknown', $this->service->latestState('contact-9', 'sms'));

		$this->service->recordOptIn('contact-9', 'sms', 'webform', 'opted in', 'consent');
		$this->assertSame('opted-in', $this->service->latestState('contact-9', 'sms'));

		sleep(1);
		$this->service->recordOptOut('contact-9', 'sms', 'keyword-stop', 'STOP', 'consent');
		$this->assertSame('opted-out', $this->service->latestState('contact-9', 'sms'));
	}//end testLatestStateReflectsNewestRecord()

	/**
	 * The recorded legal basis is persisted on the consent row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-005--consent-gating-and-recording
	 */
	public function testLegalBasisPersisted(): void {
		$saved = $this->service->recordOptIn('contact-3', 'whatsapp', 'webform', 'evidence', 'legitimate-interest');
		$this->assertIsArray($saved);
		$this->assertSame('legitimate-interest', $saved['legalBasis']);
	}//end testLegalBasisPersisted()
	/**
	 * With the flag on, an opt-out in integriq refuses the send and pipelinq's store is not read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function testAfterTheCutoverIntegriqDecides(): void {
		$this->service->recordOptIn('contact-1', 'sms', 'webform', 'yes', 'consent');
		$this->store = 'integriq';
		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms']);

		$this->assertFalse($this->service->canSend('contact-1', 'sms'));
		$this->assertSame('pipelinq', $this->integriq->decisionEvents[0]->getSourceApp());
		$this->assertSame('+31612345678', $this->integriq->decisionEvents[0]->getRecipients()[0]['address']);

		// A reply to the contact's own message passes the opt-out.
		$this->assertTrue($this->service->canSend('contact-1', 'sms', '', 'inbound-1'));
		$this->assertSame('reply', $this->integriq->decisionEvents[1]->getCategory());
	}//end testAfterTheCutoverIntegriqDecides()

	/**
	 * With the flag off, behaviour is unchanged and integriq is never asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function testBeforeTheCutoverPipelinqDecides(): void {
		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms']);
		$this->assertTrue($this->service->canSend('contact-1', 'sms'));
		$this->assertSame([], $this->integriq->decisionEvents);
	}//end testBeforeTheCutoverPipelinqDecides()

	/**
	 * Without integriq after the cutover, a service SMS is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function testAfterTheCutoverAnUnansweredQuestionRefuses(): void {
		$this->store = 'integriq';
		$this->integriq->mode = FakeIntegriq::MODE_UNHANDLED;
		$this->assertFalse($this->service->canSend('contact-1', 'sms'));
		$this->assertFalse($this->service->canSendBusinessInitiated('contact-1', 'whatsapp'));
	}//end testAfterTheCutoverAnUnansweredQuestionRefuses()

	/**
	 * latestState() reads each of the three states from the decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-lateststate-is-derived-from-integriq-s-decision-req-cii-006
	 */
	public function testLatestStateIsDerivedFromTheDecision(): void {
		$this->store = 'integriq';
		$this->assertSame('unknown', $this->service->latestState('contact-1', 'sms'));

		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'whatsapp', 'lawfulBasis' => 'consent']);
		$this->assertSame('opted-in', $this->service->latestState('contact-1', 'whatsapp'));
		$this->assertTrue($this->service->canSendBusinessInitiated('contact-1', 'whatsapp'));

		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms']);
		$this->assertSame('opted-out', $this->service->latestState('contact-1', 'sms'));

		foreach ($this->integriq->decisionEvents as $event) {
			$this->assertTrue($event->requiresConsent());
		}
	}//end testLatestStateIsDerivedFromTheDecision()

	/**
	 * latestState() only shows a state, so it asks as a probe; a send does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/latest-state-probe/specs/consent-in-integriq/spec.md#requirement-lateststate-asks-integriq-as-a-probe-req-cii-007
	 */
	public function testLatestStateAsksAsAProbe(): void {
		$this->store = 'integriq';
		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'sms']);

		$this->assertSame('opted-out', $this->service->latestState('contact-1', 'sms'));
		$probe = end($this->integriq->decisionEvents);
		$this->assertTrue($probe->isProbe(), 'showing a state is a probe');

		$this->assertFalse($this->service->canSend('contact-1', 'sms'));
		$send = end($this->integriq->decisionEvents);
		$this->assertFalse($send->isProbe(), 'a send is asked for real, so it is logged');
	}//end testLatestStateAsksAsAProbe()

	/**
	 * STOP after the cutover writes to integriq and not to pipelinq.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function testAStopAfterTheCutoverGoesToIntegriqOnly(): void {
		$this->store = 'integriq';
		$saved = $this->service->recordOptOut('contact-1', 'sms', 'keyword-stop', 'STOP', 'consent');

		$this->assertSame('integriq', $saved['store']);
		$this->assertSame([], $this->objectService->store);
		$rows = $this->integriq->rowsFor('+31612345678');
		$this->assertCount(1, $rows);
		$this->assertSame('opted-out', $rows[0]['state']);
		$this->assertSame('keyword-stop', $rows[0]['source']);
		$this->assertSame('contact-1', $rows[0]['contactRef']);
	}//end testAStopAfterTheCutoverGoesToIntegriqOnly()

	/**
	 * A refused STOP stays in pipelinq and is replayed, before a newer wish.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function testARefusedStopIsKeptAndReplayed(): void {
		$this->store = 'integriq';
		$this->cutoverAt = (string)(time() - 60);
		$this->integriq->changeMode = FakeIntegriq::MODE_UNHANDLED;

		$saved = $this->service->recordOptOut('contact-1', 'sms', 'keyword-stop', 'STOP', 'consent');
		$this->assertSame('opted-out', $saved['state']);
		$this->assertCount(1, $this->objectService->store);
		$this->assertSame([], $this->integriq->rows);

		$this->integriq->changeMode = FakeIntegriq::MODE_ANSWER;
		$this->assertSame(['replayed' => 1, 'failed' => 0], $this->service->replayPending());
		$this->assertSame('opted-out', $this->integriq->rowsFor('+31612345678')[0]['state']);

		// Replaying again writes nothing new.
		$this->service->replayPending();
		$this->assertCount(1, $this->integriq->rows);
	}//end testARefusedStopIsKeptAndReplayed()
}//end class
