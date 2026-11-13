<?php

/**
 * Contract tests for CorrespondenceLanguageController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\CorrespondenceLanguageController;
use OCA\Pipelinq\Service\CorrespondenceLanguageService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the correspondence-language surface.
 *
 * @covers \OCA\Pipelinq\Controller\CorrespondenceLanguageController
 */
class CorrespondenceLanguageControllerTest extends TestCase {
	/**
	 * The service double.
	 *
	 * @var CorrespondenceLanguageService&MockObject
	 */
	private CorrespondenceLanguageService&MockObject $service;

	/**
	 * The controller over a service double.
	 *
	 * @return CorrespondenceLanguageController
	 */
	private function controller(): CorrespondenceLanguageController {
		$this->service = $this->getMockBuilder(CorrespondenceLanguageService::class)
			->disableOriginalConstructor()
			->onlyMethods(['available', 'instanceDefault', 'resolve', 'setPreference'])
			->getMock();

		return new CorrespondenceLanguageController($this->createMock(IRequest::class), $this->service);
	}//end controller()

	/**
	 * The available list carries the languages and the instance default.
	 *
	 * @return void
	 */
	public function testAvailableListsLanguagesAndTheDefault(): void {
		$controller = $this->controller();
		$this->service->method('available')->willReturn(['nl', 'en']);
		$this->service->method('instanceDefault')->willReturn('nl');

		$response = $controller->available();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['languages' => ['nl', 'en'], 'instanceDefault' => 'nl'], $response->getData());
	}//end testAvailableListsLanguagesAndTheDefault()

	/**
	 * Resolve answers the service result without its status key, at that status.
	 *
	 * @return void
	 */
	public function testResolveCarriesTheServiceStatus(): void {
		$controller = $this->controller();
		$this->service->expects($this->once())->method('resolve')->with('party-1')
			->willReturn(['status' => 200, 'language' => 'en', 'source' => 'party']);

		$response = $controller->resolve(partyId: 'party-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['language' => 'en', 'source' => 'party'], $response->getData());
	}//end testResolveCarriesTheServiceStatus()

	/**
	 * A result without a status answers 200.
	 *
	 * @return void
	 */
	public function testAResultWithoutAStatusIs200(): void {
		$controller = $this->controller();
		$this->service->method('resolve')->willReturn(['language' => 'nl']);

		$response = $controller->resolve(partyId: 'party-2');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['language' => 'nl'], $response->getData());
	}//end testAResultWithoutAStatusIs200()

	/**
	 * A refused preference keeps the refusal status, and the tag is passed through.
	 *
	 * @return void
	 */
	public function testARefusedPreferenceKeepsItsStatus(): void {
		$controller = $this->controller();
		$this->service->expects($this->once())->method('setPreference')->with('party-1', 'xx')
			->willReturn(['status' => 422, 'error' => 'Unsupported language.']);

		$response = $controller->setPreference(partyId: 'party-1', language: 'xx');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'Unsupported language.'], $response->getData());
	}//end testARefusedPreferenceKeepsItsStatus()
}//end class
