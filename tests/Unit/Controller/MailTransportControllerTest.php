<?php

/**
 * Tests for MailTransportController: the deliverability verdict or a 404.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MailTransportController;
use OCA\Pipelinq\Kernel\Contracts\DeliverabilityCheckServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Controller\MailTransportController
 */
class MailTransportControllerTest extends TestCase {

	/**
	 * A known transport answers its verdict with 200, passing the id and refresh flag through.
	 *
	 * @return void
	 */
	public function testAKnownTransportAnswersItsVerdict(): void {
		$verdict = ['id' => 't-1', 'spf' => 'pass', 'dkim' => 'fail'];
		$service = $this->createMock(DeliverabilityCheckServiceInterface::class);
		$service->expects($this->once())
			->method('checkTransportById')
			->with('t-1', true)
			->willReturn($verdict);

		$response = $this->controller(service: $service)->checkDeliverability(id: 't-1', refresh: true);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $verdict, actual: $response->getData());
	}//end testAKnownTransportAnswersItsVerdict()

	/**
	 * An unknown transport is a 404 with a machine-readable error, and the cache is used by default.
	 *
	 * @return void
	 */
	public function testAnUnknownTransportIsNotFound(): void {
		$service = $this->createMock(DeliverabilityCheckServiceInterface::class);
		$service->expects($this->once())
			->method('checkTransportById')
			->with('', false)
			->willReturn(null);

		$response = $this->controller(service: $service)->checkDeliverability();

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'mail-transport-not-found'], actual: $response->getData());
	}//end testAnUnknownTransportIsNotFound()

	/**
	 * The controller over the given service.
	 *
	 * @param DeliverabilityCheckServiceInterface $service The service double.
	 *
	 * @return MailTransportController The subject.
	 */
	private function controller(DeliverabilityCheckServiceInterface $service): MailTransportController {
		return new MailTransportController(request: $this->createMock(IRequest::class), deliverabilityCheckService: $service);
	}//end controller()
}//end class
