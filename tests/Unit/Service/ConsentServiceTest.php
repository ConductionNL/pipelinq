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
					default => $default,
				};
			}
		);

		$this->service = new ConsentService($this->container, $this->appConfig, $this->logger);
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
		$this->service->recordOptOut('contact-1', 'sms', 'keyword-stop', 'stop');
		// Force the next record to land later — usort uses
		// recordedAt and gmdate seconds. Add a delay.
		sleep(1);
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
}//end class
