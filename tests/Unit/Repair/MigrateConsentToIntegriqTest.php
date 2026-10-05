<?php

/**
 * The consent migration into integriq: the five scenarios of REQ-CII-001.
 *
 * Real ConsentService, ComplianceService, IntegriqMarketingConsent and
 * IntegriqConsentClient over one in-memory register and a fake integriq
 * behind a real IEventDispatcher, driven through the repair step.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\MigrateConsentToIntegriq;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ConsentMigrationService;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCA\Pipelinq\Service\IntegriqMarketingConsent;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SegmentService;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Migration scenarios.
 */
class MigrateConsentToIntegriqTest extends TestCase {

	private FakeIntegriq $integriq;

	private InMemoryObjectService $store;

	/** @var array<string, string> */
	private array $config = [];

	private IAppConfig $appConfig;

	private ContainerInterface $container;

	protected function setUp(): void {
		$this->integriq = new FakeIntegriq();
		$this->store = new InMemoryObjectService();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(fn () => $this->store);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? match ($key) {
				'register' => 'pipelinq',
				'consent_record_schema' => 'consentRecord',
				default => $default,
			})
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$this->store->put('contact', ['uuid' => 'c-1', 'phone' => '+31612345678', 'email' => 'jan@example.nl']);
		$this->store->put('contact', ['uuid' => 'c-2', 'email' => 'piet@example.nl']);
		$this->store->put('contact', ['uuid' => 'c-3']);
	}//end setUp()

	private function step(string $decisionEvent = IntegriqConsentClient::DECISION_EVENT, string $changeEvent = IntegriqConsentClient::CHANGE_EVENT): MigrateConsentToIntegriq {
		$logger = new NullLogger();
		$urls = $this->createMock(IURLGenerator::class);
		$client = new IntegriqConsentClient($this->integriq, $this->appConfig, $urls, $logger, $decisionEvent, $changeEvent);
		$addresses = new ContactAddressLookup($this->container, $this->appConfig, $logger);
		$marketing = new IntegriqMarketingConsent($client, $addresses);
		$consent = new ConsentService($this->container, $this->appConfig, $logger, $client, $addresses);
		$compliance = new ComplianceService(
			$this->container,
			$this->appConfig,
			$this->createMock(SegmentService::class),
			$logger,
			$this->createMock(SegmentSignalService::class),
			$marketing
		);
		$migration = new ConsentMigrationService($client, $consent, $compliance, $marketing, $addresses, $this->appConfig, $logger);
		return new MigrateConsentToIntegriq($migration, $logger);
	}//end step()

	private function seedPipelinq(): void {
		// An older opt-in and a later STOP on sms: the latest wins.
		$this->store->put('messagingConsentRecord', ['uuid' => 'm-1', 'contactId' => 'c-1', 'channel' => 'sms', 'state' => 'opted-in', 'source' => 'webform', 'legalBasis' => 'consent', 'recordedAt' => '2026-09-01T10:00:00Z']);
		$this->store->put('messagingConsentRecord', ['uuid' => 'm-2', 'contactId' => 'c-1', 'channel' => 'sms', 'state' => 'opted-out', 'source' => 'keyword-stop', 'recordedAt' => '2026-09-02T10:00:00Z']);
		$this->store->put('consentRecord', ['uuid' => 'r-1', 'contactId' => 'c-2', 'channel' => 'email', 'listId' => 'nieuws', 'lawfulBasis' => 'consent', 'consentSource' => 'double-opt-in', 'consentedAt' => '2026-09-01T00:00:00Z']);
		$this->store->put('consentRecord', ['uuid' => 'r-2', 'contactId' => 'c-1', 'channel' => 'email', 'lawfulBasis' => 'consent', 'consentedAt' => '2026-09-01T00:00:00Z', 'withdrawnAt' => '2026-09-03T00:00:00Z', 'withdrawnReason' => 'bounce-hard']);
		$this->store->put('messagingConsentRecord', ['uuid' => 'm-3', 'contactId' => 'c-3', 'channel' => 'sms', 'state' => 'opted-out', 'recordedAt' => '2026-09-02T10:00:00Z']);
	}//end seedPipelinq()

	public function testAStopFromBeforeTheMigrationIsHonouredElsewhere(): void {
		$this->seedPipelinq();
		$this->step()->run($this->createMock(IOutput::class));

		$rows = $this->integriq->rowsFor('+31612345678');
		self::assertCount(1, $rows);
		self::assertSame(['opted-out', 'channel', 'sms'], [$rows[0]['state'], $rows[0]['scope'], $rows[0]['channel']]);
		self::assertSame('pipelinq:messagingConsentRecord:m-2', $rows[0]['legacyRef']);

		// integriq's own SMS to that number is refused now.
		$client = FakeIntegriq::client($this->appConfig, $this->integriq);
		self::assertFalse($client->decideOne(channel: 'sms', category: 'service', requiresConsent: false, address: '+31612345678')['send']);
	}//end testAStopFromBeforeTheMigrationIsHonouredElsewhere()

	public function testAListConsentIsMigratedAsAListConsent(): void {
		$this->seedPipelinq();
		$this->step()->run($this->createMock(IOutput::class));

		$rows = $this->integriq->rowsFor('piet@example.nl');
		self::assertCount(1, $rows);
		self::assertSame(['opted-in', 'list', 'nieuws', 'consent'], [$rows[0]['state'], $rows[0]['scope'], $rows[0]['ref'], $rows[0]['lawfulBasis']]);
	}//end testAListConsentIsMigratedAsAListConsent()

	public function testABounceStaysInPipelinq(): void {
		$this->seedPipelinq();
		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame([], $this->integriq->rowsFor('jan@example.nl'));
		self::assertCount(2, $this->store->ofSchema('consentRecord'), 'the old records stay as history');
	}//end testABounceStaysInPipelinq()

	public function testTheMigrationRunTwiceAddsNoRows(): void {
		$this->seedPipelinq();
		$this->step()->run($this->createMock(IOutput::class));
		self::assertSame('integriq', $this->config['consent.store']);
		$before = count($this->integriq->rows);
		$cutoverAt = $this->config['consent.cutover_at'];

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame(2, $before, 'one sms opt-out and one list consent; the bounce and the contact without a number are not migrated');
		self::assertCount($before, $this->integriq->rows);
		self::assertSame($cutoverAt, $this->config['consent.cutover_at']);
	}//end testTheMigrationRunTwiceAddsNoRows()

	public function testIntegriqMissingMigratesNothingAndKeepsTheFlag(): void {
		$this->seedPipelinq();
		$this->step(changeEvent: 'Event\\NotInstalled')->run($this->createMock(IOutput::class));

		self::assertSame([], $this->integriq->rows);
		self::assertArrayNotHasKey('consent.store', $this->config);
	}//end testIntegriqMissingMigratesNothingAndKeepsTheFlag()

	public function testARefusalKeepsTheFlagOnPipelinq(): void {
		$this->seedPipelinq();
		$this->integriq->changeMode = FakeIntegriq::MODE_UNHANDLED;
		$this->step()->run($this->createMock(IOutput::class));

		self::assertArrayNotHasKey('consent.store', $this->config);
	}//end testARefusalKeepsTheFlagOnPipelinq()
}//end class
