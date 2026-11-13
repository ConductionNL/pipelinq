<?php

/**
 * Contract of CompetitorWatchService
 *
 * The public surface of CompetitorWatchService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of CompetitorWatchService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CompetitorWatchServiceInterface {

	/**
	 * App-config key naming the OpenConnector source competitor reads leave
	 * through.
	 *
	 * @var string
	 */
	public const SOURCE_KEY = 'competitor.egress_source';

	/**
	 * Watches run in one pass, at most.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 25;

	/**
	 * Every competitor.
	 *
	 * @return array<int, array<string, mixed>> The competitors.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function competitors(): array;

	/**
	 * Run every due watch, one failure at a time.
	 *
	 * @param int $limit How many watches at most.
	 * @param string|null $actingUserId The identity the run acts as.
	 *
	 * @return array{watches: int, events: int, failures: array<string, string>}
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function runDue(int $limit = self::DEFAULT_LIMIT, ?string $actingUserId = null): array;

	/**
	 * Run one watch and record what it saw.
	 *
	 * @param array<string, mixed> $watch The watch.
	 * @param string|null $actingUserId The identity the run acts as.
	 *
	 * @return array{outcome: string, reason: string, events: int}
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
	 */
	public function runOne(array $watch, ?string $actingUserId = null): array;

	/**
	 * One watch by id.
	 *
	 * @param string $id The watch id.
	 *
	 * @return array<string, mixed>|null The watch, or null.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function watch(string $id): ?array;

	/**
	 * Every watch.
	 *
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The watches.
	 *
	 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
	 */
	public function watches(array $filters = []): array;
}//end interface
