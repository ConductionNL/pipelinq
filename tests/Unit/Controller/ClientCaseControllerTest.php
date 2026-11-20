<?php

/**
 * Tests for ClientCaseController: the reader's status becomes the HTTP code.
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

use OCA\Pipelinq\Controller\ClientCaseController;
use OCA\Pipelinq\Kernel\Contracts\ClientCaseReaderInterface;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Controller\ClientCaseController
 */
class ClientCaseControllerTest extends TestCase {

	/**
	 * The reader's answer is the body without its status, which becomes the HTTP code.
	 *
	 * @return void
	 */
	public function testTheReadersStatusBecomesTheHttpCode(): void {
		$reader = $this->createMock(ClientCaseReaderInterface::class);
		$reader->expects($this->once())
			->method('casesOf')
			->with('client-1', 'open', 5)
			->willReturn(['status' => 200, 'results' => [['id' => 'case-1']], 'total' => 1]);

		$response = $this->controller(reader: $reader)->index(id: 'client-1', status: 'open', limit: 5);

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['results' => [['id' => 'case-1']], 'total' => 1], actual: $response->getData());
	}//end testTheReadersStatusBecomesTheHttpCode()

	/**
	 * A refusal keeps its code and message, and the defaults are all statuses and 20 cases.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsCodeAndTheDefaultsApply(): void {
		$reader = $this->createMock(ClientCaseReaderInterface::class);
		$reader->expects($this->once())
			->method('casesOf')
			->with('client-2', 'all', 20)
			->willReturn(['status' => '503', 'error' => 'case-app-unavailable']);

		$response = $this->controller(reader: $reader)->index(id: 'client-2');

		$this->assertSame(expected: 503, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'case-app-unavailable'], actual: $response->getData());
	}//end testARefusalKeepsItsCodeAndTheDefaultsApply()

	/**
	 * The controller over the given reader.
	 *
	 * @param ClientCaseReaderInterface $reader The reader double.
	 *
	 * @return ClientCaseController The subject.
	 */
	private function controller(ClientCaseReaderInterface $reader): ClientCaseController {
		return new ClientCaseController(request: $this->createMock(IRequest::class), reader: $reader);
	}//end controller()
}//end class
