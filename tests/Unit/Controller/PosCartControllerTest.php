<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosCartController;
use OCA\Pipelinq\Service\PosCart\PosCartRepricer;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The recalculate endpoint returns the priced sale, or the refusal with its status.
 */
class PosCartControllerTest extends TestCase {
	public function testItReturnsTheSaleAsTheServerPricesIt(): void {
		$repricer = $this->createMock(PosCartRepricer::class);
		$repricer->expects($this->once())->method('reprice')->with('tx-1')->willReturn(['transaction' => ['total' => 9.5], 'lines' => []]);

		$response = (new PosCartController($this->createMock(IRequest::class), $repricer))->recalculate(id: 'tx-1');

		$this->assertSame([200, ['transaction' => ['total' => 9.5], 'lines' => []]], [$response->getStatus(), $response->getData()]);
	}//end testItReturnsTheSaleAsTheServerPricesIt()

	/**
	 * Each refusal and its status.
	 *
	 * @return array<string, array{0: Throwable, 1: int}>
	 */
	public static function refusals(): array {
		return [
			'missing'   => [new OCSNotFoundException('Transaction not found'), 404],
			'forbidden' => [new OCSForbiddenException('No access to this sale'), 403],
			'closed'    => [new OCSBadRequestException('The sale is no longer open'), 422],
		];
	}//end refusals()

	#[DataProvider('refusals')]
	public function testARefusalKeepsItsStatus(Throwable $refusal, int $status): void {
		$repricer = $this->createMock(PosCartRepricer::class);
		$repricer->method('reprice')->willThrowException($refusal);

		$response = (new PosCartController($this->createMock(IRequest::class), $repricer))->recalculate(id: 'tx-1');

		$this->assertSame($status, $response->getStatus());
	}//end testARefusalKeepsItsStatus()
}//end class
