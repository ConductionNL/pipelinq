<?php

/**
 * Unit tests for StoreController.
 *
 * These cover the four properties a browser cannot observe, which is why every
 * one of them carries an `@e2e exclude` in the spec:
 *
 *   - the install allowlist refuses record schemas (REQ-PLQ-STORE-003);
 *   - the registry token never leaves the server (REQ-PLQ-STORE-004);
 *   - an install CREATES and can never replace (REQ-PLQ-STORE-007);
 *   - an unconfigured schema errors rather than writing into nothing
 *     (REQ-PLQ-STORE-008).
 *
 * Each is written so that removing the guard it covers makes it FAIL. That
 * property is stated per test, because a security test that would pass with the
 * boundary deleted is decoration.
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

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\StoreController;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for StoreController.
 */
class StoreControllerTest extends TestCase {
	/**
	 * Build a controller with the collaborators a test wants to steer.
	 *
	 * @param ObjectServiceInterface|null $objectService Object write seam.
	 * @param GenericStoreService|null $storeService Engine discovery client.
	 * @param IAppConfig|null $appConfig App config.
	 * @param bool $signedIn Whether a user is signed in.
	 * @param string $registerId The resolved register id.
	 *
	 * @return StoreController
	 */
	private function controller(
		?ObjectServiceInterface $objectService = null,
		?GenericStoreService $storeService = null,
		?IAppConfig $appConfig = null,
		bool $signedIn = true,
		string $registerId = '18',
	): StoreController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		$resolver = $this->createMock(originalClassName: RegisterResolverService::class);
		$resolver->method('resolve')->willReturn($registerId);

		if ($appConfig === null) {
			$appConfig = $this->createMock(originalClassName: IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(
				static function (string $app, string $key, string $default = ''): string {
					if (str_ends_with($key, '_schema') === true) {
						return '51';
					}

					return $default;
				}
			);
		}

		return new StoreController(
			request: $this->createMock(originalClassName: IRequest::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			userSession: $session,
			appConfig: $appConfig,
			storeService: ($storeService ?? $this->createMock(originalClassName: GenericStoreService::class)),
			objectService: ($objectService ?? $this->createMock(originalClassName: ObjectServiceInterface::class)),
			registerResolver: $resolver,
		);
	}//end controller()

	/**
	 * Call a private method on the controller.
	 *
	 * `installComponents()` is private on purpose: it is not a route, and the
	 * only public way in is `install()`, which first calls the engine over the
	 * network. Reflecting into it tests the boundary without inventing a
	 * seam that production has no use for.
	 *
	 * @param StoreController $controller The controller.
	 * @param string $method The method name.
	 * @param array<int, mixed> $args The arguments.
	 *
	 * @return mixed The return value.
	 */
	private function callPrivate(StoreController $controller, string $method, array $args): mixed {
		$ref = new ReflectionClass(objectOrClass: $controller);
		$m = $ref->getMethod(name: $method);
		$m->setAccessible(accessible: true);

		return $m->invokeArgs($controller, $args);
	}//end callPrivate()

	/**
	 * A configuration component is written.
	 *
	 * The positive control for the allowlist: without it the refusal tests
	 * below could pass simply because nothing ever installs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testConfigurationComponentInstalls(): void {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('saveObject');

		$result = $this->callPrivate(
			controller: $this->controller(objectService: $objectService),
			method: 'installComponents',
			args: 			[[['schema' => 'pipeline', 'object' => ['title' => 'B2B']]]]
		);

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: 'installed', actual: $result['components'][0]['status']);
	}//end testConfigurationComponentInstalls()

	/**
	 * A record component is refused, and nothing is written.
	 *
	 * NEGATIVE CONTROL: adding `lead` to StoreController::INSTALLABLE_SLUGS
	 * makes this fail, so the assertion is known to bind to the allowlist
	 * rather than to some incidental property of the payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testRecordComponentIsRefused(): void {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->callPrivate(
			controller: $this->controller(objectService: $objectService),
			method: 'installComponents',
			args: 			[[['schema' => 'lead', 'object' => ['title' => 'Not yours to write']]]]
		);

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: 'refused', actual: $result['components'][0]['status']);
	}//end testRecordComponentIsRefused()

	/**
	 * A mixed item installs the half it may and refuses the half it may not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testMixedItemInstallsOnlyConfiguration(): void {
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('saveObject');

		$result = $this->callPrivate(
			controller: $this->controller(objectService: $objectService),
			method: 'installComponents',
			args: 			[
				[
					['schema' => 'pipeline', 'object' => ['title' => 'B2B']],
					['schema' => 'client', 'object' => ['name' => 'Acme']],
				],
			]
		);

		$statuses = array_column($result['components'], 'status', 'schema');
		$this->assertSame(expected: 'installed', actual: $statuses['pipeline']);
		$this->assertSame(expected: 'refused', actual: $statuses['client']);
	}//end testMixedItemInstallsOnlyConfiguration()

	/**
	 * Every identity key is stripped before the write.
	 *
	 * NEGATIVE CONTROL: deleting the `unset()` in asNewObject() makes this
	 * fail. Without the strip, saveObject() would resolve the target FROM the
	 * payload and replace a live pipeline PUT-semantically.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testInstallCreatesAndNeverReplaces(): void {
		$written = null;
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$written) {
				$written = $object;
				return null;
			}
		);

		$this->callPrivate(
			controller: $this->controller(objectService: $objectService),
			method: 'installComponents',
			args: 			[
				[
					[
						'schema' => 'pipeline',
						'object' => [
							'id' => 'a-live-local-pipeline',
							'uuid' => 'a-live-local-pipeline',
							'@self' => ['id' => 'a-live-local-pipeline'],
							'title' => 'B2B',
						],
					],
				],
			]
		);

		$this->assertIsArray(actual: $written);
		$this->assertArrayNotHasKey(key: 'id', array: $written);
		$this->assertArrayNotHasKey(key: 'uuid', array: $written);
		$this->assertArrayNotHasKey(key: '@self', array: $written);
		$this->assertSame(expected: 'B2B', actual: $written['title']);
	}//end testInstallCreatesAndNeverReplaces()

	/**
	 * An allowlisted slug with no configured schema id errors, and writes nothing.
	 *
	 * This is the failure mode the port introduced and dossiq does not have: a
	 * missing app-config key reads as an EMPTY STRING, and saveObject() with an
	 * empty schema stores the object into nothing and returns without
	 * complaining, so the install would report success having written nothing.
	 *
	 * NEGATIVE CONTROL: removing the `$schemaId === ''` guard makes this fail.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testUnconfiguredSchemaErrorsRatherThanWriting(): void {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->expects($this->never())->method('saveObject');

		$result = $this->callPrivate(
			controller: $this->controller(objectService: $objectService, appConfig: $appConfig),
			method: 'installComponents',
			args: 			[[['schema' => 'pipeline', 'object' => ['title' => 'B2B']]]]
		);

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: 'error', actual: $result['components'][0]['status']);
	}//end testUnconfiguredSchemaErrorsRatherThanWriting()

	/**
	 * Reading the settings never returns the token, under any key.
	 *
	 * Asserted over the SERIALISED body rather than per key: a leak under a
	 * different key would pass a per-key check, which is exactly the mistake
	 * this test exists to catch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testSettingsNeverReturnTheToken(): void {
		$secret = 'super-secret-registry-token';
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($secret): string {
				if ($key === 'registry_token') {
					return $secret;
				}

				return $default;
			}
		);

		$response = $this->controller(appConfig: $appConfig)->getSettings();
		$body = json_encode($response->getData());

		$this->assertIsString(actual: $body);
		$this->assertStringNotContainsString(needle: $secret, haystack: $body);
		$this->assertTrue(condition: $response->getData()['registryTokenSet']);
	}//end testSettingsNeverReturnTheToken()

	/**
	 * An anonymous caller gets an explicit 401 rather than a login redirect.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testAnonymousSearchIsRefused(): void {
		$response = $this->controller(signedIn: false)->search();

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $response->getData()['cards']);
	}//end testAnonymousSearchIsRefused()

	/**
	 * Every allowlisted slug has a config key, so the map lookup cannot be null.
	 *
	 * `installComponents()` reads SLUG_TO_CONFIG_KEY without a null guard, on the
	 * documented grounds that phpstan proves the key exists. phpstan runs on
	 * the analysis path; this asserts the same invariant where a reader of the
	 * test suite can see it, and fails the moment someone widens the allowlist
	 * without mapping the slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testEveryAllowlistedSlugIsMapped(): void {
		$ref = new ReflectionClass(objectOrClass: StoreController::class);
		$allowlist = $ref->getConstant(name: 'INSTALLABLE_SLUGS');
		$mapped = array_keys(\OCA\Pipelinq\Service\Settings\SchemaSlugMap::SLUG_TO_CONFIG_KEY);

		$this->assertIsArray(actual: $allowlist);
		$this->assertNotEmpty(actual: $allowlist);
		$this->assertSame(expected: [], actual: array_diff($allowlist, $mapped));
	}//end testEveryAllowlistedSlugIsMapped()

	/**
	 * The controller composes the engine client and does not extend it.
	 *
	 * A cross-app `extends` is resolved by the AUTOLOADER rather than the
	 * container, and Nextcloud's router reflects every controller during route
	 * MATCHING, so an absent OpenRegister would 500 every route in this app
	 * rather than only the store's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	public function testEngineClientIsComposedNotExtended(): void {
		$ref = new ReflectionClass(objectOrClass: StoreController::class);
		$parent = $ref->getParentClass();

		$this->assertNotFalse(condition: $parent);
		$this->assertStringStartsNotWith(prefix: 'OCA\\OpenRegister', string: $parent->getName());

		$params = array_map(
			static fn ($p): string => (string)($p->getType()?->getName() ?? ''),
			$ref->getConstructor()?->getParameters() ?? []
		);
		$this->assertContains(needle: GenericStoreService::class, haystack: $params);
	}//end testEngineClientIsComposedNotExtended()
}//end class
