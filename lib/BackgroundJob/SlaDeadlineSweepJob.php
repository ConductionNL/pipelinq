<?php

/**
 * Pipelinq SlaDeadlineSweepJob.
 *
 * Scheduled sweep job: catches deadline crossings that did not
 * coincide with an object update event. Re-evaluates target statuses
 * and fires escalations idempotently.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 * @spec openspec/specs/klachtenregistratie/spec.md#Background-Job-for-SLA-Monitoring
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Service\Sla\SlaEntitySweeper;
use OCA\Pipelinq\Service\Sla\SlaSweepRun;
use OCA\Pipelinq\Service\Sla\SlaSweepSource;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sweep job: every N seconds (default 300s, configurable 60-1800s),
 * walk all in-flight tracked objects with slaStatus set, re-evaluate
 * their targets, and fire any escalation chains whose thresholds are
 * newly crossed.
 *
 * Idempotent: per-object `currentEscalationLevel` prevents duplicate
 * firings across runs.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */
class SlaDeadlineSweepJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory     $time    Time factory.
	 * @param SlaSweepSource   $source  Register, interval, surfaces and store.
	 * @param SlaEntitySweeper $sweeper Walks the surfaces and escalates.
	 * @param LoggerInterface  $logger  PSR logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private SlaSweepSource $source,
		private SlaEntitySweeper $sweeper,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: $this->source->interval());
		$this->setTimeSensitivity(sensitivity: self::TIME_SENSITIVE);
	}//end __construct()

	/**
	 * Run the sweep.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	protected function run($argument): void {
		unset($argument);

		$startTime = microtime(true);
		$run       = $this->startRun();
		if ($run === null) {
			return;
		}

		$totals = $this->sweeper->sweepAll(surfaces: $this->source->surfaces(run: $run));
		$this->logger->info(
			'SlaDeadlineSweepJob: completed',
			['processed' => $totals['processed'], 'escalated' => $totals['escalated'], 'elapsedSeconds' => round((microtime(true) - $startTime), 3)]
		);
	}//end run()

	/**
	 * Read what the run needs; null (after logging why) when it cannot start.
	 *
	 * @return SlaSweepRun|null
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	private function startRun(): ?SlaSweepRun {
		$register = $this->register();
		if ($register === null) {
			return null;
		}

		$objectService = $this->source->objectService();
		if ($objectService === null) {
			return null;
		}

		return $this->runWith(register: $register, objectService: $objectService);
	}//end startRun()

	/**
	 * The run over a register and store, or null when the policies cannot be read.
	 *
	 * @param string $register      Register UUID.
	 * @param object $objectService OpenRegister ObjectService.
	 *
	 * @return SlaSweepRun|null
	 */
	private function runWith(string $register, object $objectService): ?SlaSweepRun {
		$policies = $this->policies();
		if ($policies === null) {
			return null;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		return new SlaSweepRun(register: $register, policies: $policies, now: $now, objectService: $objectService);
	}//end runWith()

	/**
	 * The configured register, or null (logged) when it is not configured.
	 *
	 * @return string|null
	 */
	private function register(): ?string {
		$register = $this->source->register();
		if ($register === '') {
			$this->logger->debug('SlaDeadlineSweepJob: register not configured, skipping');
			return null;
		}

		return $register;
	}//end register()

	/**
	 * The active policies by identity, or null (logged) when they cannot be read.
	 *
	 * @return array<string, array<string, mixed>>|null
	 */
	private function policies(): ?array {
		try {
			return $this->sweeper->indexPolicies();
		} catch (Throwable $e) {
			$this->logger->error(
				'SlaDeadlineSweepJob: failed to index policies',
				['error' => $e->getMessage()]
			);
			return null;
		}
	}//end policies()
}//end class
