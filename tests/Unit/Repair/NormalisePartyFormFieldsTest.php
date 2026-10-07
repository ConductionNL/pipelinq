<?php

/**
 * One language field per party, and industry as a list.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pipelinq-forms-review/specs/client-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\NormalisePartyFormFields;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for NormalisePartyFormFields.
 *
 * @covers \OCA\Pipelinq\Repair\NormalisePartyFormFields
 */
class NormalisePartyFormFieldsTest extends TestCase {
	/**
	 * A stated language moves into correspondenceLanguage.
	 *
	 * @return void
	 */
	public function testAStatedLanguageIsCopied(): void {
		$this->assertSame(['correspondenceLanguage' => 'nl'], NormalisePartyFormFields::patchFor(['language' => 'NL']));
	}//end testAStatedLanguageIsCopied()

	/**
	 * A correspondence language already set is never overwritten.
	 *
	 * @return void
	 */
	public function testASetCorrespondenceLanguageWins(): void {
		$this->assertSame([], NormalisePartyFormFields::patchFor(['language' => 'nl', 'correspondenceLanguage' => 'en']));
	}//end testASetCorrespondenceLanguageWins()

	/**
	 * A string industry is wrapped; an empty one becomes an empty list.
	 *
	 * @return void
	 */
	public function testAnIndustryStringBecomesAList(): void {
		$this->assertSame(['industry' => ['Retail']], NormalisePartyFormFields::patchFor(['industry' => 'Retail']));
		$this->assertSame(['industry' => []], NormalisePartyFormFields::patchFor(['industry' => '']));
	}//end testAnIndustryStringBecomesAList()

	/**
	 * A list stays a list, and a clean party needs nothing.
	 *
	 * @return void
	 */
	public function testANormalisedPartyNeedsNothing(): void {
		$this->assertSame([], NormalisePartyFormFields::patchFor(['industry' => ['Retail'], 'correspondenceLanguage' => 'nl']));
	}//end testANormalisedPartyNeedsNothing()

	/**
	 * The step saves exactly the patched parties, merged over what is stored.
	 *
	 * @return void
	 */
	public function testTheRunSavesOnlyPartiesThatNeedIt(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '7', 'client_schema' => '11', 'contact_schema' => ''][$key] ?? $default
		);

		$objectService = new class {
			/**
			 * Saved payloads.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Stored rows.
			 *
			 * @param mixed ...$args Ignored.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(mixed ...$args): array {
				return [
					['id' => 'a', 'name' => 'Bakkerij', 'industry' => 'Retail'],
					['id' => 'b', 'name' => 'Gemeente', 'industry' => ['Public sector']],
				];
			}//end findAll()

			/**
			 * Record a save.
			 *
			 * @param array<string,mixed> $object The payload.
			 * @param mixed ...$rest Ignored.
			 *
			 * @return void
			 */
			public function saveObject(array $object, mixed ...$rest): void {
				$this->saved[] = $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$step = new NormalisePartyFormFields($appConfig, $container, $this->createMock(IGroupManager::class), new NullLogger());
		$step->run($this->createMock(IOutput::class));

		$this->assertSame([['id' => 'a', 'name' => 'Bakkerij', 'industry' => ['Retail']]], $objectService->saved);
	}//end testTheRunSavesOnlyPartiesThatNeedIt()
}//end class
