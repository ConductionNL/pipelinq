<?php

/**
 * Unit tests for SeedPartyKinds.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Repair\SeedPartyKinds;
use OCA\Pipelinq\Service\PartyKindRegistryService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the party kind seed.
 */
class SeedPartyKindsTest extends TestCase {
	/**
	 * The kinds already declared.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $declared = [];

	/**
	 * Every object written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Build the step over doubles.
	 *
	 * @return SeedPartyKinds The step under test.
	 */
	private function step(): SeedPartyKinds {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = ['register' => 'reg-1', 'partyKind_schema' => 'sch-kind'];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (): array {
				return $this->declared;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;
				$this->declared[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);

		$registry = new PartyKindRegistryService(
			$appConfig,
			$objectService,
			$this->createMock(LoggerInterface::class),
		);

		return new SeedPartyKinds(
			$appConfig,
			$objectService,
			$registry,
			$this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * Running the step twice seeds once.
	 *
	 * @return void
	 */
	public function testRunningTwiceSeedsOnce(): void {
		$step = $this->step();
		$output = $this->createMock(IOutput::class);

		$step->run($output);
		$first = count($this->written);
		$step->run($output);

		$this->assertGreaterThan(0, $first, 'The first run seeds the kinds.');
		$this->assertCount($first, $this->written, 'The second run seeds nothing.');
	}//end testRunningTwiceSeedsOnce()

	/**
	 * A kind an administrator has edited is left alone.
	 *
	 * @return void
	 */
	public function testAnEditedKindIsLeftAlone(): void {
		$this->declared = [
			['code' => 'aanvrager', 'label' => 'Verzoeker', 'maxPerRecord' => 0, 'active' => false],
		];

		$missing = $this->step()->missingKinds(existingCodes: ['aanvrager']);

		$this->assertNotContains('aanvrager', array_column($missing, 'code'));
	}//end testAnEditedKindIsLeftAlone()

	/**
	 * A kind that needs no account is expressible.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/party-kinds-accepted-per-case-type/specs/party-kind-registry/spec.md#requirement-the-account-less-party-shall-be-declarable-here-and-built-elsewhere-req-pkr-006
	 */
	public function testAKindThatNeedsNoAccountIsSeeded(): void {
		$seeded = $this->step()->missingKinds(existingCodes: []);

		$melder = null;
		foreach ($seeded as $kind) {
			if ($kind['code'] === 'melder') {
				$melder = $kind;
			}
		}

		$this->assertNotNull($melder);
		$this->assertContains('none', $melder['identityShapes']);
	}//end testAKindThatNeedsNoAccountIsSeeded()

	/**
	 * The single kinds are seeded as single, and the plural ones as plural.
	 *
	 * @return void
	 */
	public function testCardinalityIsSeededWithTheKind(): void {
		$byCode = [];
		foreach ($this->step()->missingKinds(existingCodes: []) as $kind) {
			$byCode[$kind['code']] = $kind;
		}

		$this->assertSame(1, $byCode['aanvrager']['maxPerRecord']);
		$this->assertSame(0, $byCode['belanghebbende']['maxPerRecord']);
	}//end testCardinalityIsSeededWithTheKind()
}//end class
