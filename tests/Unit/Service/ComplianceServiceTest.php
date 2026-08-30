<?php

/**
 * Unit tests for ComplianceService.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-09-unit-integration-tests/tasks.md#complianceservice-tests-task-4.2-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ComplianceService — segment compliance gating, per-channel
 * consent lookup, template validation, and consent withdrawal propagation.
 *
 * Approach (ADR-008): mock ObjectService + SegmentService. Realistic
 * ConsentRecord rows derived from the member 01 seed config exercise the
 * lawful-basis matrix end-to-end so each test asserts production behaviour.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-09-unit-integration-tests/tasks.md#complianceservice-tests-task-4.2-of-giant
 */
class ComplianceServiceTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private SegmentService $segmentService;
	private LoggerInterface $logger;
	private object $objectService;

	/**
	 * Service under test, instantiated in setUp().
	 *
	 * @var ComplianceService
	 */
	private ComplianceService $service;

	/**
	 * Set up — wire the in-memory ObjectService double + mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->segmentService = $this->createMock(SegmentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/** @var array<int, array<string, mixed>> */
			public array $updates = [];

			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $consentRecords = [];

			/** @var array<int, array<string, mixed>> */
			public array $deliveries = [];

			/**
			 * Mock find() — returns a stored object by id.
			 *
			 * @param string $id Identifier.
			 * @param mixed $register Register slug.
			 * @param mixed $schema Schema slug.
			 *
			 * @return array<string, mixed>|null Payload or null.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}

			/**
			 * Mock findAll() — returns ConsentRecord rows or BlastDelivery
			 * rows depending on the schema slug, filtered by the supplied
			 * key-value map.
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config): the
			 * register/schema context travels INSIDE $config['filters'] and OR
			 * treats both as reserved params, never as object-field filters.
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>> Rows.
			 */
			public function findAll(array $config = []): array {
				$filters = $config['filters'] ?? [];
				$schema = $filters['schema'] ?? null;
				unset($filters['register'], $filters['schema']);

				if ($schema === 'consentRecord') {
					$source = $this->consentRecords;
				} elseif ($schema === 'blastDelivery') {
					$source = $this->deliveries;
				} else {
					$source = [];
				}

				$out = [];
				foreach ($source as $row) {
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
			 * Mock saveObject() — records the saved payload.
			 *
			 * @param array $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Existing id.
			 *
			 * @return array<string, mixed> The saved payload.
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($uuid === null || $uuid === '') {
					$uuid = ('saved-' . count($this->saved));
				}
				$object['uuid'] = $uuid;
				$this->saved[] = $object;
				$this->store[$uuid] = $object;
				if ($schema === 'consentRecord') {
					$this->consentRecords[] = $object;
				}
				return $object;
			}

			/**
			 * Mock updateObject() — records the update payload + writes it
			 * back to the in-memory store keyed by id. Mirrors the row
			 * back into the consentRecord / blastDelivery collections so
			 * subsequent findAll() calls see the patch.
			 *
			 * @param string $id Identifier.
			 * @param array $object Updated payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed> The updated payload.
			 */
			public function updateObject(string $id, array $object, $register = null, $schema = null): array {
				$object['uuid'] = $id;
				$this->updates[] = ['id' => $id, 'object' => $object, 'schema' => $schema];
				$this->store[$id] = $object;
				if ($schema === 'consentRecord') {
					foreach ($this->consentRecords as $idx => $row) {
						if (($row['uuid'] ?? null) === $id) {
							$this->consentRecords[$idx] = $object;
							return $object;
						}
					}
					$this->consentRecords[] = $object;
				}
				if ($schema === 'blastDelivery') {
					foreach ($this->deliveries as $idx => $row) {
						if (($row['uuid'] ?? null) === $id) {
							$this->deliveries[$idx] = $object;
							return $object;
						}
					}
					$this->deliveries[] = $object;
				}
				return $object;
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
					'consent_record_schema' => 'consentRecord',
					'blast_delivery_schema' => 'blastDelivery',
					default => $default,
				};
			}
		);

		$this->service = new ComplianceService($this->container,
			$this->appConfig,
			$this->segmentService,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Push a ConsentRecord row into the in-memory backing store.
	 *
	 * @param string $contactId Contact id.
	 * @param string $channel Channel.
	 * @param string $lawfulBasis Basis.
	 * @param array<string, mixed> $extra Optional overrides.
	 *
	 * @return void
	 */
	private function seedConsent(string $contactId, string $channel, string $lawfulBasis, array $extra = []): void {
		$row = array_merge(
			[
				'uuid' => 'consent-' . $contactId . '-' . $channel,
				'contactId' => $contactId,
				'channel' => $channel,
				'lawfulBasis' => $lawfulBasis,
				'consentedAt' => '2026-01-01T00:00:00Z',
			],
			$extra,
		);
		$this->objectService->consentRecords[] = $row;
	}//end seedConsent()

	/**
	 * checkSegmentCompliance: when every member has consent, the result
	 * is `compliant: true` with an empty missing list.
	 *
	 * @return void
	 */
	public function testCheckSegmentComplianceAllCompliant(): void {
		$this->segmentService->method('getMembersForBlast')->willReturn([
			['contactId' => 'c1', 'email' => 'a@example.test'],
			['contactId' => 'c2', 'email' => 'b@example.test'],
		]);
		$this->seedConsent('c1', 'email', 'consent');
		$this->seedConsent('c2', 'email', 'consent');

		$result = $this->service->checkSegmentCompliance('seg-1', 'email');

		$this->assertTrue($result['compliant']);
		$this->assertSame([], $result['missingConsent']);
		$this->assertSame(0, $result['missingCount']);
	}//end testCheckSegmentComplianceAllCompliant()

	/**
	 * checkSegmentCompliance: members without a usable ConsentRecord
	 * are surfaced in `missingConsent`, the result is `compliant: false`.
	 *
	 * @return void
	 */
	public function testCheckSegmentComplianceMissingContacts(): void {
		$this->segmentService->method('getMembersForBlast')->willReturn([
			['contactId' => 'c1', 'email' => 'a@example.test'],
			['contactId' => 'c2', 'email' => 'b@example.test'],
			['contactId' => 'c3', 'email' => 'c@example.test'],
		]);
		$this->seedConsent('c1', 'email', 'consent');
		// c2 has consent but for SMS only.
		$this->seedConsent('c2', 'sms', 'consent');
		// c3 has an "imported" ConsentRecord — fail-safe excludes it.
		$this->seedConsent('c3', 'email', 'imported');

		$result = $this->service->checkSegmentCompliance('seg-2', 'email');

		$this->assertFalse($result['compliant']);
		$this->assertEqualsCanonicalizing(['c2', 'c3'], $result['missingConsent']);
		$this->assertSame(2, $result['missingCount']);
	}//end testCheckSegmentComplianceMissingContacts()

	/**
	 * checkSegmentCompliance: empty member list short-circuits to
	 * `compliant: true, missingCount: 0`.
	 *
	 * @return void
	 */
	public function testCheckSegmentComplianceShortCircuitsEmptySegment(): void {
		$this->segmentService->method('getMembersForBlast')->willReturn([]);
		$result = $this->service->checkSegmentCompliance('seg-empty', 'email');
		$this->assertTrue($result['compliant']);
		$this->assertSame(0, $result['missingCount']);
	}//end testCheckSegmentComplianceShortCircuitsEmptySegment()

	/**
	 * validateTemplate: an email template missing the
	 * `{{unsubscribe_link}}` token is rejected (GDPR Art. 7(3)).
	 *
	 * @return void
	 */
	public function testValidateTemplateRejectsEmailWithoutUnsubscribeToken(): void {
		$template = [
			'bodyHtml' => '<p>Hello {{firstName}}.</p>',
			'bodyText' => 'Hello.',
			'footerOverride' => '',
		];
		$error = $this->service->validateTemplate($template, 'email');
		$this->assertIsString($error);
		$this->assertStringContainsString('{{unsubscribe_link}}', (string)$error);
	}//end testValidateTemplateRejectsEmailWithoutUnsubscribeToken()

	/**
	 * validateTemplate: an email template carrying `{{unsubscribe_link}}`
	 * but no physical-address signal is rejected (CAN-SPAM § 7704(a)(5)).
	 *
	 * @return void
	 */
	public function testValidateTemplateRejectsEmailWithoutAddress(): void {
		$template = [
			'bodyHtml' => '<p>Hello. <a href="{{unsubscribe_link}}">Unsubscribe</a></p>',
			'bodyText' => 'Hello. Unsubscribe: {{unsubscribe_link}}',
			'footerOverride' => '',
		];
		$error = $this->service->validateTemplate($template, 'email');
		$this->assertIsString($error);
		$this->assertStringContainsString('physical-address', (string)$error);
	}//end testValidateTemplateRejectsEmailWithoutAddress()

	/**
	 * validateTemplate: an email template with the unsubscribe token AND
	 * a recognised physical-address token is accepted (returns null).
	 *
	 * @return void
	 */
	public function testValidateTemplateAcceptsValidEmail(): void {
		$template = [
			'bodyHtml' => '<p>Hello. <a href="{{unsubscribe_link}}">Unsubscribe</a>{{physical_address}}</p>',
			'bodyText' => 'Hello. Unsubscribe: {{unsubscribe_link}}',
			'footerOverride' => '',
		];
		$this->assertNull($this->service->validateTemplate($template, 'email'));
	}//end testValidateTemplateAcceptsValidEmail()

	/**
	 * validateTemplate: an email template with the unsubscribe token AND
	 * a non-empty footerOverride (operator literal address) is accepted.
	 *
	 * @return void
	 */
	public function testValidateTemplateAcceptsEmailWithFooterOverride(): void {
		$template = [
			'bodyHtml' => '<p>Hello. {{unsubscribe_link}}</p>',
			'bodyText' => 'Hello. {{unsubscribe_link}}',
			'footerOverride' => "Conduction B.V.\nNieuwe Uitleg 56\nDen Haag",
		];
		$this->assertNull($this->service->validateTemplate($template, 'email'));
	}//end testValidateTemplateAcceptsEmailWithFooterOverride()

	/**
	 * validateTemplate: SMS templates are exempt from email-specific
	 * rules and pass through unconditionally.
	 *
	 * @return void
	 */
	public function testValidateTemplateAcceptsSmsRegardlessOfTokens(): void {
		$template = [
			'bodyText' => 'Welcome — reply STOP to unsubscribe.',
		];
		$this->assertNull($this->service->validateTemplate($template, 'sms'));
	}//end testValidateTemplateAcceptsSmsRegardlessOfTokens()

	/**
	 * hasConsentForChannel: a ConsentRecord with lawfulBasis `consent`
	 * and no withdrawnAt opens the channel.
	 *
	 * @return void
	 */
	public function testHasConsentForChannelActiveConsent(): void {
		$this->seedConsent('c-active', 'email', 'consent');
		$this->assertTrue($this->service->hasConsentForChannel('c-active', 'email'));
	}//end testHasConsentForChannelActiveConsent()

	/**
	 * hasConsentForChannel: a ConsentRecord with a withdrawnAt timestamp
	 * blocks the send (Art. 7(3)).
	 *
	 * @return void
	 */
	public function testHasConsentForChannelWithdrawnConsent(): void {
		$this->seedConsent(
			'c-withdrawn',
			'email',
			'consent',
			['withdrawnAt' => '2026-02-15T11:23:00Z', 'withdrawnReason' => 'user-unsubscribed'],
		);
		$this->assertFalse($this->service->hasConsentForChannel('c-withdrawn', 'email'));
	}//end testHasConsentForChannelWithdrawnConsent()

	/**
	 * hasConsentForChannel: lawfulBasis "imported" never permits a send
	 * (ADR-005 fail-safe).
	 *
	 * @return void
	 */
	public function testHasConsentForChannelImportedNotSatisfying(): void {
		$this->seedConsent('c-imported', 'email', 'imported');
		$this->assertFalse($this->service->hasConsentForChannel('c-imported', 'email'));
	}//end testHasConsentForChannelImportedNotSatisfying()

	/**
	 * hasConsentForChannel: a contact with NO ConsentRecord row
	 * defaults to closed (fail-safe).
	 *
	 * @return void
	 */
	public function testHasConsentForChannelNoRecordReturnsFalse(): void {
		$this->assertFalse($this->service->hasConsentForChannel('c-unknown', 'email'));
	}//end testHasConsentForChannelNoRecordReturnsFalse()

	/**
	 * recordConsentWithdrawal: updates the existing ConsentRecord with
	 * withdrawnAt + withdrawnReason and transitions queued deliveries
	 * for the same contact to `unsubscribed-before-send`.
	 *
	 * @return void
	 */
	public function testRecordConsentWithdrawalUpdatesRecordAndTransitionsDeliveries(): void {
		$this->seedConsent('c-x', 'email', 'consent');
		$this->objectService->deliveries = [
			['uuid' => 'd-queued', 'contactId' => 'c-x', 'blastId' => 'b-1', 'status' => 'queued'],
			['uuid' => 'd-sent',   'contactId' => 'c-x', 'blastId' => 'b-1', 'status' => 'sent'],
			['uuid' => 'd-other',  'contactId' => 'other', 'blastId' => 'b-1', 'status' => 'queued'],
		];

		$this->service->recordConsentWithdrawal('c-x', 'email', 'user-unsubscribed', 'b-1');

		// ConsentRecord update was issued with a withdrawnAt + reason.
		$consentUpdates = array_filter($this->objectService->updates,
			fn (array $row) => ($row['schema'] ?? null) === 'consentRecord',
		);
		$this->assertNotEmpty($consentUpdates, 'ConsentRecord update must be issued');
		$consentObject = array_values($consentUpdates)[0]['object'];
		$this->assertNotEmpty((string)($consentObject['withdrawnAt'] ?? ''));
		$this->assertSame('user-unsubscribed', $consentObject['withdrawnReason']);

		// Queued delivery for the same contact was flipped to
		// unsubscribed-before-send; sent rows + other-contact rows
		// remained untouched.
		$deliveryUpdates = array_filter($this->objectService->updates,
			fn (array $row) => ($row['schema'] ?? null) === 'blastDelivery',
		);
		$touchedIds = array_map(
			fn (array $row) => $row['id'],
			$deliveryUpdates,
		);
		$this->assertContains('d-queued', $touchedIds);
		$this->assertNotContains('d-sent', $touchedIds, 'sent rows must not transition');
		$this->assertNotContains('d-other', $touchedIds, 'other contact must not transition');

		$touchedObject = array_values(array_filter($deliveryUpdates,
			fn (array $row) => $row['id'] === 'd-queued',
		))[0]['object'];
		$this->assertSame('unsubscribed-before-send', $touchedObject['status']);
	}//end testRecordConsentWithdrawalUpdatesRecordAndTransitionsDeliveries()

	/**
	 * recordConsentWithdrawal: with no existing ConsentRecord, creates a
	 * synthetic withdrawal-ledger row so audit history is preserved
	 * (GDPR Art. 7(3)).
	 *
	 * @return void
	 */
	public function testRecordConsentWithdrawalCreatesAuditLedgerWhenNoRecordExists(): void {
		$this->service->recordConsentWithdrawal('c-fresh', 'email', 'bounce-hard', 'b-2');

		$this->assertNotEmpty($this->objectService->saved, 'audit-ledger ConsentRecord must be created');
		$created = end($this->objectService->saved);
		$this->assertSame('c-fresh', $created['contactId']);
		$this->assertSame('email', $created['channel']);
		$this->assertSame('bounce-hard', $created['withdrawnReason']);
		$this->assertNotEmpty((string)($created['withdrawnAt'] ?? ''));
	}//end testRecordConsentWithdrawalCreatesAuditLedgerWhenNoRecordExists()

	/**
	 * recordConsentWithdrawal: a second call on an already-withdrawn
	 * record preserves the original withdrawnAt timestamp (no overwrite).
	 *
	 * @return void
	 */
	public function testRecordConsentWithdrawalKeepsFirstWithdrawalTimestamp(): void {
		$this->seedConsent(
			'c-y',
			'email',
			'consent',
			['withdrawnAt' => '2026-01-15T10:00:00Z', 'withdrawnReason' => 'user-unsubscribed'],
		);

		$this->service->recordConsentWithdrawal('c-y', 'email', 'complaint', 'b-3');

		$consentUpdates = array_filter($this->objectService->updates,
			fn (array $row) => ($row['schema'] ?? null) === 'consentRecord',
		);
		$this->assertEmpty($consentUpdates,
			'already-withdrawn ConsentRecord must not be patched (preserves first-withdrawal timestamp)',
		);
	}//end testRecordConsentWithdrawalKeepsFirstWithdrawalTimestamp()

	/**
	 * preflightBlast: combined validateTemplate + checkSegmentCompliance
	 * returns `valid: true` only when both pass.
	 *
	 * @return void
	 */
	public function testPreflightBlastReturnsValidWhenAllChecksPass(): void {
		$this->segmentService->method('getMembersForBlast')->willReturn([
			['contactId' => 'c1', 'email' => 'a@example.test'],
		]);
		$this->seedConsent('c1', 'email', 'consent');

		$template = [
			'bodyHtml' => '<p>{{unsubscribe_link}} {{physical_address}}</p>',
			'bodyText' => '{{unsubscribe_link}}',
		];
		$result = $this->service->preflightBlast('seg-pre', $template, 'email');

		$this->assertTrue($result['valid']);
		$this->assertNull($result['templateError']);
		$this->assertTrue($result['segmentCompliance']['compliant']);
	}//end testPreflightBlastReturnsValidWhenAllChecksPass()
}//end class
