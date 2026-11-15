<?php

/**
 * Unit tests for SeedTrustConfigurationRows.
 *
 * Verifies the repair step seeds pipelinq's three account trust rows into
 * OpenRegister's `trust-configuration` register via ObjectService, is idempotent
 * (a second run seeds nothing when the rows already exist), and no-ops cleanly
 * when OpenRegister is not installed (REQ-MDM-005).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\SeedTrustConfigurationRows;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Recording fake of OpenRegister's ObjectService (only the two methods the
 * repair step calls). Backs findAll with the objects it has "saved" so a second
 * run sees them as already-present.
 */
final class RecordingObjectService {
	/**
	 * Saved rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * Mimic ObjectService::findAll(config) — filter the saved rows by the
	 * natural-key filters (ignoring register/schema framing).
	 *
	 * `$_rbac` / `$_multitenancy` mirror the real signature: a repair step has no
	 * user session, so it reads and writes in the system context. Omitting them
	 * here makes a by-name call throw "Unknown named parameter".
	 *
	 * @param array<string, mixed> $config The find config.
	 * @param bool $_rbac Whether to enforce RBAC scoping.
	 * @param bool $_multitenancy Whether to enforce tenant scoping.
	 *
	 * @return array<int, array<string, mixed>> Matching saved rows.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$filters = ($config['filters'] ?? []);
		unset($filters['register'], $filters['schema']);

		return array_values(
			array_filter($this->saved,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}//end findAll()

	/**
	 * Mimic ObjectService::saveObject(...) — record the row.
	 *
	 * @param array<string, mixed> $object The object to save.
	 * @param array<string, mixed> $extend Extend config (unused).
	 * @param mixed $register The register (slug).
	 * @param mixed $schema The schema (slug).
	 * @param string|null $uuid The uuid (unused).
	 * @param bool $_rbac Whether to enforce RBAC scoping.
	 * @param bool $_multitenancy Whether to enforce tenant scoping.
	 *
	 * @return array<string, mixed> The saved row.
	 */
	public function saveObject(
		array $object,
		array $extend = [],
		mixed $register = null,
		mixed $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$this->saved[] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Tests for SeedTrustConfigurationRows.
 */
final class SeedTrustConfigurationRowsTest extends TestCase {
	/**
	 * Build the repair step wired to a recording ObjectService.
	 *
	 * @param RecordingObjectService $objectService The recorder.
	 * @param bool $orInstalled Whether OR is "installed".
	 *
	 * @return SeedTrustConfigurationRows The configured step.
	 */
	private function buildStep(RecordingObjectService $objectService, bool $orInstalled = true): SeedTrustConfigurationRows {
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($orInstalled === true ? ['openregister'] : []);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new SeedTrustConfigurationRows($appManager, $container, new NullLogger());
	}//end buildStep()

	/**
	 * All three trust rows are seeded on a fresh run.
	 *
	 * @return void
	 */
	public function testSeedsThreeRows(): void {
		$objectService = new RecordingObjectService();
		$step = $this->buildStep($objectService);

		$step->run($this->createStub(IOutput::class));

		$this->assertCount(3, $objectService->saved);
		$keys = array_map(
			static fn (array $r): string => $r['entityType'] . '/' . $r['attribute'] . '/' . $r['sourceSystem'],
			$objectService->saved
		);
		$this->assertContains('account/billingAddress/kvk-api', $keys);
		$this->assertContains('account/phone/shillinq-debiteuren', $keys);
		$this->assertContains('account/vatNumber/kvk-api', $keys);
	}//end testSeedsThreeRows()

	/**
	 * A second run is idempotent — no duplicate rows.
	 *
	 * @return void
	 */
	public function testSecondRunIsIdempotent(): void {
		$objectService = new RecordingObjectService();
		$step = $this->buildStep($objectService);

		$step->run($this->createStub(IOutput::class));
		$step->run($this->createStub(IOutput::class));

		$this->assertCount(3, $objectService->saved);
	}//end testSecondRunIsIdempotent()

	/**
	 * When OpenRegister is not installed the step no-ops (no writes).
	 *
	 * @return void
	 */
	public function testNoOpWhenOpenRegisterMissing(): void {
		$objectService = new RecordingObjectService();
		$step = $this->buildStep($objectService, false);

		$step->run($this->createStub(IOutput::class));

		$this->assertCount(0, $objectService->saved);
	}//end testNoOpWhenOpenRegisterMissing()
	/**
	 * The step names what it seeds.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		self::assertStringContainsString('trust-tier rows', $this->buildStep(new RecordingObjectService())->getName());
	}//end testTheStepIsNamed()

	/**
	 * An ObjectService that cannot be resolved is warned about and nothing is seeded.
	 *
	 * @return void
	 */
	public function testAnUnavailableObjectServiceSkipsTheSeed(): void {
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not bound'));
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('OpenRegister ObjectService unavailable — skipping trust-configuration seed.');
		$output->expects(self::never())->method('info');

		(new SeedTrustConfigurationRows($appManager, $container, new NullLogger()))->run($output);
	}//end testAnUnavailableObjectServiceSkipsTheSeed()

	/**
	 * A row whose save fails is warned about by its key; the rest still run and the tally is reported.
	 *
	 * @return void
	 */
	public function testAFailingRowIsWarnedAndTheRunContinues(): void {
		$failing = new class {
			/** @var int */
			public int $attempts = 0;

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [];
			}

			public function saveObject(array $object, array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->attempts++;
				throw new \RuntimeException('write refused');
			}
		};
		$appManager = $this->createStub(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($failing);
		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});
		$output->expects(self::once())->method('info')->with('Trust-configuration seed complete: 0 seeded, 0 already present.');

		(new SeedTrustConfigurationRows($appManager, $container, new NullLogger()))->run($output);

		self::assertSame(3, $failing->attempts, 'every row is attempted');
		self::assertCount(3, $warnings);
		self::assertStringStartsWith('Trust-configuration seed failed for ', $warnings[0]);
		self::assertStringEndsWith(': write refused', $warnings[0]);
	}//end testAFailingRowIsWarnedAndTheRunContinues()

}//end class
