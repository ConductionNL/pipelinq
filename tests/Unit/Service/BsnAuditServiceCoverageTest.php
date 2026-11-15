<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\BsnAudit\LookupAudit;
use OCA\Pipelinq\Service\BsnAudit\LookupReason;
use OCA\Pipelinq\Service\BsnAudit\LookupResult;
use OCA\Pipelinq\Service\BsnAudit\LookupSubject;
use OCA\Pipelinq\Service\BsnAuditService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Coverage tests for BsnAuditService record writing and RTBF pseudonymisation.
 *
 * @covers \OCA\Pipelinq\Service\BsnAuditService
 * @uses   \OCA\Pipelinq\Service\BsnValidationService
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupAudit
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupReason
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupResult
 * @uses   \OCA\Pipelinq\Service\BsnAudit\LookupSubject
 * @uses   \OCA\Pipelinq\Util\EntityAccessorTrait
 */
class BsnAuditServiceCoverageTest extends TestCase {

	private const BSN = '123456782';

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * Configure the app-config values returned for each key.
	 *
	 * @param array<string, string> $values Key => value map.
	 *
	 * @return void
	 */
	private function configure(array $values): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($values): string {
				return ($values[$key] ?? $default);
			}
		);
	}//end configure()

	/**
	 * Build the service under test.
	 *
	 * @return BsnAuditService The service.
	 */
	private function service(): BsnAuditService {
		return new BsnAuditService($this->appConfig, $this->request, $this->logger, $this->objectService);
	}//end service()

	/**
	 * Build an entity mock.
	 *
	 * @param array<string, mixed> $payload jsonSerialize payload.
	 * @param string|null $uuid getUuid value.
	 *
	 * @return ObjectEntity&MockObject The entity.
	 */
	private function entity(array $payload = [], ?string $uuid = null): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		$entity->method('getUuid')->willReturn($uuid);
		return $entity;
	}//end entity()

	/**
	 * recordLookup persists a hashed, null-free record and returns the entity UUID.
	 *
	 * @return void
	 */
	public function testRecordLookupPersistsRecordAndReturnsUuid(): void {
		$this->configure(['register' => 'reg-1', 'bsnAuditRecord_schema' => 'audit-s']);
		$this->request->method('getRemoteAddress')->willReturn('192.168.10.77');

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object, ?array $extend, $register, $schema) use (&$captured) {
					$captured = [$object, $extend, $register, $schema];
					return $this->entity(uuid: 'audit-uuid');
				}
			);
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'BSN audit record written',
				[
					'action' => 'brp-lookup-executed',
					'actor' => 'alice',
					'bsn' => '***4567*',
					'outcome' => 'succeeded',
				]
			);
		$this->logger->expects($this->never())->method('error');

		$uuid = $this->service()->recordLookup(
			actor: 'alice',
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: self::BSN),
				reason: new LookupReason(verzoekreden: 'zaak', purposeBinding: 'wmo', linkedRequest: null, vogScreening: false),
				result: new LookupResult(outcome: 'succeeded', responseCode: 200),
			),
		);

		self::assertSame('audit-uuid', $uuid);
		[$record, $extend, $register, $schema] = $captured;
		self::assertSame([], $extend);
		self::assertSame('reg-1', $register);
		self::assertSame('audit-s', $schema);
		self::assertSame(hash('sha256', self::BSN), $record['bsnHash']);
		self::assertSame('192.168.10.0', $record['ipAddress']);
		self::assertSame(200, $record['responseCode']);
		self::assertSame('alice', $record['actor']);
		self::assertSame('Pipelinq/pipelinq (Nextcloud)', $record['userAgent']);
		self::assertArrayNotHasKey('linkedRequest', $record);
		self::assertArrayNotHasKey('actorRole', $record);
		self::assertArrayNotHasKey('haalcentraalCorrelationId', $record);
		self::assertStringNotContainsString(self::BSN, json_encode($record));

		$moment = new DateTimeImmutable($record['moment']);
		self::assertSame(
			$moment->modify('+5 years')->format(DATE_ATOM),
			$record['retainUntil']
		);
	}//end testRecordLookupPersistsRecordAndReturnsUuid()

	/**
	 * recordLookup stores a caller-supplied subject hash instead of hashing an empty BSN.
	 *
	 * @return void
	 */
	public function testRecordLookupUsesSuppliedSubjectHash(): void {
		$this->configure(['register' => 'reg-1', 'bsnAuditRecord_schema' => 'audit-s']);
		$this->request->method('getRemoteAddress')->willReturn('');

		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					static function (array $record): bool {
						return $record['bsnHash'] === 'precomputed-hash'
							&& $record['ipAddress'] === ''
							&& $record['actorRole'] === 'kcc'
							&& $record['linkedRequest'] === 'req-9'
							&& $record['haalcentraalCorrelationId'] === 'corr-1'
							&& $record['action'] === 'brp-address-revealed';
					}
				)
			)
			->willReturn($this->entity(uuid: null));

		$uuid = $this->service()->recordLookup(
			actor: 'bob',
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: '', bsnHash: 'precomputed-hash'),
				reason: new LookupReason(verzoekreden: 'zaak', purposeBinding: 'wmo', linkedRequest: 'req-9', vogScreening: true),
				result: new LookupResult(outcome: 'succeeded', action: 'brp-address-revealed', correlationId: 'corr-1'),
				actorRole: 'kcc',
			),
		);

		// The entity carries no UUID: the accessor yields an empty handle.
		self::assertSame('', $uuid);
	}//end testRecordLookupUsesSuppliedSubjectHash()

	/**
	 * recordLookup logs (masked) and returns '' when the save throws.
	 *
	 * @return void
	 */
	public function testRecordLookupReturnsEmptyWhenSaveThrows(): void {
		$this->configure(['register' => 'reg-1', 'bsnAuditRecord_schema' => 'audit-s']);
		$this->request->method('getRemoteAddress')->willReturn('10.0.0.1');
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('disk full'));
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'BSN audit record write failed',
				[
					'action' => 'brp-lookup-executed',
					'actor' => 'alice',
					'bsn' => '***4567*',
					'error' => 'disk full',
				]
			);

		$uuid = $this->service()->recordLookup(
			actor: 'alice',
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: self::BSN),
				reason: new LookupReason(verzoekreden: 'zaak', purposeBinding: 'wmo', linkedRequest: null, vogScreening: false),
				result: new LookupResult(outcome: 'failed'),
			),
		);

		self::assertSame('', $uuid);
	}//end testRecordLookupReturnsEmptyWhenSaveThrows()

	/**
	 * pseudonymise skips (returns 0, warns) when no pseudonym secret is configured.
	 *
	 * @return void
	 */
	public function testPseudonymiseSkipsWithoutSecret(): void {
		$this->configure(['register' => 'reg-1', 'bsnAuditRecord_schema' => 'audit-s']);
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())
			->method('warning')
			->with('No pseudonym secret configured; RTBF pseudonymise skipped', ['actor' => 'system']);

		self::assertSame(0, $this->service()->pseudonymise(self::BSN));
	}//end testPseudonymiseSkipsWithoutSecret()

	/**
	 * pseudonymise returns 0 and logs when the register is not configured.
	 *
	 * @return void
	 */
	public function testPseudonymiseReturnsZeroWhenNotConfigured(): void {
		$this->configure(['brp.pseudonym_secret' => 's3cret']);
		$this->objectService->expects($this->never())->method('findAll');
		$this->logger->expects($this->once())
			->method('error')
			->with('BSN audit pseudonymise failed', ['error' => 'bsnAuditRecord register/schema not configured.']);

		self::assertSame(0, $this->service()->pseudonymise(self::BSN));
	}//end testPseudonymiseReturnsZeroWhenNotConfigured()

	/**
	 * pseudonymise rewrites every identifiable record with the HMAC pseudonym and skips id-less ones.
	 *
	 * @return void
	 */
	public function testPseudonymiseRewritesIdentifiableRecords(): void {
		$this->configure(['register' => 'reg-1', 'bsnAuditRecord_schema' => 'audit-s', 'brp.pseudonym_secret' => 's3cret']);
		$oldHash = hash('sha256', self::BSN);
		$newHash = hash_hmac('sha256', self::BSN, 's3cret');

		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['bsnHash' => $oldHash, 'register' => 'reg-1', 'schema' => 'audit-s']])
			->willReturn([
				['@self' => ['id' => 'u-1'], 'bsnHash' => $oldHash, 'actor' => 'alice'],
				['id' => 'u-2', 'bsnHash' => $oldHash],
				['bsnHash' => $oldHash],
				$this->entity(['id' => 'u-3', 'bsnHash' => $oldHash]),
				new stdClass(),
			]);

		$saves = [];
		$this->objectService->expects($this->exactly(3))
			->method('saveObject')
			->willReturnCallback(
				function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$saves) {
					$saves[] = [$uuid, $register, $schema, $object];
					return $this->entity();
				}
			);
		$this->logger->expects($this->never())->method('error');

		self::assertSame(3, $this->service()->pseudonymise(self::BSN));

		self::assertSame(['u-1', 'u-2', 'u-3'], array_column($saves, 0));
		foreach ($saves as [$uuid, $register, $schema, $object]) {
			self::assertSame('reg-1', $register);
			self::assertSame('audit-s', $schema);
			self::assertSame($newHash, $object['bsnHash']);
			self::assertSame('brp-rtbf-pseudonymised', $object['action']);
			self::assertSame('pseudonymised', $object['outcome']);
		}

		self::assertSame('alice', $saves[0][3]['actor']);
	}//end testPseudonymiseRewritesIdentifiableRecords()

	/**
	 * pseudonymise returns 0 and logs when a save fails mid-way.
	 *
	 * @return void
	 */
	public function testPseudonymiseReturnsZeroWhenSaveFails(): void {
		$this->configure(['register' => 'reg-1', 'bsnAuditRecord_schema' => 'audit-s', 'brp.pseudonym_secret' => 's3cret']);
		$this->objectService->method('findAll')->willReturn([['id' => 'u-1']]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('immutable'));
		$this->logger->expects($this->once())
			->method('error')
			->with('BSN audit pseudonymise failed', ['error' => 'immutable']);

		self::assertSame(0, $this->service()->pseudonymise(self::BSN));
	}//end testPseudonymiseReturnsZeroWhenSaveFails()

	/**
	 * IPv6 anonymisation keeps the /48 prefix and zeroes the remaining 80 bits.
	 *
	 * @return void
	 */
	public function testAnonymiseIpv6ZeroesTrailingBits(): void {
		self::assertSame('2001:db8:abcd::', BsnAuditService::anonymiseIp('2001:db8:abcd:1234:5678:90ab:cdef:1234'));
	}//end testAnonymiseIpv6ZeroesTrailingBits()
}//end class
