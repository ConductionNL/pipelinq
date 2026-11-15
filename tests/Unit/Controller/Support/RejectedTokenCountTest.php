<?php

/**
 * Behavioural tests for the RejectedTokenCount trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller\Support
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

use OCA\Pipelinq\Controller\Support\RejectedTokenCount;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The attempt is counted under the host's action and the caller's address; a
 * throttler failure is logged under the host's short class name.
 *
 * @covers \OCA\Pipelinq\Controller\Support\RejectedTokenCount
 */
class RejectedTokenCountTest extends TestCase {

	/**
	 * @return void
	 */
	public function testCountsTheAttempt(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->expects($this->once())->method('registerAttempt')->with('probe_action', '10.0.0.1');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->host($throttler, $logger)->reject();
	}//end testCountsTheAttempt()

	/**
	 * @return void
	 */
	public function testAThrottlerFailureIsLoggedAndSwallowed(): void {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('RejectedTokenCountHost: registerAttempt failed: down');

		$this->host($throttler, $logger)->reject();
	}//end testAThrottlerFailureIsLoggedAndSwallowed()

	/**
	 * Build a host over the given throttler and logger.
	 *
	 * @param IThrottler      $throttler The throttler.
	 * @param LoggerInterface $logger    The logger.
	 *
	 * @return RejectedTokenCountHost
	 */
	private function host(IThrottler $throttler, LoggerInterface $logger): RejectedTokenCountHost {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('10.0.0.1');

		return new RejectedTokenCountHost($throttler, $request, $logger);
	}//end host()
}//end class

/**
 * A named host, so the log prefix is a predictable short class name.
 */
final class RejectedTokenCountHost {
	use RejectedTokenCount;

	private const THROTTLE_ACTION = 'probe_action';

	/**
	 * @param IThrottler      $throttler The throttler.
	 * @param IRequest        $request   The request.
	 * @param LoggerInterface $logger    The logger.
	 */
	public function __construct(
		private IThrottler $throttler,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return void
	 */
	public function reject(): void {
		$this->registerRejectedToken();
	}
}//end class
