<?php

/**
 * Unit tests for BrpCacheService.
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
use OCA\Pipelinq\Service\BrpCacheService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The BRP person cache: lookup of the newest unexpired entry by BSN hash,
 * storing with a retention stamp, invalidation, and the configured TTL.
 *
 * @covers \OCA\Pipelinq\Service\BrpCacheService
 * @uses   \OCA\Pipelinq\Service\BsnValidationService
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004
 */
class BrpCacheServiceTest extends TestCase {
	private const BSN = '123456782';

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var ObjectServiceInterface&MockObject */
	private ObjectServiceInterface $objectService;

	/** @var array<string,string> */
	private array $config = [
		'register' => 'pipelinq',
		'brpPersoon_schema' => 'brpPersoon',
		'brp.cache_ttl_hours' => '24',
	];

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
	 * @return BrpCacheService
	 */
	private function service(): BrpCacheService {
		return new BrpCacheService($this->appConfig, $this->logger, $this->objectService);
	}//end service()

	/**
	 * An ISO timestamp relative to now (UTC).
	 *
	 * @param string $modifier A DateTime modifier such as '+2 hours'.
	 *
	 * @return string
	 */
	private static function at(string $modifier): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modifier)->format(DATE_ATOM);
	}//end at()

	/**
	 * An OR entity whose jsonSerialize gives $data.
	 *
	 * @param mixed $data The serialised value.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(mixed $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * The lookup filters on the SHA-256 hash of the BSN, never the BSN itself.
	 *
	 * @return void
	 */
	public function testGetQueriesByBsnHashInTheConfiguredSchema(): void {
		$this->objectService->expects(self::once())->method('findAll')->with(
			[
				'filters' => [
					'bsnHash' => hash('sha256', self::BSN),
					'register' => 'pipelinq',
					'schema' => 'brpPersoon',
				],
			]
		)->willReturn([]);

		self::assertNull($this->service()->get(self::BSN));
	}//end testGetQueriesByBsnHashInTheConfiguredSchema()

	/**
	 * Of the unexpired entries the most recently fetched wins; expired, unstamped
	 * and unreadable entries are skipped.
	 *
	 * @return void
	 */
	public function testGetReturnsTheNewestUnexpiredEntry(): void {
		$older = ['id' => 'older', 'retentionTo' => self::at('+5 hours'), 'fetchedOn' => '2026-01-01T08:00:00+00:00'];
		$newer = ['id' => 'newer', 'retentionTo' => self::at('+1 hour'), 'fetchedOn' => '2026-01-02T08:00:00+00:00'];
		$expiredButNewest = ['id' => 'expired', 'retentionTo' => self::at('-1 minute'), 'fetchedOn' => '2027-01-01T08:00:00+00:00'];
		$unstamped = ['id' => 'unstamped', 'fetchedOn' => '2028-01-01T08:00:00+00:00'];
		$unreadable = ['id' => 'unreadable', 'retentionTo' => 'not a date', 'fetchedOn' => '2028-01-01T08:00:00+00:00'];
		$this->objectService->method('findAll')->willReturn(
			[$older, $expiredButNewest, $this->entity($newer), $unstamped, $unreadable, 'junk']
		);

		$found = $this->service()->get(self::BSN);

		self::assertSame('newer', $found['id']);
	}//end testGetReturnsTheNewestUnexpiredEntry()

	/**
	 * An older fetch listed after a newer one does not replace it.
	 *
	 * @return void
	 */
	public function testGetKeepsTheNewerEntryWhenAnOlderFollows(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				['id' => 'newer', 'retentionTo' => self::at('+1 hour'), 'fetchedOn' => '2026-02-01T00:00:00+00:00'],
				['id' => 'older', 'retentionTo' => self::at('+9 hours'), 'fetchedOn' => '2026-01-01T00:00:00+00:00'],
			]
		);

		self::assertSame('newer', $this->service()->get(self::BSN)['id']);
	}//end testGetKeepsTheNewerEntryWhenAnOlderFollows()

	/**
	 * Only expired entries is a cache miss.
	 *
	 * @return void
	 */
	public function testGetMissesWhenEveryEntryExpired(): void {
		$this->objectService->method('findAll')->willReturn(
			[['id' => 'x', 'retentionTo' => self::at('-1 hour'), 'fetchedOn' => '2026-01-01T00:00:00+00:00']]
		);

		self::assertNull($this->service()->get(self::BSN));
	}//end testGetMissesWhenEveryEntryExpired()

	/**
	 * A failing lookup is logged without the BSN and answers a miss.
	 *
	 * @return void
	 */
	public function testGetLogsAndMissesWhenTheLookupFails(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error')->with(
			'BRP cache lookup failed',
			['error' => 'db down']
		);

		self::assertNull($this->service()->get(self::BSN));
	}//end testGetLogsAndMissesWhenTheLookupFails()

	/**
	 * Without a configured schema there is no lookup and no data leaves.
	 *
	 * @return void
	 */
	public function testGetMissesWithoutConfiguredSchema(): void {
		$this->config['brpPersoon_schema'] = '';
		$this->objectService->expects(self::never())->method('findAll');
		$this->logger->expects(self::once())->method('error')->with(
			'BRP cache lookup failed',
			['error' => 'brpPersoon register/schema not configured.']
		);

		self::assertNull($this->service()->get(self::BSN));
	}//end testGetMissesWithoutConfiguredSchema()

	/**
	 * Storing stamps fetchedOn (when absent) and retentionTo = now + TTL.
	 *
	 * @return void
	 */
	public function testSetStampsRetentionWithTheGivenTtl(): void {
		$saved = null;
		$this->objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema) use (&$saved) {
				$saved = [$object, $extend, $register, $schema];
				return $this->entity(['id' => 'uuid-1'] + $object);
			}
		);

		$before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$result = $this->service()->set(['bsnHash' => 'h', 'naam' => 'Jansen'], 3);

		[$object, $extend, $register, $schema] = $saved;
		self::assertSame([], $extend);
		self::assertSame('pipelinq', $register);
		self::assertSame('brpPersoon', $schema);
		self::assertSame('Jansen', $object['naam']);
		$retention = new DateTimeImmutable($object['retentionTo']);
		$fetched = new DateTimeImmutable($object['fetchedOn']);
		self::assertEqualsWithDelta(3 * 3600, $retention->getTimestamp() - $fetched->getTimestamp(), 1);
		self::assertGreaterThanOrEqual($before->getTimestamp(), $fetched->getTimestamp());
		self::assertSame('uuid-1', $result['id']);
		self::assertSame($object['retentionTo'], $result['retentionTo']);
	}//end testSetStampsRetentionWithTheGivenTtl()

	/**
	 * A given fetchedOn is kept; a TTL below one hour is raised to one; the
	 * configured TTL applies when none is given.
	 *
	 * @return void
	 */
	public function testSetKeepsFetchedOnAndClampsTheTtl(): void {
		$objects = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$objects) {
				$objects[] = $object;
				return $this->entity($object);
			}
		);
		$this->config['brp.cache_ttl_hours'] = '48';
		$service = $this->service();

		$service->set(['fetchedOn' => '2026-01-01T00:00:00+00:00'], 0);
		$service->set([]);

		self::assertSame('2026-01-01T00:00:00+00:00', $objects[0]['fetchedOn']);
		$now = time();
		self::assertEqualsWithDelta($now + 3600, (new DateTimeImmutable($objects[0]['retentionTo']))->getTimestamp(), 5);
		self::assertEqualsWithDelta($now + 48 * 3600, (new DateTimeImmutable($objects[1]['retentionTo']))->getTimestamp(), 5);
	}//end testSetKeepsFetchedOnAndClampsTheTtl()

	/**
	 * A saved entity that does not serialise to an array comes back empty.
	 *
	 * @return void
	 */
	public function testSetReturnsEmptyForANonArrayEntity(): void {
		$this->objectService->method('saveObject')->willReturn($this->entity('scalar'));

		self::assertSame([], $this->service()->set(['x' => 1], 2));
	}//end testSetReturnsEmptyForANonArrayEntity()

	/**
	 * Storing without configuration throws instead of writing anywhere.
	 *
	 * @return void
	 */
	public function testSetThrowsWithoutConfiguredRegister(): void {
		$this->config['register'] = '';
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('brpPersoon register/schema not configured.');
		$this->service()->set(['x' => 1]);
	}//end testSetThrowsWithoutConfiguredRegister()

	/**
	 * Invalidation re-saves every entry with an id under that id, expired, and
	 * counts them; entries without an id are skipped.
	 *
	 * @return void
	 */
	public function testInvalidateExpiresEveryIdentifiedEntry(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				['@self' => ['id' => 'self-id'], 'retentionTo' => self::at('+5 hours')],
				$this->entity(['id' => 'plain-id', 'retentionTo' => self::at('+5 hours')]),
				['retentionTo' => self::at('+5 hours')],
			]
		);
		$saves = [];
		$this->objectService->expects(self::exactly(2))->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$saves) {
				$saves[] = [$uuid, $object['retentionTo'], $register, $schema];
				return $this->entity($object);
			}
		);

		$count = $this->service()->invalidate(self::BSN);

		self::assertSame(2, $count);
		self::assertSame(['self-id', 'plain-id'], array_column($saves, 0));
		foreach ($saves as [, $retention, $register, $schema]) {
			self::assertLessThan(time(), (new DateTimeImmutable($retention))->getTimestamp());
			self::assertSame('pipelinq', $register);
			self::assertSame('brpPersoon', $schema);
		}
	}//end testInvalidateExpiresEveryIdentifiedEntry()

	/**
	 * A failing invalidation is logged and counts zero.
	 *
	 * @return void
	 */
	public function testInvalidateLogsAndCountsZeroOnFailure(): void {
		$this->objectService->method('findAll')->willReturn([['id' => 'a']]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects(self::once())->method('error')->with(
			'BRP cache invalidate failed',
			['error' => 'locked']
		);

		self::assertSame(0, $this->service()->invalidate(self::BSN));
	}//end testInvalidateLogsAndCountsZeroOnFailure()

	/**
	 * The TTL setting is read from app config; zero, negative or garbage falls
	 * back to 24 hours.
	 *
	 * @return void
	 */
	public function testConfiguredTtlFallsBackToADay(): void {
		$service = $this->service();
		foreach (['12' => 12, '1' => 1, '0' => 24, '-5' => 24, 'abc' => 24] as $raw => $expected) {
			$this->config['brp.cache_ttl_hours'] = (string)$raw;
			self::assertSame($expected, $service->getConfiguredTtlHours(), "raw '$raw'");
		}

		unset($this->config['brp.cache_ttl_hours']);
		self::assertSame(24, $service->getConfiguredTtlHours());
	}//end testConfiguredTtlFallsBackToADay()
}//end class
