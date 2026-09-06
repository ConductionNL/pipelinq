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
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
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

	/**
	 * The signal service the suppression rule reads the dunning state from.
	 *
	 * @var SegmentSignalService
	 */
	private SegmentSignalService $signals;

	/**
	 * Contact id to the dunning state the signal service answers with.
	 *
	 * @var array<string, string>
	 */
	private array $dunning = [];
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

		$this->appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => $default
		);

		$this->signals = $this->createMock(SegmentSignalService::class);
		$this->signals->method('dunningStateForContact')->willReturnCallback(
			fn (string $contactId): ?string => ($this->dunning[$contactId] ?? null)
		);

		$this->service = new ComplianceService($this->container,
			$this->appConfig,
			$this->segmentService,
			$this->logger,
			$this->signals,
		);
	}//end setUp()

	/**
	 * A promotional send is skipped for a customer in dunning; the same
	 * contact still receives a service message, because an invoice reminder
	 * and a delivery notice have to reach a late payer.
	 *
	 * @return void
	 */
	public function testAPromotionalSendIsSuppressedForAnOverdueCustomer(): void {
		$this->seedConsent('contact-1', 'email', 'consent');
		$this->dunning['contact-1'] = 'overdue';

		$promotional = $this->service->permitsSend('contact-1', 'email', ComplianceService::INTENT_PROMOTIONAL);
		$this->assertFalse($promotional['allowed']);
		$this->assertSame(ComplianceService::REASON_SUPPRESSED, $promotional['reason']);

		$service = $this->service->permitsSend('contact-1', 'email', ComplianceService::INTENT_SERVICE);
		$this->assertTrue($service['allowed']);
		$this->assertSame('', $service['reason']);
	}//end testAPromotionalSendIsSuppressedForAnOverdueCustomer()

	/**
	 * A service message is never suppressed, whatever the state.
	 *
	 * @return void
	 */
	public function testAServiceMessageIsNeverSuppressed(): void {
		$this->seedConsent('contact-1', 'email', 'consent');

		foreach (['overdue', 'written-off', 'disputed'] as $state) {
			$this->dunning['contact-1'] = $state;
			$this->assertFalse($this->service->isSuppressed('contact-1', ComplianceService::INTENT_SERVICE));
		}
	}//end testAServiceMessageIsNeverSuppressed()

	/**
	 * A dunning state nobody can read does NOT suppress. Refusing to mail
	 * everybody the moment shillinq is uninstalled is worse than mailing a
	 * late payer once.
	 *
	 * @return void
	 */
	public function testAnUnreadableDunningStateDoesNotSuppress(): void {
		$this->seedConsent('contact-1', 'email', 'consent');

		$this->assertFalse($this->service->isSuppressed('contact-1'));
		$this->assertTrue($this->service->permitsSend('contact-1', 'email')['allowed']);
	}//end testAnUnreadableDunningStateDoesNotSuppress()

	/**
	 * A contact who is current with the bookkeeping is not suppressed.
	 *
	 * @return void
	 */
	public function testACurrentCustomerIsNotSuppressed(): void {
		$this->seedConsent('contact-1', 'email', 'consent');
		$this->dunning['contact-1'] = 'current';

		$this->assertFalse($this->service->isSuppressed('contact-1'));
	}//end testACurrentCustomerIsNotSuppressed()

	/**
	 * No consent beats suppression: the gate reports the reason that would
	 * still block the send if the invoice were paid tomorrow.
	 *
	 * @return void
	 */
	public function testNoConsentIsReportedBeforeSuppression(): void {
		$this->dunning['contact-2'] = 'overdue';

		$gate = $this->service->permitsSend('contact-2', 'email');

		$this->assertFalse($gate['allowed']);
		$this->assertSame(ComplianceService::REASON_NO_CONSENT, $gate['reason']);
	}//end testNoConsentIsReportedBeforeSuppression()

	/**
	 * Compliance separates the contact it MAY NOT mail from the contact it
	 * chose not to. Collapsing the two would report a lawful campaign as
	 * non-compliant, and a compliance flag that cries wolf gets ignored.
	 *
	 * @return void
	 */
	public function testSuppressedContactsAreReportedSeparatelyFromMissingConsent(): void {
		$this->seedConsent('contact-1', 'email', 'consent');
		$this->dunning['contact-1'] = 'overdue';

		$this->segmentService->method('getMembersForBlast')->willReturn([
			['contactId' => 'contact-1'],
			['contactId' => 'contact-2'],
		]);

		$result = $this->service->checkSegmentCompliance('segment-1', 'email');

		$this->assertSame(['contact-2'], $result['missingConsent']);
		$this->assertSame(['contact-1'], $result['suppressed']);
		$this->assertFalse($result['compliant']);
	}//end testSuppressedContactsAreReportedSeparatelyFromMissingConsent()

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

	/**
	 * hasConsentForList: a confirmed subscription's list record opens that
	 * list.
	 *
	 * @return void
	 */
	public function testHasConsentForListConfirmedSubscription(): void {
		$this->seedConsent('c-list', 'email', 'consent', ['listId' => 'list-news']);

		$this->assertTrue($this->service->hasConsentForList('c-list', 'list-news', 'email'));
	}//end testHasConsentForListConfirmedSubscription()

	/**
	 * A list-scoped record does NOT open the channel, and a channel-wide
	 * record does NOT open a list.
	 *
	 * This is the regression the nullable list scope exists to prevent:
	 * before it, `findConsentRecord()` returned the first row matching
	 * (contactId, channel), so the moment a list record existed it could
	 * answer for the whole channel.
	 *
	 * @return void
	 */
	public function testListScopeAndChannelScopeDoNotLeakIntoEachOther(): void {
		$this->seedConsent('c-scoped', 'email', 'consent', ['listId' => 'list-news']);

		$this->assertTrue($this->service->hasConsentForList('c-scoped', 'list-news', 'email'));
		$this->assertFalse($this->service->hasConsentForChannel('c-scoped', 'email'));
		$this->assertFalse($this->service->hasConsentForList('c-scoped', 'list-other', 'email'));
	}//end testListScopeAndChannelScopeDoNotLeakIntoEachOther()

	/**
	 * A withdrawn list record closes that list.
	 *
	 * @return void
	 */
	public function testHasConsentForListWithdrawn(): void {
		$this->seedConsent('c-gone', 'email', 'consent', [
			'listId' => 'list-news',
			'withdrawnAt' => '2026-08-01T10:00:00Z',
			'withdrawnReason' => 'user-unsubscribed',
		]);

		$this->assertFalse($this->service->hasConsentForList('c-gone', 'list-news', 'email'));
	}//end testHasConsentForListWithdrawn()

	/**
	 * Soft opt-in permits a send only with the objection recorded.
	 *
	 * @return void
	 */
	public function testSoftOptInBasisSatisfiesConsentOnlyWithEvidence(): void {
		$this->seedConsent('c-soft-ok', 'email', 'soft-opt-in', [
			'listId' => 'list-updates',
			'evidence' => ['objectionOffered' => true, 'objectionOfferedAt' => '2026-06-04T10:00:00Z'],
		]);
		$this->seedConsent('c-soft-bare', 'email', 'soft-opt-in', ['listId' => 'list-updates']);

		$this->assertTrue($this->service->hasConsentForList('c-soft-ok', 'list-updates', 'email'));
		$this->assertFalse($this->service->hasConsentForList('c-soft-bare', 'list-updates', 'email'));
	}//end testSoftOptInBasisSatisfiesConsentOnlyWithEvidence()

	/**
	 * recordListConsent writes a list-scoped record, and reopens rather than
	 * duplicating one that was withdrawn.
	 *
	 * @return void
	 */
	public function testRecordListConsentReopensRatherThanDuplicating(): void {
		$this->seedConsent('c-back', 'email', 'consent', [
			'listId' => 'list-news',
			'withdrawnAt' => '2026-08-01T10:00:00Z',
			'withdrawnReason' => 'user-unsubscribed',
		]);

		$this->service->recordListConsent(
			'c-back',
			'list-news',
			'email',
			'consent',
			'double-opt-in',
			['objectionOffered' => true],
		);

		$this->assertEmpty($this->objectService->saved, 'an existing record must be reopened, not duplicated');
		$this->assertTrue($this->service->hasConsentForList('c-back', 'list-news', 'email'));
	}//end testRecordListConsentReopensRatherThanDuplicating()

	/**
	 * A list-scoped withdrawal closes that list and leaves the channel-wide
	 * record alone.
	 *
	 * @return void
	 */
	public function testWithdrawalScopedToAListLeavesTheChannelRecordAlone(): void {
		$this->seedConsent('c-both', 'email', 'consent');
		$this->seedConsent('c-both', 'email', 'consent', [
			'uuid' => 'consent-c-both-email-list',
			'listId' => 'list-news',
		]);

		$this->service->recordConsentWithdrawal('c-both', 'email', 'user-unsubscribed', null, 'list-news');

		$this->assertFalse($this->service->hasConsentForList('c-both', 'list-news', 'email'));
		$this->assertTrue($this->service->hasConsentForChannel('c-both', 'email'));
	}//end testWithdrawalScopedToAListLeavesTheChannelRecordAlone()
}//end class
