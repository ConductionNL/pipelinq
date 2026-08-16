<?php

/**
 * Unit tests for the `register` slug fallback when the app-config key is
 * PRESENT but BLANK.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Passing the built-in slug as getValueString()'s default only covers an
 * ABSENT key. A key that is present but set to an empty string returns ''
 * verbatim, and an empty register is not the same as "no register" to
 * OpenRegister: ObjectService skips setRegister() on '' and the query would
 * inherit the request's leftover context.
 *
 * These tests distinguish the two cases the old default-argument form
 * conflated — key missing, and key present-but-blank.
 */
class RegisterSlugBlankOverrideTest extends TestCase {
	/**
	 * Build MessagingService over a config map.
	 *
	 * @param array<string, string> $config The app-config contents.
	 * @param ObjectServiceInterface $object The ObjectService mock.
	 *
	 * @return MessagingService
	 */
	private function buildService(array $config, ObjectServiceInterface $object): MessagingService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($object);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				// array_key_exists, NOT ??: a key set to '' must return '',
				// which is exactly the case under test.
				if (array_key_exists($key, $config) === true) {
					return $config[$key];
				}

				return $default;
			}
		);

		return new MessagingService($container,
			$appConfig,
			$this->createMock(ChannelProviderRepository::class),
			$this->createMock(SmsAdapter::class),
			$this->createMock(WhatsAppAdapter::class),
			$this->createMock(ConsentService::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end buildService()

	/**
	 * An ObjectService double that records the register every find() is scoped to.
	 *
	 * The register is read from the NAMED parameter, not from a positional
	 * index. `ObjectServiceInterface::find()` declares nine parameters and the
	 * register is the FOURTH — these tests used to read `$args[1]`, which under
	 * the current contract is `$_extend` and always came back as `[]`, so all
	 * three assertions compared a register slug against an empty array.
	 *
	 * @param array<int, mixed> $seen Collects the register of every call.
	 *
	 * @return ObjectServiceInterface The recording double.
	 */
	private function registerRecordingObjectService(array &$seen): ObjectServiceInterface {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			static function (
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
			) use (&$seen): ?ObjectEntityInterface {
				$seen[] = $register;
				return null;
			}
		);

		return $object;
	}//end registerRecordingObjectService()

	/**
	 * A BLANK `register` override still resolves to the built-in slug, so the
	 * lookup proceeds scoped instead of being abandoned.
	 *
	 * Before the fix this returned '' and loadContact() bailed out, because
	 * getValueString()'s default never applies to a present-but-empty key.
	 *
	 * @return void
	 */
	public function testBlankRegisterOverrideFallsBackToBuiltInSlug(): void {
		$seen = [];

		$object = $this->registerRecordingObjectService($seen);

		$service = $this->buildService(['register' => ''], $object);

		$service->loadContact('contact-1');

		$this->assertNotEmpty($seen, 'A blank register override must not abandon the lookup.');
		$this->assertSame('pipelinq', $seen[0], 'A blank override must resolve to the built-in slug.');
	}//end testBlankRegisterOverrideFallsBackToBuiltInSlug()

	/**
	 * An ABSENT `register` key resolves to the built-in slug too.
	 *
	 * @return void
	 */
	public function testAbsentRegisterKeyFallsBackToBuiltInSlug(): void {
		$seen = [];

		$object = $this->registerRecordingObjectService($seen);

		$service = $this->buildService([], $object);

		$service->loadContact('contact-1');

		$this->assertNotEmpty($seen);
		$this->assertSame('pipelinq', $seen[0]);
	}//end testAbsentRegisterKeyFallsBackToBuiltInSlug()

	/**
	 * An explicit override is still honoured — the fallback must not swallow
	 * a real configuration.
	 *
	 * @return void
	 */
	public function testExplicitRegisterOverrideIsHonoured(): void {
		$seen = [];

		$object = $this->registerRecordingObjectService($seen);

		$service = $this->buildService(['register' => 'custom-reg'], $object);

		$service->loadContact('contact-1');

		$this->assertNotEmpty($seen);
		$this->assertSame('custom-reg', $seen[0]);
	}//end testExplicitRegisterOverrideIsHonoured()
}//end class
