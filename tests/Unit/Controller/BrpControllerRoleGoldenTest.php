<?php

/**
 * Characterisation tests for the BrpController role resolution, the
 * monitor report and the latest linked BRP person.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\BrpController;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\OptOutService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins who resolveActorRole() admits and refuses, monitor()'s answers and
 * which person findLatestPersonForContact() picks.
 */
class BrpControllerRoleGoldenTest extends TestCase {

	/**
	 * The object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * Logged errors.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, string> $config  App config.
	 * @param bool                  $hasUser Whether a user is logged in.
	 * @param array<int, string>    $admins  Admin uids.
	 * @param array<string, bool>   $groups  Existing groups and whether the user is in them.
	 *
	 * @return BrpController
	 */
	private function controller(array $config = [], bool $hasUser = true, array $admins = [], array $groups = []): BrpController {
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'u-1']);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($hasUser === true ? $user : null);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, $admins, true));
		$groupManager->method('get')->willReturnCallback(
			function (string $groupId) use ($groups, $user): ?IGroup {
				if (array_key_exists($groupId, $groups) === false) {
					return null;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('inGroup')->willReturnCallback(static fn (IUser $who): bool => ($who === $user && $groups[$groupId] === true));
				return $group;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);
		$this->objects = $this->createMock(ObjectServiceInterface::class);

		return new BrpController(
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(ObjectOwnerAccessPolicy::class),
			$groupManager,
			$l10n,
			$appConfig,
			$this->createMock(BsnValidationService::class),
			$this->createMock(BrpCacheService::class),
			$this->createMock(HaalCentraalClient::class),
			$this->createMock(BsnAuditService::class),
			$this->createMock(OptOutService::class),
			$this->createMock(BrpMutationWebhookListener::class),
			$logger,
			objectService: $this->objects,
		);
	}//end controller()

	/**
	 * Call a private controller method.
	 *
	 * @param BrpController     $controller The controller.
	 * @param string            $method     The method.
	 * @param array<int, mixed> $args       The arguments.
	 *
	 * @return mixed
	 */
	private static function call(BrpController $controller, string $method, array $args): mixed {
		return (new ReflectionMethod($controller, $method))->invokeArgs($controller, $args);
	}//end call()

	/**
	 * Who is admitted with which role, and who is refused.
	 *
	 * @return void
	 */
	public function testResolveActorRole(): void {
		$role = static fn (BrpController $controller): ?string => self::call($controller, 'resolveActorRole', ['u-1']);

		$this->assertSame('beheerder', $role($this->controller([], false, ['u-1'])));
		$this->assertSame('behandelaar-burgerzaken', $role($this->controller([], true, [], ['behandelaar-burgerzaken' => true, 'behandelaar-avg' => true])));
		$this->assertSame('behandelaar-avg', $role($this->controller([], true, [], ['behandelaar-burgerzaken' => false, 'behandelaar-avg' => true])));
		$this->assertNull($role($this->controller([], true, [], ['behandelaar-burgerzaken' => false])));
		$this->assertNull($role($this->controller([], true, [], [])));
		$this->assertNull($role($this->controller([], false, [], ['behandelaar-avg' => true])));

		$configured = ['brp.allowed_groups' => ' kcc , ,burgerzaken '];
		$this->assertSame('burgerzaken', $role($this->controller($configured, true, [], ['kcc' => false, 'burgerzaken' => true, 'behandelaar-avg' => true])));
		$this->assertNull($role($this->controller($configured, true, [], ['behandelaar-avg' => true])));
		$this->assertSame('kcc', $role($this->controller($configured, true, ['other'], ['kcc' => true])));
		$this->assertNull($role($this->controller(['brp.allowed_groups' => ' , '], true, [], ['behandelaar-avg' => true])));
	}//end testResolveActorRole()

	/**
	 * The latest person: the query, the newest fetchedOn, entity rows, ties
	 * and failures.
	 *
	 * @return void
	 */
	public function testFindLatestPersonForContact(): void {
		$configured = ['register' => 'reg', 'brpPersoon_schema' => 'person'];
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['name' => 'entity', 'fetchedOn' => '2026-03-01'];
			}
		};
		$controller = $this->controller($configured);
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['gekoppeldContact' => 'c-1', 'register' => 'reg', 'schema' => 'person']])
			->willReturn(
				[
					['name' => 'old', 'fetchedOn' => '2026-01-01'],
					$entity,
					['name' => 'tie', 'fetchedOn' => '2026-03-01'],
					['name' => 'undated'],
				]
			);
		$this->assertSame(['name' => 'entity', 'fetchedOn' => '2026-03-01'], self::call($controller, 'findLatestPersonForContact', ['c-1']));

		$controller = $this->controller($configured);
		$this->objects->method('findAll')->willReturn([['name' => 'undated'], ['name' => 'second']]);
		$this->assertSame(['name' => 'undated'], self::call($controller, 'findLatestPersonForContact', ['c-1']));

		$controller = $this->controller($configured);
		$this->objects->method('findAll')->willReturn([]);
		$this->assertNull(self::call($controller, 'findLatestPersonForContact', ['c-1']));

		$controller = $this->controller($configured);
		$this->objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->assertNull(self::call($controller, 'findLatestPersonForContact', ['c-1']));
		$this->assertSame(['findLatestPersoonForContact failed'], $this->errors);

		$controller = $this->controller(['register' => 'reg']);
		$this->objects->expects($this->never())->method('findAll');
		$this->assertNull(self::call($controller, 'findLatestPersonForContact', ['c-1']));
	}//end testFindLatestPersonForContact()
}//end class
