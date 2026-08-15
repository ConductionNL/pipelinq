<?php

/**
 * Unit tests for ForecastOverrideService validation.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ForecastOverrideService;
use OCA\Pipelinq\Service\ForecastService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for override payload validation.
 */
class ForecastOverrideServiceTest extends TestCase {
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
		$this->service = new ForecastOverrideService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->createMock(IAppConfig::class),
			forecastService: $this->createMock(ForecastService::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end setUp()

	/**
	 * A complete payload validates.
	 *
	 * @return void
	 */
	public function testValidPayload(): void {
		$error = $this->service->validatePayload([
			'period_id' => 'Q2-2026',
			'override_owner_id' => 'john.doe',
			'level' => 'rep',
			'category' => 'commit',
			'override_amount' => 60000,
			'reason' => 'Execution risk on the Bakker deal.',
		]);
		$this->assertNull($error);
	}//end testValidPayload()

	/**
	 * Missing override_owner_id is rejected.
	 *
	 * @return void
	 */
	public function testRejectsMissingOwner(): void {
		$error = $this->service->validatePayload([
			'period_id' => 'Q2-2026',
			'level' => 'rep',
			'category' => 'commit',
			'override_amount' => 60000,
			'reason' => 'Execution risk.',
		]);
		$this->assertNotNull($error);
	}//end testRejectsMissingOwner()

	/**
	 * An invalid category (e.g. pipeline) is rejected.
	 *
	 * @return void
	 */
	public function testRejectsInvalidCategory(): void {
		$error = $this->service->validatePayload([
			'period_id' => 'Q2-2026',
			'override_owner_id' => 'john.doe',
			'level' => 'rep',
			'category' => 'pipeline',
			'override_amount' => 60000,
			'reason' => 'Execution risk.',
		]);
		$this->assertNotNull($error);
	}//end testRejectsInvalidCategory()

	/**
	 * A negative or non-numeric amount is rejected.
	 *
	 * @return void
	 */
	public function testRejectsBadAmount(): void {
		$base = [
			'period_id' => 'Q2-2026',
			'override_owner_id' => 'john.doe',
			'level' => 'rep',
			'category' => 'commit',
			'reason' => 'Execution risk.',
		];
		$this->assertNotNull($this->service->validatePayload(array_merge($base, ['override_amount' => -1])));
		$this->assertNotNull($this->service->validatePayload(array_merge($base, ['override_amount' => 'abc'])));
	}//end testRejectsBadAmount()

	/**
	 * A too-short reason is rejected.
	 *
	 * @return void
	 */
	public function testRejectsShortReason(): void {
		$error = $this->service->validatePayload([
			'period_id' => 'Q2-2026',
			'override_owner_id' => 'john.doe',
			'level' => 'rep',
			'category' => 'commit',
			'override_amount' => 60000,
			'reason' => 'no',
		]);
		$this->assertNotNull($error);
	}//end testRejectsShortReason()
}//end class
