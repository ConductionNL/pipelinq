<?php

/**
 * TicketLeafProvider: a failing read and serialisable rows.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Integration
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

namespace OCA\Pipelinq\Tests\Unit\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Integration\TicketLeafProvider;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Client ids are read through OpenRegister; a failed read is logged and reads
 * as none, and serialisable rows are read through jsonSerialize.
 *
 * @covers \OCA\Pipelinq\Integration\TicketLeafProvider
 */
class TicketLeafProviderEdgesTest extends TestCase {

	/**
	 * The provider over the given object service and logger.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 * @param LoggerInterface        $logger  The logger.
	 *
	 * @return TicketLeafProvider
	 */
	private function provider(ObjectServiceInterface $objects, LoggerInterface $logger): TicketLeafProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (['register' => 'reg', 'client_schema' => 'clients'][$key] ?? $default)
		);

		return new TicketLeafProvider($appConfig, $objects, $this->createMock(IUserManager::class), $logger);
	}//end provider()

	/**
	 * A failing read is logged with its schema and gives no clients.
	 *
	 * @return void
	 */
	public function testAFailingReadGivesNoClients(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'TicketLeafProvider: a read failed',
			['schema' => 'clients', 'exception' => 'db down']
		);

		$this->assertSame([], $this->provider($objects, $logger)->clientIdsOf(ref: 'kvk:1'));
	}//end testAFailingReadGivesNoClients()

	/**
	 * Rows are read as arrays or through jsonSerialize; anything else, and a
	 * client of another reference, is skipped.
	 *
	 * @return void
	 */
	public function testSerialisableRowsAreRead(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'reg', 'schema' => 'clients', 'masterEntityRef' => ['in' => ['kvk:1']]], 'limit' => TicketLeafProvider::MAX_ROWS])
			->willReturn(
				[
					['id' => 'c-1', 'masterEntityRef' => 'kvk:1'],
					new class implements \JsonSerializable {
						/**
						 * The serialised client.
						 *
						 * @return array<string, string>
						 */
						public function jsonSerialize(): array {
							return ['uuid' => 'c-2', 'masterEntityRef' => 'kvk:1'];
						}//end jsonSerialize()
					},
					['id' => 'c-3', 'masterEntityRef' => 'kvk:2'],
					'not a row',
				]
			);

		$this->assertSame(['c-1', 'c-2'], $this->provider($objects, $this->createMock(LoggerInterface::class))->clientIdsOf(ref: ' kvk:1 '));
	}//end testSerialisableRowsAreRead()
}//end class
