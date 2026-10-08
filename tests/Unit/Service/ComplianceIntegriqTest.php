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
		$this->service = new ComplianceService(
			$container,
			$appConfig,
			$this->segments,
			$logger,
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
