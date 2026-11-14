<?php

/**
 * Where scheduled tasks are stored: the configured register and task schema
 * (a missing one is logged, never silently replaced), the OpenRegister object
 * service, and the read language taken from a request's Accept-Language.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\ScheduledTask
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\ScheduledTask;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\LanguageService;
use OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskStore;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\ScheduledTask\ScheduledTaskStore
 */
class ScheduledTaskStoreTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private ObjectServiceInterface&MockObject $objects;

	private LanguageService&MockObject $language;

	private LoggerInterface&MockObject $logger;

	private ScheduledTaskStore $store;

	/**
	 * A store over mocked config, object service, language service and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->objects   = $this->createMock(ObjectServiceInterface::class);
		$this->language  = $this->createMock(LanguageService::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->store     = new ScheduledTaskStore($this->appConfig, $this->objects, $this->language, $this->logger);

	}//end setUp()

	/**
	 * Configure the register and task schema ids.
	 *
	 * @param string $register The register id.
	 * @param string $schema   The task schema id.
	 *
	 * @return void
	 */
	private function configure(string $register, string $schema): void {
		$this->appConfig->expects($this->exactly(2))->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default) use ($register, $schema): string {
				self::assertSame('pipelinq', $app);
				self::assertSame('', $default);

				return match ($key) {
					'register' => $register,
					'task_schema' => $schema,
				};
			}
		);

	}//end configure()

	/**
	 * A configured register and schema come back as the pair, without a warning.
	 *
	 * @return void
	 */
	public function testTheConfiguredRegisterAndSchemaAreTheLocation(): void {
		$this->configure(register: '7', schema: '12');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(['7', '12'], $this->store->location());

	}//end testTheConfiguredRegisterAndSchemaAreTheLocation()

	/**
	 * A missing register is returned empty and logged.
	 *
	 * @return void
	 */
	public function testAMissingRegisterIsEmptyAndLogged(): void {
		$this->configure(register: '', schema: '12');
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq: register/task_schema not configured; OpenRegister calls are refused, not run unscoped');

		$this->assertSame(['', '12'], $this->store->location());

	}//end testAMissingRegisterIsEmptyAndLogged()

	/**
	 * A missing task schema is returned empty and logged.
	 *
	 * @return void
	 */
	public function testAMissingSchemaIsEmptyAndLogged(): void {
		$this->configure(register: '7', schema: '');
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(['7', ''], $this->store->location());

	}//end testAMissingSchemaIsEmptyAndLogged()

	/**
	 * The object service handed out is the one injected.
	 *
	 * @return void
	 */
	public function testTheObjectServiceIsTheInjectedOne(): void {
		$this->assertSame($this->objects, $this->store->objects());

	}//end testTheObjectServiceIsTheInjectedOne()

	/**
	 * An empty header leaves the language untouched.
	 *
	 * @return void
	 */
	public function testAnEmptyHeaderSetsNoLanguage(): void {
		$this->language->expects($this->never())->method('setPreferredLanguage');

		$this->store->applyAcceptLanguage('');

	}//end testAnEmptyHeaderSetsNoLanguage()

	/**
	 * Headers and the language each one sets.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function headers(): array {
		return [
			'a weighted list takes the first tag' => ['nl-NL,nl;q=0.9,en;q=0.8', 'nl-NL'],
			'a single weighted tag loses its weight' => ['en;q=0.8', 'en'],
			'surrounding spaces are trimmed' => ['  de-DE , en', 'de-DE'],
			'a blank first tag falls back to Dutch' => [' ,en', 'nl'],
			'a weight without a tag falls back to Dutch' => [';q=0.5', 'nl'],
		];

	}//end headers()

	/**
	 * The highest-priority tag is set as the preferred language.
	 *
	 * @param string $header   The Accept-Language header.
	 * @param string $expected The language set.
	 *
	 * @return void
	 *
	 * @dataProvider headers
	 */
	public function testTheFirstTagBecomesThePreferredLanguage(string $header, string $expected): void {
		$this->language->expects($this->once())->method('setPreferredLanguage')->with($expected);

		$this->store->applyAcceptLanguage($header);

	}//end testTheFirstTagBecomesThePreferredLanguage()

	/**
	 * A failing language service is logged at debug level and does not escape.
	 *
	 * @return void
	 */
	public function testAFailingLanguageServiceIsLoggedAndSwallowed(): void {
		$this->language->method('setPreferredLanguage')->willThrowException(new RuntimeException('not shipped'));
		$this->logger->expects($this->once())->method('debug')->with(
			'applyAcceptLanguage: OR LanguageService unavailable.',
			['exception' => 'not shipped']
		);

		$this->store->applyAcceptLanguage('nl');

	}//end testAFailingLanguageServiceIsLoggedAndSwallowed()
}//end class
