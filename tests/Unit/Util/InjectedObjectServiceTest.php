<?php

/**
 * Behavioural tests for the InjectedObjectService trait.
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\InjectedObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The injected service is what the host hands out.
 *
 * @covers \OCA\Pipelinq\Util\InjectedObjectService
 */
class InjectedObjectServiceTest extends TestCase {

	/**
	 * @return void
	 */
	public function testHandsOutTheInjectedService(): void {
		$service = new \stdClass();
		$host = new class($service) {
			use InjectedObjectService;

			/**
			 * @param object $objectService The service.
			 */
			public function __construct(private object $objectService) {
			}

			/**
			 * @return object
			 */
			public function read(): object {
				return $this->getObjectService();
			}
		};

		$this->assertSame($service, $host->read());
	}//end testHandsOutTheInjectedService()
}//end class
