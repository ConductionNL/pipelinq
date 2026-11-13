<?php

/**
 * Contract of JourneyService
 *
 * The public surface of JourneyService that other components call, so they depend
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
 * Contract of JourneyService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface JourneyServiceInterface {

	/**
	 * Compile one journey into a published flow.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 * @param string $runAs The identity a scheduled journey runs as.
	 *
	 * @return array<string, mixed>|null The journey with its flow status, or null when unknown.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function compile(string $journeyId, string $runAs = ''): ?array;

	/**
	 * One journey by id or slug.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 *
	 * @return array<string, mixed>|null The journey, or null.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function find(string $journeyId): ?array;

	/**
	 * Every journey.
	 *
	 * @return array<int, array<string, mixed>> The journeys.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function listJourneys(): array;

	/**
	 * Every recorded run of one journey.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> The runs.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function runsFor(string $journeyId): array;

	/**
	 * Write a journey, then compile it.
	 *
	 * @param array<string, mixed> $payload The journey fields.
	 * @param string $createdByUid The author.
	 * @param string|null $journeyId The id when updating, null when creating.
	 *
	 * @return array<string, mixed>|null The stored journey, or null when the write failed.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function save(array $payload, string $createdByUid, ?string $journeyId = null): ?array;

	/**
	 * Pause or resume a journey and compile it again, so its flow is
	 * switched off while paused and back on when resumed.
	 *
	 * @param string $journeyId Journey UUID or slug.
	 * @param string $status `active` or `paused`.
	 * @param string $runAs The identity a scheduled journey runs as.
	 *
	 * @return array<string, mixed>|null The journey with its flow status, or null when unknown.
	 *
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function setStatus(string $journeyId, string $status, string $runAs = ''): ?array;
}//end interface
