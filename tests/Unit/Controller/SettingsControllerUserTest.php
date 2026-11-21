<?php

/**
 * SettingsController: user settings, a refused admin value and malformed import rejections.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\SettingsController;
use OCA\Pipelinq\Kernel\Contracts\SettingsServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * The per-user settings endpoints, a 400 for a refused admin value, and an
 * import result whose rejection list is not the shape expected.
 *
 * @covers \OCA\Pipelinq\Controller\SettingsController
 */
class SettingsControllerUserTest extends TestCase {

	/**
	 * Settings service mock.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface&MockObject $settings;

	/**
	 * Build the settings service mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsServiceInterface::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @param string|null          $uid    The logged-in user, or null for none.
	 * @param array<string, mixed> $params The request body.
	 *
	 * @return SettingsController
	 */
	private function controller(?string $uid, array $params = []): SettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new SettingsController(
			$request,
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IGroupManager::class),
			$this->settings,
			$session,
			$l10n,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * Without a session the user settings are not read.
	 *
	 * @return void
	 */
	public function testUserSettingsNeedASession(): void {
		$this->settings->expects($this->never())->method('getUserSettings');

		$response = $this->controller(null)->getUserSettings();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not logged in'], $response->getData());
	}//end testUserSettingsNeedASession()

	/**
	 * Without a session nothing is written to the user settings.
	 *
	 * @return void
	 */
	public function testUpdatingUserSettingsNeedsASession(): void {
		$this->settings->expects($this->never())->method('updateUserSettings');

		$response = $this->controller(null, ['digest' => 'weekly'])->updateUserSettings();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Not logged in'], $response->getData());
	}//end testUpdatingUserSettingsNeedsASession()

	/**
	 * The posted body is saved for the logged-in user and the result returned.
	 *
	 * @return void
	 */
	public function testUserSettingsAreSavedForTheCaller(): void {
		$this->settings->expects($this->once())->method('updateUserSettings')
			->with('alice', ['digest' => 'weekly'])
			->willReturn(['digest' => 'weekly', 'notify' => true]);

		$response = $this->controller('alice', ['digest' => 'weekly'])->updateUserSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['digest' => 'weekly', 'notify' => true], $response->getData());
	}//end testUserSettingsAreSavedForTheCaller()

	/**
	 * A value the service refuses is a 400 carrying the service's message.
	 *
	 * @return void
	 */
	public function testARefusedAdminValueIsABadRequest(): void {
		$this->settings->method('updateSettings')
			->willThrowException(new UnexpectedValueException('Store a credential reference, not the token.'));

		$response = $this->controller('admin', ['matomo_token_ref' => 'abc'])->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['success' => false, 'error' => 'Store a credential reference, not the token.'],
			$response->getData()
		);
	}//end testARefusedAdminValueIsABadRequest()

	/**
	 * A rejection list that is not a list counts as no rejections.
	 *
	 * @return void
	 */
	public function testANonListRejectionCountsAsNone(): void {
		$this->settings->method('loadSettings')->willReturn(['schemas' => ['a'], 'failed' => ['schemas' => 'broken']]);
		$this->settings->method('getSettings')->willReturn([]);

		$data = $this->controller('admin')->reimport()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('Configuration re-imported successfully', $data['message']);
		$this->assertSame([], $data['result']['rejectedSchemas']);
	}//end testANonListRejectionCountsAsNone()

	/**
	 * Malformed rejection entries are skipped; a key stands in for a missing slug.
	 *
	 * @return void
	 */
	public function testMalformedRejectionEntriesAreSkipped(): void {
		$this->settings->method('loadSettings')->willReturn(
			[
				'schemas' => [],
				'failed' => ['schemas' => ['not-an-entry', ['key' => 'lead', 'error' => 'no slug']]],
			]
		);
		$this->settings->method('getSettings')->willReturn([]);

		$data = $this->controller('admin')->reimport()->getData();

		$this->assertFalse($data['success']);
		$this->assertSame([['slug' => 'lead', 'error' => 'no slug']], $data['result']['rejectedSchemas']);
		$this->assertSame('Configuration re-imported, but OpenRegister rejected 1 schema(s): lead', $data['message']);
	}//end testMalformedRejectionEntriesAreSkipped()
}//end class
