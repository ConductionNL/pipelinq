<?php

/**
 * Behavioural tests for the PosJsonAction trait.
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

namespace OCA\Pipelinq\Tests\Unit\Controller\Support;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The action's result is the JSON body; a crash logs under the host's name.
 *
 * @covers \OCA\Pipelinq\Controller\Support\PosJsonAction
 * @uses \OCA\Pipelinq\Controller\PosResponses
 * @uses \OCA\Pipelinq\Controller\Support\OcsErrorResponse
 * @uses \OCA\Pipelinq\Controller\Support\PosControllerResponses
 */
class PosJsonActionTest extends TestCase {

	/**
	 * @return void
	 */
	public function testRun(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('PosControllerResponsesHost::act failed', ['exception' => 'boom']);
		$host = PosControllerResponsesTest::host(null, $logger, $this);

		$ok = $host->act(static fn (): array => ['role' => 'r1']);
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['role' => 'r1'], $ok->getData());

		$failed = $host->act(static function (): array {
			throw new \RuntimeException('boom');
		});
		$this->assertSame(500, $failed->getStatus());
	}//end testRun()
}//end class
