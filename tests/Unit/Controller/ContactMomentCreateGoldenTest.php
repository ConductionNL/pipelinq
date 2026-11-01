<?php

/**
 * Golden test for appending a contact moment through the leaf controller.
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
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactMomentLeafController;
use OCA\Pipelinq\Integration\ContactMomentLeafProvider;
use OCA\Pipelinq\Service\ContactMomentFilingService;
use OCA\Pipelinq\Tests\Unit\Support\NextcloudDispatch;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * ContactMomentLeafController::create called as Nextcloud dispatches it: the
 * response, and the host id and payload that reach the provider (which does
 * the per-object authorisation). Compared with ContactMomentCreateGolden.json;
 * set CONTACT_MOMENT_CREATE_GOLDEN_RECORD=1 to rewrite it, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Controller\ContactMomentLeafController
 * @covers \OCA\Pipelinq\Util\ReadsTypedRequestParameters
 *
 * @spec openspec/changes/contact-moments-on-pipelinq-schema/specs/contactmomenten/spec.md#requirement-contact-moments-are-a-data-provider-leaf-with-append-req-cmd-003
 */
class ContactMomentCreateGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ContactMomentCreateGolden.json';

	/**
	 * What the current scenario did, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('CONTACT_MOMENT_CREATE_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$actual = json_decode($json, true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * Forged identity and authorisation values in the request change nothing.
	 *
	 * @return void
	 */
	public function testForgedIdentityParametersChangeNothing(): void {
		$forged = ['userId' => 'mallory', 'uid' => 'mallory', 'owner' => 'mallory', 'createdBy' => 'mallory', 'actor' => 'mallory', '_rbac' => false, '_multitenancy' => false];
		foreach ($this->scenarios() as $name => $scenario) {
			$plain = $this->runScenario(scenario: $scenario);
			$scenario['params'] = ($scenario['params'] ?? []) + $forged;
			$this->assertSame($plain, $this->runScenario(scenario: $scenario), $name);
		}
	}//end testForgedIdentityParametersChangeNothing()

	/**
	 * The scenarios: the request parameters and how the provider answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$full = ['hostId' => 'case-1', 'title' => 'Called back', 'channel' => 'phone', 'direction' => 'outbound', 'outcome' => 'resolved', 'summary' => 'All good', 'occurredAt' => '2026-10-01T09:00:00Z', 'client' => 'client-9'];
		return [
			'every field'                 => ['params' => $full],
			'host only'                   => ['params' => ['hostId' => 'case-1']],
			'no host'                     => ['params' => ['title' => 'x']],
			'null host'                   => ['params' => ['hostId' => null, 'title' => 'x']],
			'empty host'                  => ['params' => ['hostId' => '', 'title' => 'x']],
			'null optional values'        => ['params' => ['hostId' => 'case-1', 'title' => null, 'outcome' => null, 'client' => null]],
			'empty optional values'       => ['params' => ['hostId' => 'case-1', 'outcome' => '', 'summary' => '', 'occurredAt' => '', 'client' => '']],
			'integer host'                => ['params' => ['hostId' => 12]],
			'integer title'               => ['params' => ['hostId' => 'case-1', 'title' => 7]],
			'boolean outcome'             => ['params' => ['hostId' => 'case-1', 'outcome' => false]],
			'array client'                => ['params' => ['hostId' => 'case-1', 'client' => ['id' => 'x']]],
			'float summary'               => ['params' => ['hostId' => 'case-1', 'summary' => 2.5]],
			'unknown parameters'          => ['params' => ['hostId' => 'case-1', 'extra' => 'y', 'channel' => 'mail']],
			'provider refuses'            => ['params' => $full, 'answer' => ['status' => 403, 'error' => 'You cannot write to this case.']],
			'provider answers without status' => ['params' => $full, 'answer' => ['id' => 'cm-2']],
			'provider answers a text status'  => ['params' => $full, 'answer' => ['status' => '404', 'error' => 'gone']],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh controller.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->trace = [];
		$params = ($scenario['params'] ?? []);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (isset($params[$key]) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);

		$provider = $this->createMock(ContactMomentLeafProvider::class);
		$provider->method('create')->willReturnCallback(
			function (string $hostId, array $payload) use ($scenario): array {
				$this->trace[] = ['create' => ['hostId' => $hostId, 'payload' => $payload]];
				return ($scenario['answer'] ?? ['status' => 201, 'id' => 'cm-1', 'title' => ($payload['title'] ?? null)]);
			}
		);

		$controller = new ContactMomentLeafController(
			request: $request,
			provider: $provider,
			filingService: $this->createMock(ContactMomentFilingService::class),
		);

		try {
			$response = NextcloudDispatch::call(controller: $controller, method: 'create', params: $params);
			$result = ['class' => get_class($response), 'status' => $response->getStatus()];
			if ($response instanceof JSONResponse) {
				$result['data'] = $response->getData();
			}
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $result + ['trace' => $this->trace];
	}//end runScenario()
}//end class
