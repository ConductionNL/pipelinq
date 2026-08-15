<?php

/**
 * SettingsController instance-wide write-path unit tests.
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
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SettingsController;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * `PUT /api/settings` (`settings#update`) is the canonical ADR-066
 * instance-wide write; `POST /api/settings` (`settings#create`) is the legacy
 * alias kept for the live frontend callers.
 *
 * These tests assert the ITEM — that the write actually reaches
 * `SettingsService::updateSettings()` with the request's own parameters and
 * that the response carries what the service stored. A test that only checked
 * for a JSONResponse, or only for `success => true`, would pass against a
 * controller that silently wrote nothing.
 *
 * The auth tests exist because the single highest-risk mistake available here
 * is copying `#[NoAdminRequired]` from `updateUserSettings()` — a legitimately
 * non-admin PER-USER write — onto the INSTANCE-WIDE write.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class SettingsControllerWriteTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->settingsService = $this->createMock(originalClassName: SettingsService::class);

	}//end setUp()

	/**
	 * Build the controller under test with its collaborators mocked.
	 *
	 * @return SettingsController The controller under test.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function controller(): SettingsController {
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession->method('getUser')->willReturn($user);

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new SettingsController(
			request: $this->request,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			appManager: $this->createMock(originalClassName: IAppManager::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			settingsService: $this->settingsService,
			userSession: $userSession,
			l10n: $l10n,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

	}//end controller()

	/**
	 * PUT /api/settings must persist the request parameters and return the
	 * config the service actually stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void {
		$submitted = ['register' => '16', 'lead_schema' => '62'];
		$stored = ['register' => '16', 'lead_schema' => '62', 'currency' => 'EUR'];

		$this->request->method('getParams')->willReturn($submitted);

		// The ITEM: the write reaches the service, with the submitted params.
		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller()->update();

		$this->assertSame(
			['success' => true, 'config' => $stored],
			$response->getData(),
			'update() must return the config the service stored, not the submission'
		);

	}//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()

	/**
	 * POST /api/settings is a legacy alias and must write identically.
	 *
	 * `src/views/settings/ExportConfigurationSettings.vue::save()` still posts
	 * here, so the alias staying a real write — not an empty success — is
	 * load-bearing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testCreateDelegatesToUpdateAndStillWrites(): void {
		$submitted = ['export.retention_days' => '30'];
		$stored = ['export.retention_days' => '30'];

		$this->request->method('getParams')->willReturn($submitted);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with($submitted)
			->willReturn($stored);

		$response = $this->controller()->create();

		$this->assertSame(
			['success' => true, 'config' => $stored],
			$response->getData(),
			'create() must produce the same written result as update()'
		);

	}//end testCreateDelegatesToUpdateAndStillWrites()

	/**
	 * An empty submission must still reach the service.
	 *
	 * An early return on an empty payload looks identical, from the caller's
	 * side, to a successful no-op write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testEmptySubmissionStillReachesTheService(): void {
		$this->request->method('getParams')->willReturn([]);

		$this->settingsService->expects($this->once())
			->method('updateSettings')
			->with([])
			->willReturn(['unchanged' => true]);

		$response = $this->controller()->update();

		$this->assertSame(
			['success' => true, 'config' => ['unchanged' => true]],
			$response->getData()
		);

	}//end testEmptySubmissionStillReachesTheService()

	/**
	 * The instance-wide write must NOT touch the per-user surface.
	 *
	 * `updateUserSettings()` is a different scope with a different (non-admin)
	 * posture; `update()` writing through it would silently widen who can
	 * change instance configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testUpdateDoesNotWriteThroughThePerUserSurface(): void {
		$this->request->method('getParams')->willReturn(['register' => '16']);
		$this->settingsService->method('updateSettings')->willReturn(['register' => '16']);

		$this->settingsService->expects($this->never())->method('updateUserSettings');
		$this->settingsService->expects($this->never())->method('getUserSettings');

		$this->controller()->update();

	}//end testUpdateDoesNotWriteThroughThePerUserSurface()

	/**
	 * Collect the attribute class names declared on a controller method.
	 *
	 * Attribute NAMES are read as strings so the assertion does not depend on
	 * the attribute class being instantiable in the unit harness.
	 *
	 * @param string $method The controller method name.
	 *
	 * @return array<int, string> The declared attribute class names.
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	private function attributeNamesOf(string $method): array {
		$reflection = new ReflectionClass(SettingsController::class);
		$this->assertTrue($reflection->hasMethod($method),
			sprintf('SettingsController::%s() does not exist', $method)
		);

		return array_map(
			static fn ($attribute) => $attribute->getName(),
			$reflection->getMethod($method)->getAttributes()
		);

	}//end attributeNamesOf()

	/**
	 * The instance-wide write carries the admin posture, and only that.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testUpdateCarriesTheInstanceWideAdminPosture(): void {
		$attributes = $this->attributeNamesOf('update');

		$this->assertContains(
			AuthorizedAdminSetting::class,
			$attributes,
			'SettingsController::update() is the INSTANCE-WIDE write and must carry '
			. '#[AuthorizedAdminSetting].'
		);

		$this->assertNotContains(
			NoAdminRequired::class,
			$attributes,
			'SettingsController::update() must NOT carry #[NoAdminRequired]. That posture '
			. 'belongs to the per-user write updateUserSettings() and to the index() read; '
			. 'on the instance-wide write it would let any authenticated user rewrite the '
			. 'register/schema bindings for everyone.'
		);

	}//end testUpdateCarriesTheInstanceWideAdminPosture()

	/**
	 * The legacy alias keeps its OWN attribute.
	 *
	 * Nextcloud's SecurityMiddleware evaluates the attributes of the
	 * DISPATCHED method only, so `create()` delegating to `update()` does not
	 * inherit `update()`'s posture. Dropping the attribute here would make
	 * `POST /api/settings` non-admin while `PUT` stayed admin-only.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testCreateKeepsItsOwnAdminPostureDespiteDelegating(): void {
		$attributes = $this->attributeNamesOf('create');

		$this->assertContains(
			AuthorizedAdminSetting::class,
			$attributes,
			'SettingsController::create() must keep its own #[AuthorizedAdminSetting]; '
			. 'delegating to update() does not carry the attribute across the dispatcher.'
		);

		$this->assertNotContains(NoAdminRequired::class, $attributes);

		$this->assertSame($this->attributeNamesOf('update'),
			$attributes,
			'create() and update() are the same instance-wide write and must share '
			. 'an identical auth posture — the net privilege change of this conversion is zero.'
		);

	}//end testCreateKeepsItsOwnAdminPostureDespiteDelegating()

	/**
	 * The per-user write is the contrast case and stays non-admin.
	 *
	 * This is the positive control for the two assertions above: it proves the
	 * attribute reader can actually observe `#[NoAdminRequired]` on this class,
	 * so `assertNotContains()` on `update()` is a real measurement rather than
	 * a reader that never finds anything.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function testPerUserWriteRemainsNonAdminAndProvesTheAttributeReaderWorks(): void {
		$attributes = $this->attributeNamesOf('updateUserSettings');

		$this->assertContains(
			NoAdminRequired::class,
			$attributes,
			'updateUserSettings() is a PER-USER write and must stay #[NoAdminRequired]. '
			. 'If this fails, every assertNotContains(NoAdminRequired) above is unreadable.'
		);

		$this->assertNotContains(
			AuthorizedAdminSetting::class,
			$attributes,
			'The per-user write must not be gated behind the admin settings panel.'
		);

	}//end testPerUserWriteRemainsNonAdminAndProvesTheAttributeReaderWorks()
}//end class
