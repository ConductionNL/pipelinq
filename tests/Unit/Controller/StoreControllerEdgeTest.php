<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\StoreController;
use OCA\Pipelinq\Kernel\Contracts\RegisterResolverServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\StoreInstallLedgerInterface;
use OCA\Pipelinq\Kernel\Contracts\StoreTemplateAssemblerInterface;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The refusals and failure paths of the store routes, and saving the connection.
 */
class StoreControllerEdgeTest extends TestCase {

	/**
	 * The store engine.
	 *
	 * @var GenericStoreService&MockObject
	 */
	private GenericStoreService&MockObject $store;

	/**
	 * The install ledger.
	 *
	 * @var StoreInstallLedgerInterface&MockObject
	 */
	private StoreInstallLedgerInterface&MockObject $ledger;

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * App config values written, as key => value.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed> $params   Request parameters.
	 * @param bool                 $signedIn Whether a user is signed in.
	 *
	 * @return StoreController
	 */
	private function controller(array $params = [], bool $signedIn = true): StoreController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true ? $this->createMock(IUser::class) : null));

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->written[$key] ?? (str_ends_with($key, '_schema') === true ? '51' : $default))
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);

		$resolver = $this->createMock(RegisterResolverServiceInterface::class);
		$resolver->method('resolve')->willReturn('18');

		$this->store   = $this->createMock(GenericStoreService::class);
		$this->ledger  = $this->createMock(StoreInstallLedgerInterface::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);

		$authorizer = $this->createMock(StoreActionAuthorizer::class);
		$authorizer->method('canPublish')->willReturn(true);

		return new StoreController(
			request: $request,
			logger: $this->createMock(LoggerInterface::class),
			userSession: $session,
			appConfig: $config,
			storeService: $this->store,
			objectService: $this->objects,
			registerResolver: $resolver,
			installLedger: $this->ledger,
			assembler: $this->createMock(StoreTemplateAssemblerInterface::class),
			actionAuthorizer: $authorizer,
		);
	}//end controller()

	/**
	 * A malformed slug is refused before the registry is asked.
	 *
	 * @return void
	 */
	public function testAMalformedSlugIsABadRequest(): void {
		$controller = $this->controller();
		$this->store->expects($this->never())->method('resolve');

		foreach (['-lead', 'Upper', 'a', 'x/../y'] as $slug) {
			$response = $controller->install(slug: $slug);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $slug);
			$this->assertSame(['success' => false, 'message' => 'Malformed item slug.'], $response->getData());
		}
	}//end testAMalformedSlugIsABadRequest()

	/**
	 * A failing or empty resolve is 404 and records nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvedItemIsNotFound(): void {
		$controller = $this->controller();
		$this->store->method('resolve')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('registry down')),
			null,
		);
		$this->ledger->expects($this->never())->method('record');

		foreach (['broken-item', 'missing-item'] as $slug) {
			$response = $controller->install(slug: $slug);
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame('The store item could not be resolved.', $response->getData()['message']);
		}
	}//end testAnUnresolvedItemIsNotFound()

	/**
	 * Components shipped as a JSON string install; a component without an object is refused.
	 *
	 * @return void
	 */
	public function testComponentsFromAJsonStringAndAMissingObject(): void {
		$controller = $this->controller();
		$this->store->method('resolve')->willReturn(
			[
				'version' => '2.0.0',
				'components' => json_encode([['schema' => 'skill', 'object' => ['id' => 'x', 'title' => 'Calls']], ['schema' => 'product'], 'junk']),
			]
		);
		$saved = [];
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntityInterface {
				$saved[] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);
		$this->ledger->expects($this->never())->method('record');

		$data = $controller->install(slug: 'starter-kit')->getData();

		$this->assertFalse($data['success']);
		$this->assertSame([['title' => 'Calls']], $saved);
		$this->assertSame(['installed', 'refused'], array_column($data['components'], 'status'));
		$this->assertSame('The component declares no object to install.', $data['components'][1]['message']);
	}//end testComponentsFromAJsonStringAndAMissingObject()

	/**
	 * An item whose components are not a list installs nothing and is not a success.
	 *
	 * @return void
	 */
	public function testAnItemWithoutComponentsIsNotASuccess(): void {
		$controller = $this->controller();
		$this->store->method('resolve')->willReturn(['components' => 42]);
		$this->ledger->expects($this->never())->method('record');

		$this->assertSame(['success' => false, 'components' => []], $controller->install(slug: 'empty-item')->getData());
	}//end testAnItemWithoutComponentsIsNotASuccess()

	/**
	 * A search that throws reports the registry as unreachable, with no cards.
	 *
	 * @return void
	 */
	public function testAFailingSearchIsUnreachable(): void {
		$controller = $this->controller(['q' => 'sales']);
		$this->store->method('search')->willThrowException(new RuntimeException('timeout'));

		$response = $controller->search();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []], $response->getData());
	}//end testAFailingSearchIsUnreachable()

	/**
	 * Without a user, publishing and the publish dialog are 401.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerMayNotPublish(): void {
		$controller = $this->controller(signedIn: false);
		$this->store->expects($this->never())->method('publish');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->publishable()->getStatus());
		$response = $controller->publish();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['outcome' => 'unauthenticated', 'slug' => ''], $response->getData());
	}//end testAnAnonymousCallerMayNotPublish()

	/**
	 * A publish body whose item is not an object is a bad request.
	 *
	 * @return void
	 */
	public function testAPublishWithoutAnItemIsABadRequest(): void {
		$controller = $this->controller(['item' => 'not-an-object']);
		$this->store->expects($this->never())->method('publish');

		$response = $controller->publish();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(GenericStoreService::OUTCOME_NOT_PUBLISHABLE, $response->getData()['outcome']);
	}//end testAPublishWithoutAnItemIsABadRequest()

	/**
	 * Saving trims the values; an empty token or register leaves the stored one.
	 *
	 * @return void
	 */
	public function testSaveSettingsKeepsTheTokenWhenLeftEmpty(): void {
		$this->written = ['registry_token' => 'secret'];
		$controller    = $this->controller(['registryUrl' => ' https://store.example.nl ', 'registryRegister' => ' ', 'registryToken' => '']);

		$data = $controller->saveSettings()->getData();

		$this->assertSame(['registry_token' => 'secret', 'registry_url' => 'https://store.example.nl'], $this->written);
		$this->assertSame(['registryUrl' => 'https://store.example.nl', 'registryRegister' => 'pipelinq', 'registryTokenSet' => true], $data);

		$this->controller(['registryRegister' => 'shared ', 'registryToken' => ' new '])->saveSettings();
		$this->assertSame('shared', $this->written['registry_register']);
		$this->assertSame('new', $this->written['registry_token']);
	}//end testSaveSettingsKeepsTheTokenWhenLeftEmpty()
}//end class
