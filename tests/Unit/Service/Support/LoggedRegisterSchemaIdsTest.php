<?php

/**
 * Behavioural tests for the LoggedRegisterSchemaIds trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Tests\Unit\Util\ConfiguredRegisterIdTest;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Both ids are returned; an empty one is logged with the schema key.
 *
 * @covers \OCA\Pipelinq\Service\Support\LoggedRegisterSchemaIds
 */
class LoggedRegisterSchemaIdsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testBothConfigured(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$host = ConfiguredRegisterIdTest::host(['register' => 'r1', 'tier_schema' => 's1'], $logger, $this->createMock(IAppConfig::class));
		$this->assertSame(['r1', 's1'], $host->ids(key: 'tier_schema'));
	}//end testBothConfigured()

	/**
	 * @return void
	 */
	public function testMissingSchemaIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pipelinq: register/schema not configured; OpenRegister calls are refused, not run unscoped',
			['schemaKey' => 'tier_schema']
		);

		$host = ConfiguredRegisterIdTest::host(['register' => 'r1'], $logger, $this->createMock(IAppConfig::class));
		$this->assertSame(['r1', ''], $host->ids(key: 'tier_schema'));
	}//end testMissingSchemaIsLogged()
}//end class
