<?php

/**
 * Behaviour tests for OptOutService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\OptOutService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which opt-out flag is active on a day, and what the BRP-derived and local
 * opt-out records hold when they are written.
 *
 * @covers \OCA\Pipelinq\Service\OptOutService
 * @uses   \OCA\Pipelinq\Service\BsnValidationService
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-006
 */
class OptOutServiceBehaviourTest extends TestCase {
	private const BSN = '123456782';

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface $objectService;

	/** @var array<string,string> */
	private array $config = ['register' => 'pipelinq', 'optOutVlag_schema' => 'optOutVlag'];

	/** @var array<int,array<string,mixed>> */
	private array $saves = [];

	/**
	 * Wire the mocks; app config answers from $this->config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return OptOutService
	 */
	private function service(): OptOutService {
		return new OptOutService($this->appConfig, $this->logger, $this->objectService);
	}//end service()

	/**
	 * A date relative to today (UTC), Y-m-d.
	 *
	 * @param string $modifier A DateTime modifier.
	 *
	 * @return string
	 */
	private static function day(string $modifier): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d');
	}//end day()

	/**
	 * Record every saveObject call.
	 *
	 * @return void
	 */
	private function recordSaves(): void {
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null): ObjectEntityInterface {
				$this->saves[] = ['object' => $object, 'extend' => $extend, 'register' => $register, 'schema' => $schema];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
	}//end recordSaves()

	/**
	 * The lookup filters on the BSN hash in the configured register and schema.
	 *
	 * @return void
	 */
	public function testGetOptOutQueriesByBsnHash(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			[
				'filters' => [
					'bsnHash' => hash('sha256', self::BSN),
					'register' => 'pipelinq',
					'schema' => 'optOutVlag',
				],
			]
		)->willReturn([]);

		self::assertNull($this->service()->getOptOut(self::BSN));
	}//end testGetOptOutQueriesByBsnHash()

	/**
	 * The first flag in force today is returned; flags not yet in force, ended,
	 * without or with an unreadable start date are not.
	 *
	 * @return void
	 */
	public function testGetOptOutReturnsTheFirstFlagInForce(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'active-entity', 'effectiveDate' => self::day('-10 days'), 'endDate' => self::day('+10 days')]);
		$this->objectService->method('findAll')->willReturn(
			[
				['id' => 'future', 'effectiveDate' => self::day('+1 day')],
				['id' => 'ended', 'effectiveDate' => self::day('-30 days'), 'endDate' => self::day('-1 day')],
				['id' => 'no-start'],
				['id' => 'bad-start', 'effectiveDate' => 'someday'],
				'junk',
				$entity,
				['id' => 'later-active', 'effectiveDate' => self::day('-1 day')],
			]
		);

		$found = $this->service()->getOptOut(self::BSN);

		self::assertSame('active-entity', $found['id']);
	}//end testGetOptOutReturnsTheFirstFlagInForce()

	/**
	 * An empty end date means open-ended, and an unreadable one keeps the flag
	 * in force rather than dropping the protection.
	 *
	 * @return void
	 */
	public function testAnOpenOrUnreadableEndDateKeepsTheFlagInForce(): void {
		foreach (['', 'not a date'] as $endDate) {
			$this->objectService = $this->createMock(ObjectServiceInterface::class);
			$this->objectService->method('findAll')->willReturn(
				[['id' => 'flag', 'effectiveDate' => self::day('-1 year'), 'endDate' => $endDate]]
			);
			self::assertTrue($this->service()->hasOptOut(self::BSN), "endDate '$endDate'");
		}
	}//end testAnOpenOrUnreadableEndDateKeepsTheFlagInForce()

	/**
	 * A flag starting today is in force; a non-array entity is no flag.
	 *
	 * @return void
	 */
	public function testAFlagStartingTodayIsInForce(): void {
		$scalar = $this->createMock(ObjectEntityInterface::class);
		$scalar->method('jsonSerialize')->willReturn('x');
		$this->objectService->method('findAll')->willReturn([$scalar, ['id' => 'today', 'effectiveDate' => self::day('today')]]);

		self::assertSame('today', $this->service()->getOptOut(self::BSN)['id']);
	}//end testAFlagStartingTodayIsInForce()

	/**
	 * A failed lookup is logged without the BSN and reads as no opt-out.
	 *
	 * @return void
	 */
	public function testAFailedLookupIsLoggedAndReadsAsNone(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error')->with('OptOut lookup failed', ['error' => 'db down']);

		self::assertFalse($this->service()->hasOptOut(self::BSN));
	}//end testAFailedLookupIsLoggedAndReadsAsNone()

	/**
	 * A secret BRP indication without a flag yet records a municipality
	 * confidentiality flag from today.
	 *
	 * @return void
	 */
	public function testRecordFromBrpResponseWritesTheConfidentialityFlag(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->recordSaves();

		self::assertTrue($this->service()->recordFromBrpResponse(self::BSN, '1'));

		self::assertCount(1, $this->saves);
		self::assertSame(
			[
				'bsnHash' => hash('sha256', self::BSN),
				'type' => 'confidentiality-municipality',
				'source' => 'BRP',
				'effectiveDate' => self::day('today'),
				'limited' => ['commerciele-derden', 'kerkgenootschappen', 'derdeportalen'],
			],
			$this->saves[0]['object']
		);
		self::assertSame([], $this->saves[0]['extend']);
		self::assertSame('pipelinq', $this->saves[0]['register']);
		self::assertSame('optOutVlag', $this->saves[0]['schema']);
	}//end testRecordFromBrpResponseWritesTheConfidentialityFlag()

	/**
	 * An existing active flag is not duplicated.
	 *
	 * @return void
	 */
	public function testRecordFromBrpResponseSkipsWhenAFlagIsInForce(): void {
		$this->objectService->method('findAll')->willReturn([['effectiveDate' => self::day('-1 day')]]);
		$this->objectService->expects(self::never())->method('saveObject');

		self::assertFalse($this->service()->recordFromBrpResponse(self::BSN, '1'));
	}//end testRecordFromBrpResponseSkipsWhenAFlagIsInForce()

	/**
	 * A failed write is logged and reported as not recorded.
	 *
	 * @return void
	 */
	public function testRecordFromBrpResponseReportsAFailedWrite(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects(self::once())->method('error')->with('OptOut record from BRP response failed', ['error' => 'locked']);

		self::assertFalse($this->service()->recordFromBrpResponse(self::BSN, '1'));
	}//end testRecordFromBrpResponseReportsAFailedWrite()

	/**
	 * A local opt-out records who entered it and the note; a missing note is
	 * left out of the object.
	 *
	 * @return void
	 */
	public function testRecordLocalOptOutWritesTheLocalFlag(): void {
		$this->recordSaves();
		$service = $this->service();

		self::assertTrue($service->recordLocalOptOut(self::BSN, 'kcc-agent', 'Asked by phone'));
		self::assertTrue($service->recordLocalOptOut(self::BSN, 'kcc-agent'));

		self::assertSame(
			[
				'bsnHash' => hash('sha256', self::BSN),
				'type' => 'local-contact-opt-out',
				'source' => 'local',
				'effectiveDate' => self::day('today'),
				'limited' => ['commerciele-derden'],
				'localEnteredBy' => 'kcc-agent',
				'note' => 'Asked by phone',
			],
			$this->saves[0]['object']
		);
		self::assertArrayNotHasKey('note', $this->saves[1]['object']);
		self::assertSame('kcc-agent', $this->saves[1]['object']['localEnteredBy']);
	}//end testRecordLocalOptOutWritesTheLocalFlag()

	/**
	 * Without a configured schema a local opt-out is logged and not recorded.
	 *
	 * @return void
	 */
	public function testRecordLocalOptOutWithoutConfiguration(): void {
		$this->config['optOutVlag_schema'] = '';
		$this->objectService->expects(self::never())->method('saveObject');
		$this->logger->expects(self::once())->method('error')->with(
			'OptOut local record failed',
			['error' => 'optOutVlag register/schema not configured.']
		);

		self::assertFalse($this->service()->recordLocalOptOut(self::BSN, 'kcc-agent'));
	}//end testRecordLocalOptOutWithoutConfiguration()
}//end class
