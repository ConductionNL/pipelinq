<?php

/**
 * Unit tests for OptOutService.
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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#9.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\OptOutService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006
 */
class OptOutServiceTest extends TestCase {
	/**
	 * Without OR configured, hasOptOut returns false (defensive).
	 *
	 * @return void
	 */
	public function testHasOptOutFalseWhenUnconfigured(): void {
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig->method('getValueString')->willReturn('');

		$service = new OptOutService($container, $appConfig, $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		self::assertFalse($service->hasOptOut('123456782'));
	}//end testHasOptOutFalseWhenUnconfigured()

	/**
	 * recordFromBrpResponse with indicatieGeheim != "1" is a no-op.
	 *
	 * @return void
	 */
	public function testRecordFromBrpResponseSkipsNonGeheim(): void {
		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		// Container::get should never be called.
		$container->expects(self::never())->method('get');

		$service = new OptOutService($container, $appConfig, $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		self::assertFalse($service->recordFromBrpResponse('123456782', '0'));
		self::assertFalse($service->recordFromBrpResponse('123456782', ''));
	}//end testRecordFromBrpResponseSkipsNonGeheim()
}//end class
