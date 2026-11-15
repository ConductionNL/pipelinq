<?php

/**
 * Behavioural tests for the ExportSourceCredentials trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\ConnectorSourceRegister;
use OCA\Pipelinq\Service\Support\ExportSourceCredentials;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Credentials come from the destination's connector source: non-empty legacy
 * secrets and a non-empty authentication block are kept.
 *
 * @covers \OCA\Pipelinq\Service\Support\ExportSourceCredentials
 */
class ExportSourceCredentialsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testCollectsSecrets(): void {
		$host = new class {
			use ExportSourceCredentials;

			private const OPENCONNECTOR_LEGACY_SECRET_FIELDS = ['password', 'apikey'];

			/**
			 * @param array<string, mixed> $source The source.
			 * @return array<string, mixed>
			 */
			public function read(array $source): array {
				return $this->extractSourceCredentials(source: $source);
			}
		};

		$this->assertSame(
			['password' => 'p', 'authentication' => ['type' => 'basic']],
			$host->read(source: ['password' => 'p', 'apikey' => '', 'other' => 'x', 'configuration' => ['authentication' => ['type' => 'basic']]])
		);
		$this->assertSame([], $host->read(source: ['apikey' => 5, 'configuration' => ['authentication' => []]]));
	}//end testCollectsSecrets()

	/**
	 * @return void
	 */
	public function testResolveCredentials(): void {
		$register = $this->createStub(ConnectorSourceRegister::class);
		$register->method('slugOrNull')->willReturnCallback(
			static fn (string $operation, string $sourceId = ''): ?string => ($sourceId === 'unregistered' ? null : 'oc-'.$operation)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('credentials failed', ['sourceId' => 'broken', 'error' => 'down']);

		$host = new ExportSourceCredentialsHost($register, $logger);

		$this->assertSame([], $host->resolve(destination: []));
		$this->assertSame([], $host->resolve(destination: ['connectorSourceId' => 'unregistered']));
		$this->assertSame([], $host->resolve(destination: ['connectorSourceId' => 'missing']));
		$this->assertSame([], $host->resolve(destination: ['connectorSourceId' => 'broken']));
		$this->assertSame(['password' => 'p'], $host->resolve(destination: ['connectorSourceId' => 'known']));
		$this->assertSame(['known', 'oc-ExportSourceCredentialsHost.resolveCredentials', 'source'], $host->lastFind);
	}//end testResolveCredentials()
}//end class

/**
 * A named host with a fake object service.
 */
final class ExportSourceCredentialsHost {
	use ExportSourceCredentials;

	private const OPENCONNECTOR_LEGACY_SECRET_FIELDS = ['password'];

	private const OPENCONNECTOR_SOURCE_SCHEMA_SLUG = 'source';

	private const CREDENTIALS_FAILURE_MESSAGE = 'credentials failed';

	/**
	 * @var array<int, string>
	 */
	public array $lastFind = [];

	/**
	 * @param ConnectorSourceRegister $connectorRegister The register resolver.
	 * @param LoggerInterface         $logger            The logger.
	 */
	public function __construct(private ConnectorSourceRegister $connectorRegister, private LoggerInterface $logger) {
	}

	/**
	 * @param array<string, mixed> $destination The destination.
	 * @return array<string, mixed>
	 */
	public function resolve(array $destination): array {
		return $this->resolveCredentials(destination: $destination);
	}

	/**
	 * @return object
	 */
	private function getObjectService(): object {
		return new class($this) {
			/**
			 * @param ExportSourceCredentialsHost $host The host.
			 */
			public function __construct(private ExportSourceCredentialsHost $host) {
			}

			/**
			 * @param string $id            Id.
			 * @param string $register      Register.
			 * @param string $schema        Schema.
			 * @param bool   $_rbac         RBAC.
			 * @param bool   $_multitenancy Multitenancy.
			 * @param bool   $_render       Render.
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, string $register, string $schema, bool $_rbac, bool $_multitenancy, bool $_render): ?array {
				if ($id === 'broken') {
					throw new \RuntimeException('down');
				}

				$this->host->lastFind = [$id, $register, $schema];
				return ($id === 'known') ? ['password' => 'p', 'apikey' => 'k'] : null;
			}
		};
	}

	/**
	 * @param mixed $object The object.
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		return (array)$object;
	}
}//end class
