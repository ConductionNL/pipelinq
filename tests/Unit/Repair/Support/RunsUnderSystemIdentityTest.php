<?php

/**
 * Unit tests for the RunsUnderSystemIdentity trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair\Support
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

namespace OCA\Pipelinq\Tests\Unit\Repair\Support;

use OCA\Pipelinq\Repair\Support\RunsUnderSystemIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Repair work runs inside the ObjectService's system identity when it offers
 * one, and runs directly (exactly once) when it does not.
 *
 * @covers \OCA\Pipelinq\Repair\Support\RunsUnderSystemIdentity
 */
final class RunsUnderSystemIdentityTest extends TestCase {

	/**
	 * A host class exposing the trait's private helper.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class {
			use RunsUnderSystemIdentity;

			/**
			 * Run the work through the trait.
			 *
			 * @param object|null $objectService The ObjectService, if any.
			 * @param callable $work The work.
			 *
			 * @return void
			 */
			public function run(?object $objectService, callable $work): void {
				$this->withSystemIdentity(objectService: $objectService, work: $work);
			}//end run()
		};

	}//end host()

	/**
	 * With runAsSystem available the work runs inside it.
	 *
	 * @return void
	 */
	public function testWorkRunsInsideRunAsSystemWhenOffered(): void {
		$service = new class {
			/**
			 * Whether the work ran inside the system identity.
			 *
			 * @var boolean
			 */
			public bool $inside = false;

			/**
			 * Calls made.
			 *
			 * @var integer
			 */
			public int $calls = 0;

			/**
			 * Run the callback as the system.
			 *
			 * @param callable $callback The work.
			 *
			 * @return void
			 */
			public function runAsSystem(callable $callback): void {
				$this->calls++;
				$this->inside = true;
				$callback();
				$this->inside = false;
			}//end runAsSystem()
		};

		$ranInside = null;
		$this->host()->run(
			$service,
			static function () use ($service, &$ranInside): void {
				$ranInside = $service->inside;
			}
		);

		$this->assertSame(1, $service->calls);
		$this->assertTrue($ranInside);

	}//end testWorkRunsInsideRunAsSystemWhenOffered()

	/**
	 * Without an ObjectService, or one without runAsSystem, the work runs
	 * directly, once.
	 *
	 * @return void
	 */
	public function testWorkRunsDirectlyWithoutRunAsSystem(): void {
		$runs = 0;
		$work = static function () use (&$runs): void {
			$runs++;
		};

		$this->host()->run(null, $work);
		$this->host()->run(new \stdClass(), $work);

		$this->assertSame(2, $runs);

	}//end testWorkRunsDirectlyWithoutRunAsSystem()
}//end class
