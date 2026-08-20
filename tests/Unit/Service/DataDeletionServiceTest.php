<?php

/**
 * Unit tests for DataDeletionService (AVG erasure of customer Bookings).
 *
 * These tests assert the NEW, authorized behaviour: booking erasure is routed
 * through OpenRegister's canonical, legal-hold-aware erasure
 * (`DataSubjectRequestService::erase` in `pseudonymise` mode, resolved lazily
 * from the DI container by {@see \OCA\Pipelinq\Service\DataDeletionService})
 * instead of the earlier named-field SHA-256 hashing. The critical retention
 * invariant — a Booking row held by the NL Boekhoudplicht 7-year retention
 * SURVIVES erasure — is verified here: held objects come back in the `held`
 * bucket and are never erased. The OR-absent safe path (container cannot resolve
 * the OR service) is also verified: it degrades to an empty summary.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/pipelinq-avg-adopt-or-gdpr/design.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DataDeletionService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Minimal fake of OR's GDPR capability for the DataDeletionService tests.
 *
 * Models legal-hold-aware field-level pseudonymise erasure: every matched object
 * is erased (row retained) unless its uuid is held, in which case it is reported
 * back in the `held` bucket and never mutated.
 */
class FakeBookingErase {

	/**
	 * Booking object uuids the erase will discover for the subject.
	 *
	 * @var array<int, string>
	 */
	public array $matchedUuids = [];

	/**
	 * Uuids that are held (legal hold / immutable) — reported, never erased.
	 *
	 * @var array<int, string>
	 */
	public array $heldUuids = [];

	/**
	 * Captured erase call arguments.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $lastErase = null;

	/**
	 * Legal-hold-aware field-level pseudonymise erasure (row retained).
	 *
	 * @param string $subjectId The subject identifier value.
	 * @param string|null $type Optional type filter.
	 * @param string $eraseMode The erase mode.
	 * @param bool $dryRun Whether this is a dry run.
	 *
	 * @return array<string, mixed> OR's erase summary.
	 */
	public function erase(string $subjectId, ?string $type = null, string $eraseMode = 'pseudonymise', bool $dryRun = false): array {
		$this->lastErase = ['subjectId' => $subjectId, 'type' => $type, 'eraseMode' => $eraseMode, 'dryRun' => $dryRun];

		$erased = [];
		$held = [];
		foreach ($this->matchedUuids as $uuid) {
			if (in_array($uuid, $this->heldUuids, true) === true) {
				$held[] = ['uuid' => $uuid, 'reason' => 'legal-hold'];
				continue;
			}

			$erased[] = ['uuid' => $uuid];
		}

		return [
			'subject' => $subjectId,
			'eraseMode' => $eraseMode,
			'dryRun' => $dryRun,
			'matchedCount' => count($this->matchedUuids),
			'erased' => $erased,
			'held' => $held,
			'failed' => [],
			'complete' => true,
		];
	}//end erase()
}//end class

/**
 * Tests for DataDeletionService.
 */
class DataDeletionServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var DataDeletionService
	 */
	private DataDeletionService $service;

	/**
	 * The fake OR erase backing the service.
	 *
	 * @var FakeBookingErase
	 */
	private FakeBookingErase $erase;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->erase = new FakeBookingErase();

		$container = self::containerResolving($this->erase);
		$this->service = new DataDeletionService(container: $container, logger: new NullLogger());
	}//end setUp()

	/**
	 * Build a DI container that resolves OR's request service to $fake, or, when
	 * $fake is null, models OR-absent by throwing for every id.
	 *
	 * @param FakeBookingErase|null $fake The fake OR erase, or null for OR-absent.
	 *
	 * @return ContainerInterface The container.
	 */
	private static function containerResolving(?FakeBookingErase $fake): ContainerInterface {
		return new class($fake) implements ContainerInterface {
			/**
			 * @param FakeBookingErase|null $fake The fake erase, or null for OR-absent.
			 */
			public function __construct(
				private ?FakeBookingErase $fake,
			) {
			}

			/**
			 * @param string $id The service id.
			 *
			 * @return mixed The service.
			 */
			public function get(string $id): mixed {
				if ($this->fake !== null && $id === DataDeletionService::OR_REQUEST_SERVICE) {
					return $this->fake;
				}

				throw new \RuntimeException('not found: ' . $id);
			}

			/**
			 * @param string $id The service id.
			 *
			 * @return bool Whether the service exists.
			 */
			public function has(string $id): bool {
				return ($this->fake !== null && $id === DataDeletionService::OR_REQUEST_SERVICE);
			}
		};
	}//end containerResolving()

	/**
	 * Returns the empty summary when the customer id is blank (no erase call).
	 *
	 * @return void
	 */
	public function testRejectsEmptyCustomerId(): void {
		$summary = $this->service->pseudonymizeCustomerBookings('');

		$this->assertSame(['bookings' => 0, 'held' => 0], $summary);
		$this->assertNull($this->erase->lastErase, 'No erase should occur for an empty id.');
	}//end testRejectsEmptyCustomerId()

	/**
	 * Delegates to OR's erase in pseudonymise mode with the customer as subject.
	 *
	 * @return void
	 */
	public function testDelegatesToOrEraseInPseudonymiseMode(): void {
		$this->erase->matchedUuids = ['b1', 'b2'];

		$summary = $this->service->pseudonymizeCustomerBookings('cust-7');

		$this->assertSame(2, $summary['bookings']);
		$this->assertSame(0, $summary['held']);
		$this->assertNotNull($this->erase->lastErase);
		$this->assertSame('cust-7', $this->erase->lastErase['subjectId']);
		$this->assertSame('pseudonymise', $this->erase->lastErase['eraseMode']);
		$this->assertFalse($this->erase->lastErase['dryRun']);
	}//end testDelegatesToOrEraseInPseudonymiseMode()

	/**
	 * CRITICAL retention invariant: a Booking held by the Boekhoudplicht 7-year
	 * retention (legal hold / immutable) SURVIVES erasure — it is reported in the
	 * `held` bucket and never erased. The row is retained; only unheld objects'
	 * PII is removed. This is the legal floor that must hold.
	 *
	 * @return void
	 */
	public function testHeldBookingRowSurvivesErasure(): void {
		$this->erase->matchedUuids = ['b1', 'b2-held'];
		$this->erase->heldUuids = ['b2-held'];

		$summary = $this->service->pseudonymizeCustomerBookings('cust-7');

		// The unheld booking is erased; the held (Boekhoudplicht) booking is kept.
		$this->assertSame(1, $summary['bookings'], 'Only the unheld booking is erased.');
		$this->assertSame(1, $summary['held'], 'The held Boekhoudplicht booking survives.');
	}//end testHeldBookingRowSurvivesErasure()

	/**
	 * A dry run reports matches/holds without mutating (dryRun passed through).
	 *
	 * @return void
	 */
	public function testDryRunPassesThroughWithoutMutating(): void {
		$this->erase->matchedUuids = ['b1'];

		$summary = $this->service->pseudonymizeCustomerBookings('cust-7', dryRun: true);

		$this->assertSame(1, $summary['bookings']);
		$this->assertNotNull($this->erase->lastErase);
		$this->assertTrue($this->erase->lastErase['dryRun']);
	}//end testDryRunPassesThroughWithoutMutating()

	/**
	 * OR-absent safe path: when the container cannot resolve OR's request
	 * service, erasure degrades to an empty summary instead of throwing.
	 *
	 * @return void
	 */
	public function testOrAbsentDegradesToEmptySummary(): void {
		$service = new DataDeletionService(
			container: self::containerResolving(null),
			logger: new NullLogger()
		);

		$summary = $service->pseudonymizeCustomerBookings('cust-7');

		$this->assertSame(['bookings' => 0, 'held' => 0], $summary);
	}//end testOrAbsentDegradesToEmptySummary()
}//end class
