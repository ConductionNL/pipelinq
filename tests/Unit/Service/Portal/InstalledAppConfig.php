<?php

/**
 * App config holding only what the install writes.
 *
 * Wires an IAppConfig double as a key/value store and fills it by running the
 * REAL register-import writer (`SettingsLoadService::applySchemaConfig()`)
 * over every schema slug the install knows, plus the two register ids. A
 * portal reader built over this config sees exactly the keys a live instance
 * has, so a reader that asks for a key the install never writes reads empty
 * here too. The fakes it replaced answered any key, which is how pipelinq#2037
 * (no resident could log in) shipped with a green suite.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
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

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use ArrayObject;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Builds an app config double that holds only the install's own keys.
 */
final class InstalledAppConfig {
	/**
	 * Main register id the double reports.
	 *
	 * @var string
	 */
	public const MAIN_REGISTER = 'reg-main';

	/**
	 * Portal register id the double reports.
	 *
	 * @var string
	 */
	public const PORTAL_REGISTER = 'reg-portal';

	/**
	 * The schema id the double stores for a slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return string The schema id.
	 */
	public static function schemaId(string $slug): string {
		return 'schema-' . $slug;
	}//end schemaId()

	/**
	 * Wire a mocked IAppConfig as a store and fill it through the real writer.
	 *
	 * @param IAppConfig&MockObject $config The IAppConfig mock to wire.
	 *
	 * @return IAppConfig The same config, now holding the installed keys.
	 */
	public static function wire(IAppConfig&MockObject $config): IAppConfig {
		$store = new ArrayObject();
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($store): bool {
				$store[$key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($store[$key] ?? $default)
		);

		$loader = new ReflectionClass(SettingsLoadService::class);
		$writer = $loader->newInstanceWithoutConstructor();
		$loader->getProperty('appConfig')->setValue($writer, $config);

		$schemaMap = [];
		foreach ((array)$loader->getConstant('SCHEMA_SLUGS') as $slug) {
			$schemaMap[$slug] = self::schemaId(slug: (string)$slug);
		}

		$loader->getMethod('applySchemaConfig')->invoke($writer, $schemaMap);

		// The register ids are written by the same import, outside applySchemaConfig.
		$store['register'] = self::MAIN_REGISTER;
		$store['portal_register'] = self::PORTAL_REGISTER;

		return $config;
	}//end wire()
}//end class
