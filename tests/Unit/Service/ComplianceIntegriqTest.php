<?php

/**
 * ComplianceService after the cutover: integriq answers, dunning and bounces stay.
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

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\IntegriqMarketingConsent;
use OCA\Pipelinq\Service\SegmentService;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The marketing gate through a real IntegriqConsentClient and a fake integriq.
 */
class ComplianceIntegriqTest extends TestCase {

	private FakeIntegriq $integriq;

	private InMemoryObjectService $store;

	private ComplianceService $service;

	private SegmentService $segments;

	/** @var array<string, string> */
	private array $dunning = [];

	private string $flag = 'integriq';

	/**
	 * The info lines the service logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $infoLogs = [];

	protected function setUp(): void {
		$this->integriq = new FakeIntegriq();
		$this->store = new InMemoryObjectService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn () => $this->store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => match ($key) {
				'register' => 'pipelinq',
				'consent_record_schema' => 'consentRecord',
				'blast_delivery_schema' => 'blastDelivery',
				'consent.store' => $this->flag,
				default => $default,
			}
		);
		$appConfig->method('getValueBool')->willReturnCallback(fn (string $app, string $key, bool $default = false) => $default);

		$signals = $this->createMock(SegmentSignalService::class);
		$signals->method('dunningStateForContact')->willReturnCallback(fn (string $id) => ($this->dunning[$id] ?? null));
		$this->segments = $this->createMock(SegmentService::class);

		$logger = new NullLogger();
		$serviceLogger = $this->createMock(LoggerInterface::class);
		$serviceLogger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->infoLogs[] = [$message, $context];
			}
		);
		$this->service = new ComplianceService(
			$container,
			$appConfig,
			$this->segments,
			$serviceLogger,
			$signals,
			new IntegriqMarketingConsent(FakeIntegriq::client($appConfig, $this->integriq), new ContactAddressLookup($container, $appConfig, $logger)),
		);

		$this->store->put('contact', ['uuid' => 'c-1', 'email' => 'jan@example.nl']);
		$this->store->put('contact', ['uuid' => 'c-2', 'email' => 'piet@example.nl']);
	}//end setUp()

	public function testALatePayerIsStillSuppressedAfterIntegriqAllows(): void {
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->dunning['c-1'] = 'overdue';

		$gate = $this->service->permitsSend(contactId: 'c-1', channel: 'email');

		self::assertSame(['allowed' => false, 'reason' => 'suppressed_dunning'], $gate);
		self::assertSame('marketing', $this->integriq->decisionEvents[0]->getCategory());
		self::assertTrue($this->integriq->decisionEvents[0]->requiresConsent());
	}//end testALatePayerIsStillSuppressedAfterIntegriqAllows()

	public function testAServiceIntentIsAskedAsServiceWithoutConsent(): void {
		$gate = $this->service->permitsSend(contactId: 'c-2', channel: 'email', intent: ComplianceService::INTENT_SERVICE);

		self::assertTrue($gate['allowed']);
		self::assertSame('service', $this->integriq->decisionEvents[0]->getCategory());
		self::assertFalse($this->integriq->decisionEvents[0]->requiresConsent());
	}//end testAServiceIntentIsAskedAsServiceWithoutConsent()

	public function testABlastSkipsAContactWhoStoppedEverythingElsewhere(): void {
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-out', 'scope' => 'instance', 'channel' => '', 'source' => 'dossiq-unsubscribe']);
		$this->integriq->seed(['address' => 'piet@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->segments->method('getMembersForBlast')->willReturn([
			['contactId' => 'c-1', 'email' => 'jan@example.nl'],
			['contactId' => 'c-2', 'email' => 'piet@example.nl'],
		]);

		$result = $this->service->checkSegmentCompliance(segmentId: 'seg-1', channel: 'email');

		self::assertSame(['c-1'], $result['missingConsent']);
		self::assertArrayHasKey('c-2', $result['unsubscribe']);
		self::assertStringStartsWith('https://', $result['unsubscribe']['c-2']);
	}//end testABlastSkipsAContactWhoStoppedEverythingElsewhere()

	public function testASegmentOf1200ContactsMakesThreeEvents(): void {
		$members = [];
		for ($i = 0; $i < 1200; $i++) {
			$members[] = ['contactId' => 'm-'.$i, 'email' => 'm'.$i.'@example.nl'];
		}

		$this->segments->method('getMembersForBlast')->willReturn($members);
		$this->service->checkSegmentCompliance(segmentId: 'seg-big', channel: 'email');

		self::assertCount(3, $this->integriq->decisionEvents);
	}//end testASegmentOf1200ContactsMakesThreeEvents()

	public function testASegmentCheckViaIntegriqReportsEveryBucketAndOnlyRealLinks(): void {
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->integriq->seed(['address' => 'piet@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->store->put('contact', ['uuid' => 'c-3', 'email' => 'kees@example.nl']);
		$this->integriq->seed(['address' => 'kees@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->store->put('consentRecord', ['contactId' => 'c-3', 'channel' => 'email', 'withdrawnAt' => '2026-01-01T00:00:00Z', 'withdrawnReason' => 'bounce-hard']);
		$this->dunning['c-2'] = 'overdue';
		$this->segments->method('getMembersForBlast')->willReturn([
			['contactId' => 'c-1', 'email' => 'jan@example.nl'],
			['contactId' => 'c-2', 'email' => 'piet@example.nl'],
			['contactId' => 'c-3', 'email' => 'kees@example.nl'],
			['email' => 'anonymous@example.nl'],
			['contactId' => 'c-9', 'email' => 'unknown@example.nl'],
			['contactId' => 'c-1', 'email' => 'jan@example.nl'],
		]);

		$result = $this->service->checkSegmentCompliance(segmentId: 'seg-1', channel: ' EMAIL ');

		self::assertSame(['c-3', '', 'c-9'], $result['missingConsent']);
		self::assertSame(3, $result['missingCount']);
		self::assertFalse($result['compliant']);
		self::assertSame(['c-2'], $result['suppressed']);
		self::assertSame(1, $result['suppressedCount']);
		self::assertSame(['c-1', 'c-2'], array_keys($result['unsubscribe']));
		self::assertSame(['compliant', 'missingConsent', 'missingCount', 'suppressed', 'suppressedCount', 'unsubscribe'], array_keys($result));
	}//end testASegmentCheckViaIntegriqReportsEveryBucketAndOnlyRealLinks()

	public function testAnEmptySegmentIsCompliantWithoutAskingIntegriq(): void {
		$this->segments->method('getMembersForBlast')->willReturn([]);

		self::assertSame(
			['compliant' => true, 'missingConsent' => [], 'missingCount' => 0, 'suppressed' => [], 'suppressedCount' => 0],
			$this->service->checkSegmentCompliance(segmentId: 'seg-0', channel: 'email')
		);
		self::assertSame([], $this->integriq->decisionEvents);
	}//end testAnEmptySegmentIsCompliantWithoutAskingIntegriq()

	public function testASendViaIntegriqIsRefusedForABounceAndAskedForTheList(): void {
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'list', 'ref' => 'nieuws', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);

		self::assertSame(['allowed' => true, 'reason' => ''], $this->service->permitsSend(contactId: 'c-1', channel: 'Email', listId: 'nieuws'));

		$this->store->put('consentRecord', ['contactId' => 'c-1', 'channel' => 'email', 'listId' => 'nieuws', 'withdrawnAt' => '2026-01-01T00:00:00Z', 'withdrawnReason' => 'bounce-hard']);
		self::assertSame(['allowed' => false, 'reason' => ComplianceService::REASON_NO_CONSENT], $this->service->permitsSend(contactId: 'c-1', channel: 'Email', listId: 'nieuws'));
		self::assertSame(['allowed' => false, 'reason' => ComplianceService::REASON_NO_CONSENT], $this->service->permitsSend(contactId: 'c-2', channel: 'email'));
	}//end testASendViaIntegriqIsRefusedForABounceAndAskedForTheList()

	public function testABounceStaysInPipelinqAndStillExcludes(): void {
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$this->store->put('consentRecord', ['contactId' => 'c-1', 'channel' => 'email', 'lawfulBasis' => 'consent', 'consentedAt' => '2026-01-01T00:00:00Z']);

		$this->service->recordConsentWithdrawal(contactId: 'c-1', channel: 'email', reason: 'bounce-hard');

		self::assertSame([], $this->integriq->changeEvents, 'a bounce is not handed to integriq');
		self::assertSame('bounce-hard', $this->store->ofSchema('consentRecord')[0]['withdrawnReason']);
		self::assertFalse($this->service->hasConsentForChannel(contactId: 'c-1', channel: 'email'));
	}//end testABounceStaysInPipelinqAndStillExcludes()

	public function testAListUnsubscribeReachesIntegriqAsAListOptOut(): void {
		$this->service->recordConsentWithdrawal(contactId: 'c-1', channel: 'email', reason: 'user-unsubscribed', listId: 'nieuws');

		$rows = $this->integriq->rowsFor('jan@example.nl');
		self::assertCount(1, $rows);
		self::assertSame(['opted-out', 'list', 'nieuws'], [$rows[0]['state'], $rows[0]['scope'], $rows[0]['ref']]);
		self::assertSame([], $this->store->ofSchema('consentRecord'));
	}//end testAListUnsubscribeReachesIntegriqAsAListOptOut()

	public function testAWithdrawalIntegriqTookTransitionsTheQueueAndIsLogged(): void {
		$this->store->put('blastDelivery', ['uuid' => 'd-1', 'contactId' => 'c-1', 'blastId' => 'b-1', 'status' => 'queued']);

		$this->service->recordConsentWithdrawal(contactId: 'c-1', channel: ' Email ', reason: 'user-unsubscribed', sourceBlastId: 'b-1', listId: 'nieuws');

		self::assertSame('unsubscribed-before-send', $this->store->ofSchema('blastDelivery')[0]['status']);
		self::assertSame([], $this->store->ofSchema('consentRecord'));
		self::assertSame(
			[['ComplianceService.recordConsentWithdrawal: withdrawal recorded in integriq', ['contactId' => 'c-1', 'channel' => 'email', 'listId' => 'nieuws', 'reason' => 'user-unsubscribed']]],
			$this->infoLogs
		);
	}//end testAWithdrawalIntegriqTookTransitionsTheQueueAndIsLogged()

	public function testAConfirmedListSubscriptionIsAListConsentInIntegriq(): void {
		$this->service->recordListConsent(contactId: 'c-2', listId: 'nieuws', channel: 'email', lawfulBasis: 'consent', consentSource: 'double-opt-in', evidence: ['confirmedAt' => '2026-10-05']);

		self::assertSame([], $this->store->ofSchema('consentRecord'));
		self::assertTrue($this->service->hasConsentForList(contactId: 'c-2', listId: 'nieuws'));
		self::assertFalse($this->service->hasConsentForList(contactId: 'c-2', listId: 'other'));
	}//end testAConfirmedListSubscriptionIsAListConsentInIntegriq()

	public function testARefusedListConsentStaysInPipelinq(): void {
		$this->integriq->changeMode = FakeIntegriq::MODE_UNHANDLED;
		$this->service->recordListConsent(contactId: 'c-2', listId: 'nieuws', channel: 'email', lawfulBasis: 'consent', consentSource: 'double-opt-in', evidence: []);

		self::assertCount(1, $this->store->ofSchema('consentRecord'));
	}//end testARefusedListConsentStaysInPipelinq()

	public function testBeforeTheCutoverIntegriqIsNotAsked(): void {
		$this->flag = 'pipelinq';
		$this->service->permitsSend(contactId: 'c-1', channel: 'email');
		self::assertSame([], $this->integriq->decisionEvents);
	}//end testBeforeTheCutoverIntegriqIsNotAsked()
}//end class
