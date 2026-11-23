<?php

/**
 * PosCustomerSettingsController: reading the POS customer settings with
 * their defaults, and what an update accepts, stores and refuses.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosCustomerSettingsController;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Controller\PosCustomerSettingsController
 */
class PosCustomerSettingsControllerCoverageTest extends TestCase {

	/**
	 * The stored app config values.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller over an in-memory app config, with the request parameters given.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $broken Whether the app config throws on write.
	 *
	 * @return PosCustomerSettingsController
	 */
	private function controller(array $params = [], bool $broken = false): PosCustomerSettingsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use ($broken): bool {
				if ($broken === true) {
					throw new RuntimeException('config table locked');
				}

				$this->stored[$key] = $value;
				return true;
			}
		);

		return new PosCustomerSettingsController($request, $config, $this->logger);
	}//end controller()

	/**
	 * Nothing stored: every default.
	 *
	 * @return void
	 */
	public function testIndexReadsTheDefaults(): void {
		$this->assertSame(
			['settings' => [
				'customerSearchFields' => ['name', 'email', 'phone'],
				'customerHistoryDepth' => 10,
				'enablePipelinqSync' => true,
				'requireCustomerForOnAccount' => true,
			]],
			$this->controller()->index()->getData()
		);
	}//end testIndexReadsTheDefaults()

	/**
	 * Stored values are read back; unknown search fields are dropped, and none left means all.
	 *
	 * @return void
	 */
	public function testIndexReadsStoredValuesAndDropsUnknownFields(): void {
		$this->stored = ['customerSearchFields' => ' phone , iban', 'customerHistoryDepth' => '50', 'enablePipelinqSync' => 'FALSE'];
		$settings = $this->controller()->index()->getData()['settings'];
		$this->assertSame(['phone'], $settings['customerSearchFields']);
		$this->assertSame(50, $settings['customerHistoryDepth']);
		$this->assertFalse($settings['enablePipelinqSync']);
		$this->assertTrue($settings['requireCustomerForOnAccount']);

		$this->stored = ['customerSearchFields' => 'iban'];
		$this->assertSame(['name', 'email', 'phone'], $this->controller()->index()->getData()['settings']['customerSearchFields']);
	}//end testIndexReadsStoredValuesAndDropsUnknownFields()

	/**
	 * Search fields come as a comma list and are stored trimmed.
	 *
	 * @return void
	 */
	public function testUpdateStoresTheSearchFields(): void {
		$response = $this->controller(['customerSearchFields' => 'email, phone'])->update();

		$this->assertSame('email,phone', $this->stored['customerSearchFields']);
		$this->assertSame(['email', 'phone'], $response->getData()['settings']['customerSearchFields']);
	}//end testUpdateStoresTheSearchFields()

	/**
	 * An unknown field, or no field at all, is refused with 422 and nothing stored.
	 *
	 * @return void
	 */
	public function testUpdateRefusesUnknownOrNoSearchFields(): void {
		$unknown = $this->controller(['customerSearchFields' => 'name,iban'])->update();
		$this->assertSame(422, $unknown->getStatus());
		$this->assertSame(['error' => "Onbekend zoekveld: 'iban'. Toegestaan: name, email, phone."], $unknown->getData());

		$none = $this->controller(['customerSearchFields' => ' , '])->update();
		$this->assertSame(422, $none->getStatus());
		$this->assertSame(['error' => 'Ten minste één zoekveld is verplicht.'], $none->getData());
		$this->assertSame([], $this->stored);
	}//end testUpdateRefusesUnknownOrNoSearchFields()

	/**
	 * Booleans come as a bool, a word or a number, and are stored as true or false.
	 *
	 * @return void
	 */
	public function testUpdateReadsEachBooleanForm(): void {
		$cases = [[true, 'true'], [false, 'false'], ['yes', 'true'], ['On', 'true'], ['nee', 'false'], ['0', 'false'], [1, 'true'], [0, 'false']];
		foreach ($cases as [$given, $stored]) {
			$this->controller(['enablePipelinqSync' => $given, 'requireCustomerForOnAccount' => $given])->update();
			$this->assertSame($stored, $this->stored['enablePipelinqSync'], var_export($given, true));
			$this->assertSame($stored, $this->stored['requireCustomerForOnAccount'], var_export($given, true));
		}
	}//end testUpdateReadsEachBooleanForm()

	/**
	 * A parameter that is not sent is left as it is.
	 *
	 * @return void
	 */
	public function testUpdateLeavesUnsentSettingsAlone(): void {
		$this->stored = ['customerHistoryDepth' => '20'];

		$response = $this->controller(['enablePipelinqSync' => 'false'])->update();

		$this->assertSame(['customerHistoryDepth' => '20', 'enablePipelinqSync' => 'false'], $this->stored);
		$this->assertSame(20, $response->getData()['settings']['customerHistoryDepth']);
	}//end testUpdateLeavesUnsentSettingsAlone()

	/**
	 * A failing store answers 500 and is logged.
	 *
	 * @return void
	 */
	public function testAFailingStoreIs500AndLogged(): void {
		$this->logger->expects($this->once())->method('error')
			->with('PosCustomerSettingsController::update failed', ['exception' => 'config table locked']);

		$response = $this->controller(['customerHistoryDepth' => '5'], broken: true)->update();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Onverwachte fout bij opslaan van instellingen.'], $response->getData());
	}//end testAFailingStoreIs500AndLogged()
}//end class
