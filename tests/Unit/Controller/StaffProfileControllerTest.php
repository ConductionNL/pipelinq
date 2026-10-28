<?php

/**
 * Tests for StaffProfileController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\StaffProfileController;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Accounts\PropertyDoesNotExistException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * A colleague's function and department come from their profile; private
 * properties and unknown users read as nothing.
 *
 * @covers \OCA\Pipelinq\Controller\StaffProfileController
 */
class StaffProfileControllerTest extends TestCase {
	/**
	 * A profile property mock.
	 *
	 * @param string $value The value.
	 * @param string $scope The scope.
	 *
	 * @return IAccountProperty The property.
	 */
	private function property(string $value, string $scope): IAccountProperty {
		$property = $this->createMock(IAccountProperty::class);
		$property->method('getValue')->willReturn($value);
		$property->method('getScope')->willReturn($scope);
		return $property;
	}//end property()

	/**
	 * The controller over one known user with the given properties.
	 *
	 * @param array<string, IAccountProperty|null> $properties Properties by name; null is missing.
	 *
	 * @return StaffProfileController The controller.
	 */
	private function controller(array $properties): StaffProfileController {
		$user = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid) => ($uid === 'fatma' ? $user : null));

		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willReturnCallback(function (string $name) use ($properties) {
			if (($properties[$name] ?? null) === null) {
				throw new PropertyDoesNotExistException($name);
			}
			return $properties[$name];
		});
		$accounts = $this->createMock(IAccountManager::class);
		$accounts->method('getAccount')->willReturn($account);

		return new StaffProfileController($this->createMock(IRequest::class), $users, $accounts);
	}//end controller()

	/**
	 * Function is the headline, department the organisation.
	 *
	 * @return void
	 */
	public function testReadsFunctionAndDepartmentFromTheProfile(): void {
		$controller = $this->controller([
			IAccountManager::PROPERTY_HEADLINE     => $this->property('Woo-adviseur', IAccountManager::SCOPE_LOCAL),
			IAccountManager::PROPERTY_ORGANISATION => $this->property(' Woo en informatie ', IAccountManager::SCOPE_LOCAL),
		]);

		$response = $controller->show('fatma');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['function' => 'Woo-adviseur', 'department' => 'Woo en informatie'], $response->getData());
	}//end testReadsFunctionAndDepartmentFromTheProfile()

	/**
	 * A private or missing property reads as empty.
	 *
	 * @return void
	 */
	public function testPrivateAndMissingPropertiesReadAsEmpty(): void {
		$controller = $this->controller([
			IAccountManager::PROPERTY_HEADLINE => $this->property('Woo-adviseur', IAccountManager::SCOPE_PRIVATE),
		]);

		$this->assertSame(['function' => '', 'department' => ''], $controller->show('fatma')->getData());
	}//end testPrivateAndMissingPropertiesReadAsEmpty()

	/**
	 * An unknown user is a 404.
	 *
	 * @return void
	 */
	public function testUnknownUserIsNotFound(): void {
		$this->assertSame(404, $this->controller([])->show('nobody')->getStatus());
	}//end testUnknownUserIsNotFound()
}//end class
