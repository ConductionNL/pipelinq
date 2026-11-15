<?php

/**
 * Coverage tests for ForecastOverrideService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ForecastOverrideService;
use OCA\Pipelinq\Service\ForecastService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Validation, creation, lookup and deletion of forecast overrides.
 *
 * @covers \OCA\Pipelinq\Service\ForecastOverrideService
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class ForecastOverrideServiceCoverageTest extends TestCase {
	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The forecast service mock.
	 *
	 * @var ForecastService&MockObject
	 */
	private ForecastService&MockObject $forecastService;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The service under test.
	 *
	 * @var ForecastOverrideService
	 */
	private ForecastOverrideService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->forecastService = $this->createMock(originalClassName: ForecastService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$this->service = new ForecastOverrideService(
			appConfig: $this->appConfig,
			forecastService: $this->forecastService,
			logger: $this->logger,
			objectService: $this->objectService,
		);
	}//end setUp()

	/**
	 * Configure the register and override schema ids.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The override schema id.
	 *
	 * @return void
	 */
	private function configure(string $register, string $schema): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($register, $schema): string {
				if ($app !== 'pipelinq') {
					return $default;
				}

				if ($key === 'register') {
					return $register;
				}

				if ($key === 'forecastOverride_schema') {
					return $schema;
				}

				return $default;
			}
		);
	}//end configure()

	/**
	 * A valid payload.
	 *
	 * @param array<string, mixed> $changes Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(array $changes = []): array {
		return array_merge(
			[
				'period_id' => 'Q2-2026',
				'override_owner_id' => 'john.doe',
				'level' => 'rep',
				'category' => 'commit',
				'override_amount' => 60000,
				'reason' => 'Execution risk on the Bakker deal.',
			],
			$changes
		);
	}//end payload()

	/**
	 * An entity mock whose serialised form is the given data.
	 *
	 * @param array<string, mixed> $data The serialised data.
	 *
	 * @return ObjectEntityInterface&MockObject
	 */
	private function entity(array $data): ObjectEntityInterface&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A missing period id is reported first.
	 *
	 * @return void
	 */
	public function testValidateMissingPeriod(): void {
		$payload = $this->payload();
		unset($payload['period_id']);
		$this->assertSame(expected: 'period_id is required.', actual: $this->service->validatePayload($payload));
	}//end testValidateMissingPeriod()

	/**
	 * A whitespace-only period id counts as missing.
	 *
	 * @return void
	 */
	public function testValidateBlankPeriod(): void {
		$this->assertSame(expected: 'period_id is required.', actual: $this->service->validatePayload($this->payload(changes: ['period_id' => '   '])));
	}//end testValidateBlankPeriod()

	/**
	 * A missing override owner is reported with its own message.
	 *
	 * @return void
	 */
	public function testValidateMissingOwner(): void {
		$this->assertSame(
			expected: 'override_owner_id is required.',
			actual: $this->service->validatePayload($this->payload(changes: ['override_owner_id' => ' ']))
		);
	}//end testValidateMissingOwner()

	/**
	 * An unknown level is rejected.
	 *
	 * @return void
	 */
	public function testValidateInvalidLevel(): void {
		$this->assertSame(
			expected: 'A valid level (rep, team, division) is required.',
			actual: $this->service->validatePayload($this->payload(changes: ['level' => 'company']))
		);
	}//end testValidateInvalidLevel()

	/**
	 * A missing level is rejected.
	 *
	 * @return void
	 */
	public function testValidateMissingLevel(): void {
		$payload = $this->payload();
		unset($payload['level']);
		$this->assertSame(expected: 'A valid level (rep, team, division) is required.', actual: $this->service->validatePayload($payload));
	}//end testValidateMissingLevel()

	/**
	 * Team and division levels are accepted.
	 *
	 * @return void
	 */
	public function testValidateAcceptsTeamAndDivision(): void {
		$this->assertNull(actual: $this->service->validatePayload($this->payload(changes: ['level' => 'team'])));
		$this->assertNull(actual: $this->service->validatePayload($this->payload(changes: ['level' => 'division'])));
	}//end testValidateAcceptsTeamAndDivision()

	/**
	 * The pipeline category cannot be overridden.
	 *
	 * @return void
	 */
	public function testValidateInvalidCategory(): void {
		$this->assertSame(
			expected: 'A valid category (commit, best_case) is required.',
			actual: $this->service->validatePayload($this->payload(changes: ['category' => 'pipeline']))
		);
	}//end testValidateInvalidCategory()

	/**
	 * The best_case category is accepted.
	 *
	 * @return void
	 */
	public function testValidateAcceptsBestCase(): void {
		$this->assertNull(actual: $this->service->validatePayload($this->payload(changes: ['category' => 'best_case'])));
	}//end testValidateAcceptsBestCase()

	/**
	 * Non-numeric, negative and missing amounts are rejected.
	 *
	 * @return void
	 */
	public function testValidateBadAmounts(): void {
		$message = 'override_amount must be a non-negative number.';
		$this->assertSame(expected: $message, actual: $this->service->validatePayload($this->payload(changes: ['override_amount' => 'abc'])));
		$this->assertSame(expected: $message, actual: $this->service->validatePayload($this->payload(changes: ['override_amount' => -0.01])));
		$this->assertSame(expected: $message, actual: $this->service->validatePayload($this->payload(changes: ['override_amount' => null])));
	}//end testValidateBadAmounts()

	/**
	 * Zero and numeric strings are valid amounts.
	 *
	 * @return void
	 */
	public function testValidateZeroAndNumericStringAmounts(): void {
		$this->assertNull(actual: $this->service->validatePayload($this->payload(changes: ['override_amount' => 0])));
		$this->assertNull(actual: $this->service->validatePayload($this->payload(changes: ['override_amount' => '1250.50'])));
	}//end testValidateZeroAndNumericStringAmounts()

	/**
	 * A reason padded with spaces is measured after trimming.
	 *
	 * @return void
	 */
	public function testValidateReasonTrimmedLength(): void {
		$this->assertSame(
			expected: 'A reason of at least 5 characters is required.',
			actual: $this->service->validatePayload($this->payload(changes: ['reason' => '   abcd   ']))
		);
		$this->assertNull(actual: $this->service->validatePayload($this->payload(changes: ['reason' => 'abcde'])));
	}//end testValidateReasonTrimmedLength()

	/**
	 * Reason length counts characters, not bytes.
	 *
	 * @return void
	 */
	public function testValidateReasonCountsCharacters(): void {
		$this->assertSame(
			expected: 'A reason of at least 5 characters is required.',
			actual: $this->service->validatePayload($this->payload(changes: ['reason' => 'éééé']))
		);
		$this->assertNull(actual: $this->service->validatePayload($this->payload(changes: ['reason' => 'ééééé'])));
	}//end testValidateReasonCountsCharacters()

	/**
	 * A commit override records the roll-up's original commit and persists
	 * server-set fields.
	 *
	 * @return void
	 */
	public function testCreateCommitOverridePersists(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->forecastService->expects($this->once())
			->method('computeRollUp')
			->with('john.doe', 'Q2-2026', 'rep')
			->willReturn(['original_commit' => 75000, 'original_best_case' => 90000]);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object, ?array $extend, $register, $schema, ?string $uuid) use (&$captured) {
					$this->assertSame(expected: [], actual: $extend);
					$this->assertSame(expected: 'reg-1', actual: $register);
					$this->assertSame(expected: 'schema-1', actual: $schema);
					$this->assertNull(actual: $uuid);
					$captured = $object;
					return $this->entity(data: ['id' => 'ov-1', 'category' => 'commit']);
				}
			);

		$before = time();
		$result = $this->service->createOverride($this->payload(changes: ['override_amount' => '60000', 'reason' => '  Execution risk.  ']), 'manager.one');
		$after = time();

		$this->assertSame(expected: ['id' => 'ov-1', 'category' => 'commit'], actual: $result);
		$createdAt = $captured['created_at'];
		unset($captured['created_at']);
		$this->assertSame(
			expected: [
				'period_id' => 'Q2-2026',
				'owner_id' => 'manager.one',
				'override_owner_id' => 'john.doe',
				'level' => 'rep',
				'category' => 'commit',
				'override_amount' => 60000.0,
				'original_amount' => 75000.0,
				'reason' => 'Execution risk.',
				'created_by' => 'manager.one',
			],
			actual: $captured
		);
		$stamp = (new DateTimeImmutable($createdAt))->getTimestamp();
		$this->assertGreaterThanOrEqual(expected: $before, actual: $stamp);
		$this->assertLessThanOrEqual(expected: $after, actual: $stamp);
	}//end testCreateCommitOverridePersists()

	/**
	 * A best_case override records the roll-up's original best case.
	 *
	 * @return void
	 */
	public function testCreateBestCaseUsesOriginalBestCase(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->forecastService->expects($this->once())
			->method('computeRollUp')
			->with('team-east', 'Q3-2026', 'team')
			->willReturn(['original_commit' => 75000, 'original_best_case' => 90000.5]);

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (array $object) use (&$captured) {
					$captured = $object;
					return $this->entity(data: ['id' => 'ov-2']);
				}
			);

		$result = $this->service->createOverride(
			$this->payload(changes: ['period_id' => 'Q3-2026', 'override_owner_id' => 'team-east', 'level' => 'team', 'category' => 'best_case']),
			'manager.two'
		);

		$this->assertSame(expected: ['id' => 'ov-2'], actual: $result);
		$this->assertSame(expected: 90000.5, actual: $captured['original_amount']);
		$this->assertSame(expected: 'best_case', actual: $captured['category']);
		$this->assertSame(expected: 'team-east', actual: $captured['override_owner_id']);
		$this->assertSame(expected: 'manager.two', actual: $captured['owner_id']);
	}//end testCreateBestCaseUsesOriginalBestCase()

	/**
	 * A roll-up without the original key records zero.
	 *
	 * @return void
	 */
	public function testCreateMissingOriginalDefaultsToZero(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->forecastService->method('computeRollUp')->willReturn([]);

		$captured = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured) {
				$captured = $object;
				return $this->entity(data: []);
			}
		);

		$this->assertSame(expected: [], actual: $this->service->createOverride($this->payload(), 'manager.one'));
		$this->assertSame(expected: 0.0, actual: $captured['original_amount']);
	}//end testCreateMissingOriginalDefaultsToZero()

	/**
	 * An entity that only exposes getObject() is converted through it.
	 *
	 * @return void
	 */
	public function testCreateReturnsEntityPayloadFromGetObject(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->forecastService->method('computeRollUp')->willReturn(['original_commit' => 1]);

		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn('not-an-array');
		$entity->method('getObject')->willReturn(['uuid' => 'from-get-object']);
		$this->objectService->method('saveObject')->willReturn($entity);

		$this->assertSame(expected: ['uuid' => 'from-get-object'], actual: $this->service->createOverride($this->payload(), 'manager.one'));
	}//end testCreateReturnsEntityPayloadFromGetObject()

	/**
	 * An unconfigured schema refuses to persist and logs a warning.
	 *
	 * @return void
	 */
	public function testCreateUnconfiguredThrows(): void {
		$this->configure(register: 'reg-1', schema: '');
		$this->forecastService->expects($this->once())->method('computeRollUp')->willReturn([]);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->logger->expects($this->once())
			->method('warning')
			->with('Pipelinq: register/forecastOverride_schema not configured; OpenRegister calls are refused, not run unscoped');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Forecast override schema is not configured.');
		$this->service->createOverride($this->payload(), 'manager.one');
	}//end testCreateUnconfiguredThrows()

	/**
	 * A missing register refuses to persist too.
	 *
	 * @return void
	 */
	public function testCreateMissingRegisterThrows(): void {
		$this->configure(register: '', schema: 'schema-1');
		$this->forecastService->method('computeRollUp')->willReturn([]);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Forecast override schema is not configured.');
		$this->service->createOverride($this->payload(), 'manager.one');
	}//end testCreateMissingRegisterThrows()

	/**
	 * A found override is returned as an array, scoped to register and schema.
	 *
	 * @return void
	 */
	public function testGetOverrideReturnsArray(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->logger->expects($this->never())->method('warning');
		$this->objectService->expects($this->once())
			->method('find')
			->with('ov-1', [], false, 'reg-1', 'schema-1')
			->willReturn($this->entity(data: ['id' => 'ov-1', 'override_amount' => 5.0]));

		$this->assertSame(expected: ['id' => 'ov-1', 'override_amount' => 5.0], actual: $this->service->getOverride('ov-1'));
	}//end testGetOverrideReturnsArray()

	/**
	 * A missing override yields null.
	 *
	 * @return void
	 */
	public function testGetOverrideNotFound(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->objectService->expects($this->once())->method('find')->willReturn(null);

		$this->assertNull(actual: $this->service->getOverride('ov-unknown'));
	}//end testGetOverrideNotFound()

	/**
	 * A lookup failure yields null instead of propagating.
	 *
	 * @return void
	 */
	public function testGetOverrideSwallowsFailure(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->objectService->expects($this->once())->method('find')->willThrowException(new RuntimeException('boom'));

		$this->assertNull(actual: $this->service->getOverride('ov-1'));
	}//end testGetOverrideSwallowsFailure()

	/**
	 * An unconfigured schema never queries OpenRegister and warns.
	 *
	 * @return void
	 */
	public function testGetOverrideUnconfigured(): void {
		$this->configure(register: '', schema: '');
		$this->objectService->expects($this->never())->method('find');
		$this->logger->expects($this->once())->method('warning');

		$this->assertNull(actual: $this->service->getOverride('ov-1'));
	}//end testGetOverrideUnconfigured()

	/**
	 * A missing schema alone also refuses the lookup.
	 *
	 * @return void
	 */
	public function testGetOverrideMissingSchema(): void {
		$this->configure(register: 'reg-1', schema: '');
		$this->objectService->expects($this->never())->method('find');

		$this->assertNull(actual: $this->service->getOverride('ov-1'));
	}//end testGetOverrideMissingSchema()

	/**
	 * An empty id never queries OpenRegister.
	 *
	 * @return void
	 */
	public function testGetOverrideEmptyId(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->objectService->expects($this->never())->method('find');
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull(actual: $this->service->getOverride(''));
	}//end testGetOverrideEmptyId()

	/**
	 * Deleting returns OpenRegister's verdict, scoped to register and schema.
	 *
	 * @return void
	 */
	public function testDeleteOverrideSucceeds(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->objectService->expects($this->once())
			->method('deleteObject')
			->with('ov-1', 'reg-1', 'schema-1')
			->willReturn(true);
		$this->logger->expects($this->never())->method('error');

		$this->assertTrue(condition: $this->service->deleteOverride('ov-1'));
	}//end testDeleteOverrideSucceeds()

	/**
	 * A refused delete returns false.
	 *
	 * @return void
	 */
	public function testDeleteOverrideRefused(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->objectService->expects($this->once())->method('deleteObject')->willReturn(false);

		$this->assertFalse(condition: $this->service->deleteOverride('ov-1'));
	}//end testDeleteOverrideRefused()

	/**
	 * A failing delete is logged and returns false.
	 *
	 * @return void
	 */
	public function testDeleteOverrideFailureLogged(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->objectService->expects($this->once())
			->method('deleteObject')
			->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())
			->method('error')
			->with('Pipelinq: failed to delete forecast override', ['exception' => 'locked', 'id' => 'ov-9']);

		$this->assertFalse(condition: $this->service->deleteOverride('ov-9'));
	}//end testDeleteOverrideFailureLogged()

	/**
	 * An unconfigured schema never deletes.
	 *
	 * @return void
	 */
	public function testDeleteOverrideUnconfigured(): void {
		$this->configure(register: 'reg-1', schema: '');
		$this->objectService->expects($this->never())->method('deleteObject');
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse(condition: $this->service->deleteOverride('ov-1'));
	}//end testDeleteOverrideUnconfigured()

	/**
	 * A missing register never deletes.
	 *
	 * @return void
	 */
	public function testDeleteOverrideMissingRegister(): void {
		$this->configure(register: '', schema: 'schema-1');
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->assertFalse(condition: $this->service->deleteOverride('ov-1'));
	}//end testDeleteOverrideMissingRegister()

	/**
	 * An empty id never deletes.
	 *
	 * @return void
	 */
	public function testDeleteOverrideEmptyId(): void {
		$this->configure(register: 'reg-1', schema: 'schema-1');
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->assertFalse(condition: $this->service->deleteOverride(''));
	}//end testDeleteOverrideEmptyId()

	/**
	 * Config is read from the pipelinq app with the exact keys.
	 *
	 * @return void
	 */
	public function testConfigReadsExactKeys(): void {
		$keys = [];
		$this->appConfig->expects($this->exactly(count: 2))
			->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default) use (&$keys): string {
					$keys[] = [$app, $key, $default];
					return '';
				}
			);
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->assertFalse(condition: $this->service->deleteOverride('ov-1'));
		$this->assertSame(expected: [['pipelinq', 'register', ''], ['pipelinq', 'forecastOverride_schema', '']], actual: $keys);
	}//end testConfigReadsExactKeys()
}//end class
