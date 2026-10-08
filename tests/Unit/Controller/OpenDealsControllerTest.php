<?php

/**
 * Unit tests for OpenDealsController: the Open deals KPI of a contact or client.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Controller\OpenDealsController;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Counts and sums open deals, under the user's RBAC.
 */
class OpenDealsControllerTest extends TestCase {

	/**
	 * The controller over the given request parameters and object service.
	 *
	 * @param array<string, string>  $params  Request parameters.
	 * @param ObjectServiceInterface $objects The object service double.
	 *
	 * @return OpenDealsController The controller.
	 */
	private function controller(array $params, ObjectServiceInterface $objects): OpenDealsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'register' => '20',
				'lead_schema' => '30',
				'contact_schema' => '31',
				'client_schema' => '32',
			][$key] ?? $default
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new OpenDealsController($request, $config, $objects, $session);
	}//end controller()

	/**
	 * Two open deals of the contact are counted and summed; nothing else is.
	 *
	 * @return void
	 */
	public function testCountsAndSumsTheOpenDealsOfAContact(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(new ObjectEntity());
		$objects->expects($this->once())->method('findAll')->with(
			$this->callback(static fn (array $config): bool => $config['filters'] === [
				'register' => '20',
				'schema' => '30',
				'contact' => 'c-1',
				'status' => 'open',
			])
		)->willReturn([
			['contact' => 'c-1', 'status' => 'open', 'value' => 12500],
			['contact' => 'c-1', 'status' => 'open', 'value' => 3600],
			// A row the store returned anyway is checked again, not trusted.
			['contact' => 'c-1', 'status' => 'won', 'value' => 99999],
		]);

		$response = $this->controller(['contact' => 'c-1'], $objects)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['openDealCount' => 2, 'openDealValue' => 16100.0], $response->getData());
	}//end testCountsAndSumsTheOpenDealsOfAContact()

	/**
	 * A contact the user cannot read answers 404 and no deals are read.
	 *
	 * @return void
	 */
	public function testUnreadablePartyIsNotFound(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturn(null);
		$objects->expects($this->never())->method('findAll');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['contact' => 'c-x'], $objects)->index()->getStatus());
	}//end testUnreadablePartyIsNotFound()

	/**
	 * Naming no party, or both, is a bad request.
	 *
	 * @return void
	 */
	public function testExactlyOnePartyIsRequired(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller([], $objects)->index()->getStatus());
		$this->assertSame(
			Http::STATUS_BAD_REQUEST,
			$this->controller(['contact' => 'c-1', 'client' => 'k-1'], $objects)->index()->getStatus()
		);
	}//end testExactlyOnePartyIsRequired()
}//end class
