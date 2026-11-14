<?php

/**
 * Unit tests for PosCustomerSettingsController.
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosCustomerSettingsController;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The till's purchase history depth, as board PqKassaKlantzoeken draws it
 * ("Laatste 5 transacties").
 */
class PosCustomerSettingsControllerTest extends TestCase {
	/**
	 * Build the controller over a request carrying the given params.
	 *
	 * @param array<string, mixed> $params The request params.
	 * @param array<string, string> $stored The stored app config, updated by writes.
	 *
	 * @return PosCustomerSettingsController
	 */
	private function controller(array $params, array &$stored): PosCustomerSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			}
		);

		return new PosCustomerSettingsController(
			request: $request,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The last five transactions is a depth the till can show.
	 *
	 * @return void
	 */
	public function testAcceptsTheLastFiveTransactions(): void {
		$stored   = [];
		$response = $this->controller(params: ['customerHistoryDepth' => 5], stored: $stored)->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(5, $response->getData()['settings']['customerHistoryDepth']);
	}//end testAcceptsTheLastFiveTransactions()

	/**
	 * A depth outside the list is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnOtherDepth(): void {
		$stored   = [];
		$response = $this->controller(params: ['customerHistoryDepth' => 7], stored: $stored)->update();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertArrayNotHasKey('customerHistoryDepth', $stored);
	}//end testRefusesAnOtherDepth()
}//end class
