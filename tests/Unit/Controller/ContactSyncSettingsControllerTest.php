<?php

/**
 * Unit tests for ContactSyncSettingsController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactSyncSettingsController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\ContactSyncSettingsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The contacts section's endpoints.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
class ContactSyncSettingsControllerTest extends TestCase {
	/**
	 * A controller for a signed-in user.
	 *
	 * @param ContactSyncSettingsService $settings   The settings service.
	 * @param bool                       $privileged Whether the user may write the CRM to Contacts.
	 * @param mixed                      $writeBack  The writeBack parameter sent.
	 *
	 * @return ContactSyncSettingsController The controller.
	 */
	private function controller(ContactSyncSettingsService $settings, bool $privileged = true, mixed $writeBack = null): ContactSyncSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($writeBack);
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'pieter']);
		$session = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ContactSyncSettingsController(
			$request,
			$settings,
			$session,
			$this->createConfiguredMock(ObjectOwnerAccessPolicy::class, ['isPrivileged' => $privileged]),
			$l10n,
		);
	}//end controller()

	/**
	 * The switch takes a boolean and saves it for the signed-in user.
	 *
	 * @return void
	 */
	public function testUpdateSavesTheSwitchForTheSignedInUser(): void {
		$settings = $this->createMock(ContactSyncSettingsService::class);
		$settings->expects($this->once())->method('saveWriteBack')->with('pieter', false);
		$settings->method('status')->willReturn(['writeBack' => false]);

		$response = $this->controller(settings: $settings, writeBack: false)->update();

		$this->assertSame(['writeBack' => false], $response->getData());
	}//end testUpdateSavesTheSwitchForTheSignedInUser()

	/**
	 * Anything but a boolean is refused.
	 *
	 * @return void
	 */
	public function testUpdateRefusesAValueThatIsNotABoolean(): void {
		$settings = $this->createMock(ContactSyncSettingsService::class);
		$settings->expects($this->never())->method('saveWriteBack');

		$response = $this->controller(settings: $settings, writeBack: 'yes')->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testUpdateRefusesAValueThatIsNotABoolean()

	/**
	 * A full run needs the CRM capability, checked before anything is written.
	 *
	 * @return void
	 */
	public function testARunNeedsTheCrmCapability(): void {
		$settings = $this->createMock(ContactSyncSettingsService::class);
		$settings->expects($this->never())->method('run');

		$response = $this->controller(settings: $settings, privileged: false)->run();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testARunNeedsTheCrmCapability()
}//end class
