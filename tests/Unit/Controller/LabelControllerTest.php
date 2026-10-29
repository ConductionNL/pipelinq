<?php

/**
 * The label rename endpoint answers each refusal with its status.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\LabelController;
use OCA\Pipelinq\Service\Labels\LabelRenameException;
use OCA\Pipelinq\Service\Labels\LabelRenameService;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Controller\LabelController
 */
class LabelControllerTest extends TestCase {

	/**
	 * A controller over a service mock.
	 *
	 * @param LabelRenameService $service The service.
	 * @param string $name The posted name.
	 *
	 * @return LabelController
	 */
	private function controller(LabelRenameService $service, string $name): LabelController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['name', '', $name]]);

		return new LabelController(request: $request, service: $service, logger: $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * The endpoint is an admin setting, never open to every account.
	 *
	 * @return void
	 */
	public function testTheRenameIsAnAdminEndpoint(): void {
		$method = new ReflectionMethod(LabelController::class, 'rename');
		$this->assertNotSame([], $method->getAttributes(AuthorizedAdminSetting::class));
		$this->assertSame([], $method->getAttributes(NoAdminRequired::class));
	}//end testTheRenameIsAnAdminEndpoint()

	/**
	 * A rename answers the renamed label and how many items followed.
	 *
	 * @return void
	 */
	public function testARenameAnswersTheResult(): void {
		$service = $this->createMock(LabelRenameService::class);
		$service->expects($this->once())->method('rename')->with('l3', 'ondernemers centrum')
			->willReturn(['id' => 'l3', 'name' => 'ondernemers centrum', 'rewritten' => 5, 'queued' => false]);

		$response = $this->controller(service: $service, name: 'ondernemers centrum')->rename(id: 'l3');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(5, $response->getData()['rewritten']);
	}//end testARenameAnswersTheResult()

	/**
	 * A refusal answers its own status and message.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsStatus(): void {
		$service = $this->createMock(LabelRenameService::class);
		$service->method('rename')->willThrowException(new LabelRenameException(message: 'Another label already has this name.', status: 409));

		$response = $this->controller(service: $service, name: 'duurzaam')->rename(id: 'l3');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame('Another label already has this name.', $response->getData()['error']);
	}//end testARefusalAnswersItsStatus()

	/**
	 * Any other failure answers 500 without its message.
	 *
	 * @return void
	 */
	public function testAFailureAnswers500(): void {
		$service = $this->createMock(LabelRenameService::class);
		$service->method('rename')->willThrowException(new RuntimeException('db down at host x'));

		$response = $this->controller(service: $service, name: 'x')->rename(id: 'l3');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame('The label could not be renamed.', $response->getData()['error']);
	}//end testAFailureAnswers500()
}//end class
