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
use OCP\IUserSession;
use OCP\Profile\IProfileManager;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;

/**
 * A colleague's function and department come from their profile, as that
 * profile shows them to the signed-in visitor; private properties, hidden
 * fields and accounts the visitor may not see read as nothing.
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
		$property = $this->createMock(originalClassName: IAccountProperty::class);
		$property->method('getValue')->willReturn($value);
		$property->method('getScope')->willReturn($scope);
		return $property;
	}//end property()

	/**
	 * A user mock.
	 *
	 * @param string $uid The user id.
	 * @param bool $enabled Whether the account is enabled.
	 *
	 * @return IUser The user.
	 */
	private function user(string $uid, bool $enabled = true): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}//end user()

	/**
	 * The signed-in user for a test: nobody, fatma herself, or another user.
	 *
	 * @param string|null $uid The visitor's user id, or null for no session.
	 * @param IUser $target The fatma mock.
	 *
	 * @return IUser|null The visitor.
	 */
	private function visitor(?string $uid, IUser $target): ?IUser {
		if ($uid === null) {
			return null;
		}

		if ($uid === 'fatma') {
			return $target;
		}

		return $this->user(uid: $uid);
	}//end visitor()

	/**
	 * The controller over one known user, fatma, with the given properties.
	 *
	 * By default the visitor is ruud, fatma's profile is on, every field is
	 * shown and ruud may find fatma. Each option overrides one of those.
	 *
	 * @param array<string, IAccountProperty|null> $properties Properties by name; null is missing.
	 * @param array{visitor?: ?string, targetEnabled?: bool, profileEnabled?: bool, hidden?: list<string>, discoverable?: bool} $options Overrides.
	 *
	 * @return StaffProfileController The controller.
	 */
	private function controller(array $properties, array $options = []): StaffProfileController {
		$user = $this->user(uid: 'fatma', enabled: $options['targetEnabled'] ?? true);
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid) use ($user): ?IUser {
				if ($uid === 'fatma') {
					return $user;
				}

				return null;
			}
		);

		$account = $this->createMock(originalClassName: IAccount::class);
		$account->method('getProperty')->willReturnCallback(function (string $name) use ($properties) {
			if (($properties[$name] ?? null) === null) {
				throw new PropertyDoesNotExistException($name);
			}
			return $properties[$name];
		});
		$accounts = $this->createMock(originalClassName: IAccountManager::class);
		$accounts->method('getAccount')->willReturn($account);

		$visitor = $this->user(uid: 'ruud');
		if (array_key_exists(key: 'visitor', array: $options) === true) {
			$visitor = $this->visitor(uid: $options['visitor'], target: $user);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($visitor);

		$hidden = $options['hidden'] ?? [];
		$profiles = $this->createMock(originalClassName: IProfileManager::class);
		$profileEnabled = $options['profileEnabled'] ?? true;
		$profiles->method('isProfileEnabled')->willReturnCallback(
			fn (?IUser $target): bool => ($target === $user && $profileEnabled)
		);
		$profiles->method('isProfileFieldVisible')->willReturnCallback(
			fn (string $field, IUser $target, ?IUser $visiting): bool => ($target === $user
				&& $visiting?->getUID() === 'ruud'
				&& in_array(needle: $field, haystack: $hidden, strict: true) === false)
		);

		$shares = $this->createMock(originalClassName: IShareManager::class);
		$discoverable = $options['discoverable'] ?? true;
		$shares->method('currentUserCanEnumerateTargetUser')->willReturnCallback(
			fn (?IUser $current, IUser $target): bool => ($target === $user && $current?->getUID() === 'ruud' && $discoverable)
		);

		return new StaffProfileController(
			request: $this->createMock(originalClassName: IRequest::class),
			userManager: $users,
			accountManager: $accounts,
			userSession: $session,
			profileManager: $profiles,
			shareManager: $shares,
		);
	}//end controller()

	/**
	 * Both fields filled at local scope.
	 *
	 * @return array<string, IAccountProperty> The properties.
	 */
	private function filled(): array {
		return [
			IAccountManager::PROPERTY_HEADLINE     => $this->property(value: 'Woo-adviseur', scope: IAccountManager::SCOPE_LOCAL),
			IAccountManager::PROPERTY_ORGANISATION => $this->property(value: 'Woo en informatie', scope: IAccountManager::SCOPE_LOCAL),
		];
	}//end filled()

	/**
	 * Assert the response is the unknown-user answer.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return void
	 */
	private function assertUnknown(\OCP\AppFramework\Http\JSONResponse $response): void {
		$unknown = $this->controller(properties: [])->show(userId: 'nobody');
		$this->assertSame(expected: 404, actual: $response->getStatus());
		$this->assertSame(expected: $unknown->getStatus(), actual: $response->getStatus());
		$this->assertSame(expected: $unknown->getData(), actual: $response->getData());
	}//end assertUnknown()

	/**
	 * Function is the headline, department the organisation.
	 *
	 * @return void
	 */
	public function testReadsFunctionAndDepartmentFromTheProfile(): void {
		$controller = $this->controller(properties: [
			IAccountManager::PROPERTY_HEADLINE     => $this->property(value: 'Woo-adviseur', scope: IAccountManager::SCOPE_LOCAL),
			IAccountManager::PROPERTY_ORGANISATION => $this->property(value: ' Woo en informatie ', scope: IAccountManager::SCOPE_LOCAL),
		]);

		$response = $controller->show(userId: 'fatma');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['function' => 'Woo-adviseur', 'department' => 'Woo en informatie'], actual: $response->getData());
	}//end testReadsFunctionAndDepartmentFromTheProfile()

	/**
	 * A private or missing property reads as empty.
	 *
	 * @return void
	 */
	public function testPrivateAndMissingPropertiesReadAsEmpty(): void {
		$controller = $this->controller(properties: [
			IAccountManager::PROPERTY_HEADLINE => $this->property(value: 'Woo-adviseur', scope: IAccountManager::SCOPE_PRIVATE),
		]);

		$this->assertSame(expected: ['function' => '', 'department' => ''], actual: $controller->show(userId: 'fatma')->getData());
	}//end testPrivateAndMissingPropertiesReadAsEmpty()

	/**
	 * An unknown user is a 404.
	 *
	 * @return void
	 */
	public function testUnknownUserIsNotFound(): void {
		$this->assertSame(expected: 404, actual: $this->controller(properties: [])->show(userId: 'nobody')->getStatus());
	}//end testUnknownUserIsNotFound()
	/**
	 * A visitor who may see the profile gets both fields, as before.
	 *
	 * @return void
	 */
	public function testAVisitorWhoMaySeeTheProfileGetsBothFields(): void {
		$response = $this->controller(properties: $this->filled())->show(userId: 'fatma');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['function' => 'Woo-adviseur', 'department' => 'Woo en informatie'], actual: $response->getData());
	}//end testAVisitorWhoMaySeeTheProfileGetsBothFields()

	/**
	 * The answer is judged for the signed-in user, not for anyone else: a
	 * different signed-in user whom the profile does not admit gets the
	 * unknown answer.
	 *
	 * @return void
	 */
	public function testTheSignedInUserIsTheVisitorJudged(): void {
		$this->assertUnknown(response: $this->controller(properties: $this->filled(), options: ['visitor' => 'mallory'])->show(userId: 'fatma'));
	}//end testTheSignedInUserIsTheVisitorJudged()

	/**
	 * Without a signed-in user nothing is answered.
	 *
	 * @return void
	 */
	public function testNoSignedInUserGetsTheUnknownAnswer(): void {
		$this->assertUnknown(response: $this->controller(properties: $this->filled(), options: ['visitor' => null])->show(userId: 'fatma'));
	}//end testNoSignedInUserGetsTheUnknownAnswer()

	/**
	 * A profile that is switched off answers as an unknown user.
	 *
	 * @return void
	 */
	public function testADisabledProfileAnswersAsUnknown(): void {
		$this->assertUnknown(response: $this->controller(properties: $this->filled(), options: ['profileEnabled' => false])->show(userId: 'fatma'));
	}//end testADisabledProfileAnswersAsUnknown()

	/**
	 * A disabled account answers as an unknown user.
	 *
	 * @return void
	 */
	public function testADisabledAccountAnswersAsUnknown(): void {
		$this->assertUnknown(response: $this->controller(properties: $this->filled(), options: ['targetEnabled' => false])->show(userId: 'fatma'));
	}//end testADisabledAccountAnswersAsUnknown()

	/**
	 * An account the visitor may not find under the user search settings
	 * answers as an unknown user.
	 *
	 * @return void
	 */
	public function testAnAccountTheVisitorMayNotFindAnswersAsUnknown(): void {
		$this->assertUnknown(response: $this->controller(properties: $this->filled(), options: ['discoverable' => false])->show(userId: 'fatma'));
	}//end testAnAccountTheVisitorMayNotFindAnswersAsUnknown()

	/**
	 * A field the profile hides from the visitor reads as empty; the other
	 * field still shows.
	 *
	 * @return void
	 */
	public function testAFieldTheProfileHidesReadsAsEmpty(): void {
		$controller = $this->controller(properties: $this->filled(), options: ['hidden' => [IAccountManager::PROPERTY_HEADLINE]]);
		$this->assertSame(expected: ['function' => '', 'department' => 'Woo en informatie'], actual: $controller->show(userId: 'fatma')->getData());

		$controller = $this->controller(properties: $this->filled(), options: ['hidden' => [IAccountManager::PROPERTY_ORGANISATION]]);
		$this->assertSame(expected: ['function' => 'Woo-adviseur', 'department' => ''], actual: $controller->show(userId: 'fatma')->getData());
	}//end testAFieldTheProfileHidesReadsAsEmpty()

	/**
	 * Looking up one's own profile skips the user search check.
	 *
	 * @return void
	 */
	public function testOwnProfileIsAlwaysFound(): void {
		$response = $this->controller(properties: $this->filled(), options: ['visitor' => 'fatma', 'discoverable' => false])->show(userId: 'fatma');

		$this->assertSame(expected: 200, actual: $response->getStatus());
	}//end testOwnProfileIsAlwaysFound()
}//end class
