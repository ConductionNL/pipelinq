<?php

/**
 * Behavioural tests for the ConfiguredMailTransportSchemaSlug trait.
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

use OCA\Pipelinq\Service\Support\ConfiguredMailTransportSchemaSlug;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A configured slug wins; an empty value falls back to the host's default.
 *
 * @covers \OCA\Pipelinq\Service\Support\ConfiguredMailTransportSchemaSlug
 */
class ConfiguredMailTransportSchemaSlugTest extends TestCase {

	/**
	 * Build a host whose app config answers the given value.
	 *
	 * @param string $value The configured value.
	 *
	 * @return MailTransportLookupHost The host.
	 */
	private function host(string $value, ?LoggerInterface $logger = null): MailTransportLookupHost {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($app === 'pipelinq' ? $value : 'wrong-app')
		);

		return new MailTransportLookupHost($appConfig, $logger ?? $this->createStub(LoggerInterface::class));
	}//end host()

	/**
	 * @return void
	 */
	public function testConfiguredValueWins(): void {
		$this->assertSame('custom', $this->host(value: 'custom')->read());
	}//end testConfiguredValueWins()

	/**
	 * @return void
	 */
	public function testEmptyValueFallsBackToDefault(): void {
		$this->assertSame('default-transport', $this->host(value: '')->read());
	}//end testEmptyValueFallsBackToDefault()

	/**
	 * @return void
	 */
	public function testFindsOneTransport(): void {
		$store = new class {
			/**
			 * @param string $id       Id.
			 * @param string $register Register.
			 * @param string $schema   Schema.
			 * @return array<string, string>
			 */
			public function find(string $id, string $register, string $schema): array {
				if ($id === 'gone') {
					throw new RuntimeException('missing');
				}

				return ['id' => $id, 'register' => $register, 'schema' => $schema];
			}
		};

		$this->assertSame(['id' => 't1', 'register' => 'reg', 'schema' => 'custom'], $this->host(value: 'custom')->find($store, 't1'));
		$this->assertNull($this->host(value: 'custom')->find(null, 't1'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->willReturnCallback(
			function (string $message, array $context): void {
				$this->assertSame('MailTransportLookupHost.loadOne: not found', $message);
				$this->assertSame(['id' => 'gone', 'exception' => 'missing'], $context);
			}
		);
		$this->assertNull($this->host(value: '', logger: $logger)->find($store, 'gone'));
	}//end testFindsOneTransport()
}//end class

/**
 * A host exposing the trait's reads.
 */
final class MailTransportLookupHost {
	use ConfiguredMailTransportSchemaSlug;

	private const DEFAULT_MAIL_TRANSPORT_SCHEMA_SLUG = 'default-transport';

	/**
	 * @param IAppConfig $appConfig The app config.
	 */
	public function __construct(private IAppConfig $appConfig, private LoggerInterface $logger) {
	}

	/**
	 * @return string
	 */
	private function getRegisterSlug(): string {
		return 'reg';
	}

	/**
	 * @param object|null $objectService The object service.
	 * @param string      $id            The id.
	 * @return mixed
	 */
	public function find(?object $objectService, string $id): mixed {
		return $this->findMailTransport(objectService: $objectService, id: $id);
	}

	/**
	 * @return string
	 */
	public function read(): string {
		return $this->getMailTransportSchemaSlug();
	}
}//end class
