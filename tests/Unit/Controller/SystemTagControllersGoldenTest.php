<?php

/**
 * Golden tests for the two system-tag admin controllers: lead sources and
 * request channels.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\LeadSourceController;
use OCA\Pipelinq\Controller\RequestChannelController;
use OCA\Pipelinq\Service\SystemTagService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Every allowed and refused outcome of both controllers, and the access
 * attribute on each action.
 */
class SystemTagControllersGoldenTest extends TestCase {
	/**
	 * The controllers and their tag types and log labels.
	 *
	 * @return array<string, array{0: class-string<Controller>, 1: string, 2: string}>
	 */
	public static function controllerProvider(): array {
		return [
			'lead sources' => [LeadSourceController::class, 'pipelinq_lead_source', 'LeadSourceController::destroy failed'],
			'request channels' => [RequestChannelController::class, 'pipelinq_request_channel', 'RequestChannelController::destroy failed'],
		];
	}//end controllerProvider()

	/**
	 * Listing needs a logged-in user (any user); the other actions are
	 * admin-setting actions.
	 *
	 * @param class-string<Controller> $class The controller.
	 *
	 * @return void
	 *
	 * @dataProvider controllerProvider
	 */
	public function testAccessAttributes(string $class): void {
		$attributes = [];
		foreach (['index', 'create', 'update', 'destroy'] as $action) {
			$method = new ReflectionMethod($class, $action);
			$attributes[$action] = array_map(
				static fn ($attribute): array => [$attribute->getName(), $attribute->getArguments()],
				$method->getAttributes()
			);
		}

		$admin = [[AuthorizedAdminSetting::class, ['pipelinq']]];
		$this->assertSame(
			['index' => [[NoAdminRequired::class, []]], 'create' => $admin, 'update' => $admin, 'destroy' => $admin],
			$attributes
		);
	}//end testAccessAttributes()

	/**
	 * Listing without a user is refused with 401 and lists nothing; with a
	 * user it lists the controller's own tag type.
	 *
	 * @param class-string<Controller> $class The controller.
	 * @param string                   $type  The tag type.
	 *
	 * @return void
	 *
	 * @dataProvider controllerProvider
	 */
	public function testIndexNeedsAUser(string $class, string $type): void {
		$tags = $this->createMock(SystemTagService::class);
		$tags->expects($this->never())->method('getTags');
		$response = $this->controller(class: $class, tags: $tags, user: false)->index();
		$this->assertSame([401, ['message' => 'Authentication required']], [$response->getStatus(), $response->getData()]);

		$tags = $this->createMock(SystemTagService::class);
		$tags->expects($this->once())->method('getTags')->with($type)->willReturn([['id' => 1, 'name' => 'Web']]);
		$response = $this->controller(class: $class, tags: $tags, user: true)->index();
		$this->assertSame([200, ['success' => true, 'tags' => [['id' => 1, 'name' => 'Web']]]], [$response->getStatus(), $response->getData()]);
	}//end testIndexNeedsAUser()

	/**
	 * Create and rename pass the tag type, the name (default '') and the id,
	 * and answer a service refusal with 400 and its message.
	 *
	 * @param class-string<Controller> $class The controller.
	 * @param string                   $type  The tag type.
	 *
	 * @return void
	 *
	 * @dataProvider controllerProvider
	 */
	public function testCreateAndRename(string $class, string $type): void {
		$tags = $this->createMock(SystemTagService::class);
		$tags->expects($this->once())->method('addTag')->with($type, 'Phone')->willReturn(['id' => 4, 'name' => 'Phone']);
		$tags->expects($this->once())->method('renameTag')->with($type, 12, '')->willReturn(['id' => 12, 'name' => '']);
		$controller = $this->controller(class: $class, tags: $tags, user: false, params: ['name' => 'Phone']);
		$response = $controller->create();
		$this->assertSame([200, ['success' => true, 'tag' => ['id' => 4, 'name' => 'Phone']]], [$response->getStatus(), $response->getData()]);

		$controller = $this->controller(class: $class, tags: $tags, user: false);
		$response = $controller->update('12abc');
		$this->assertSame([200, ['success' => true, 'tag' => ['id' => 12, 'name' => '']]], [$response->getStatus(), $response->getData()]);

		foreach ([new \InvalidArgumentException('Name taken'), new RuntimeException('Store down')] as $error) {
			$tags = $this->createMock(SystemTagService::class);
			$tags->method('addTag')->willThrowException($error);
			$tags->method('renameTag')->willThrowException($error);
			$controller = $this->controller(class: $class, tags: $tags, user: false);
			foreach ([$controller->create(), $controller->update('1')] as $response) {
				$this->assertSame([400, ['success' => false, 'message' => $error->getMessage()]], [$response->getStatus(), $response->getData()]);
			}
		}
	}//end testCreateAndRename()

	/**
	 * Delete removes the tag of the controller's type; any failure is logged
	 * and answered with 500 and a generic message.
	 *
	 * @param class-string<Controller> $class The controller.
	 * @param string                   $type  The tag type.
	 * @param string                   $label The log message.
	 *
	 * @return void
	 *
	 * @dataProvider controllerProvider
	 */
	public function testDestroy(string $class, string $type, string $label): void {
		$tags = $this->createMock(SystemTagService::class);
		$tags->expects($this->once())->method('removeTag')->with($type, 7);
		$response = $this->controller(class: $class, tags: $tags, user: false)->destroy('7');
		$this->assertSame([200, ['success' => true]], [$response->getStatus(), $response->getData()]);

		$error = new \Exception('locked');
		$tags = $this->createMock(SystemTagService::class);
		$tags->method('removeTag')->willThrowException($error);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($label, ['exception' => $error]);
		$response = $this->controller(class: $class, tags: $tags, user: false, logger: $logger)->destroy('7');
		$this->assertSame([500, ['success' => false, 'message' => 'An unexpected error occurred']], [$response->getStatus(), $response->getData()]);
	}//end testDestroy()

	/**
	 * Build a controller.
	 *
	 * @param class-string<Controller> $class  The controller.
	 * @param SystemTagService         $tags   The tag service.
	 * @param bool                     $user   Whether a user is logged in.
	 * @param array<string, string>    $params The request parameters.
	 * @param LoggerInterface|null     $logger The logger.
	 *
	 * @return LeadSourceController|RequestChannelController
	 */
	private function controller(string $class, SystemTagService $tags, bool $user, array $params = [], ?LoggerInterface $logger = null): Controller {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $this->createMock(IUser::class) : null);

		return new $class($request, $tags, $session, $logger ?? $this->createMock(LoggerInterface::class));
	}//end controller()
}//end class
