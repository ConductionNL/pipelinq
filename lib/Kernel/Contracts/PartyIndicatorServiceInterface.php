<?php

/**
 * Contract of PartyIndicatorService
 *
 * The public surface of PartyIndicatorService that other components call, so they depend
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

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Contract of PartyIndicatorService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartyIndicatorServiceInterface {

	/**
	 * Record that a handler has seen an indicator that demands it.
	 *
	 * @param string $valueId The indicator value's uuid.
	 *
	 * @return array<string, mixed> `status` plus either `indicatorValue` or `error`.
	 *
	 * @spec openspec/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
	 */
	public function acknowledge(string $valueId): array;

	/**
	 * Whether an act on a party is blocked, and by which indicator.
	 *
	 * The answer names the indicator, its label and its severity, so a caller
	 * can show a person why an act was refused instead of a bare refusal.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param string $act One of the ACTS keys.
	 * @param DateTimeImmutable|null $onDay The day to judge on; today by default.
	 *
	 * @return array{blocked: bool, act: string, indicators: array<int, array<string, mixed>>}
	 *   The answer.
	 *
	 * @throws InvalidArgumentException When the act is not one this service answers.
	 *
	 * @spec openspec/specs/party-fields-and-indicators/spec.md#requirement-pipelinq-shall-answer-whether-an-indicator-blocks-an-act-and-shall-not-intercept-it-req-pfi-004
	 */
	public function isBlocked(string $partyId, string $act, ?DateTimeImmutable $onDay = null): array;

	/**
	 * Every indicator a party carries today, resolved live.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param DateTimeImmutable|null $onDay The day to resolve on; today by default.
	 *
	 * @return array<int, array<string, mixed>> The indicators, each with its
	 *   code, label, severity, effects, period, source and acknowledgement.
	 *
	 * @spec openspec/specs/party-fields-and-indicators/spec.md#requirement-a-partys-indicators-shall-resolve-live-on-every-surface-showing-that-party-req-pfi-003
	 */
	public function resolve(string $partyId, ?DateTimeImmutable $onDay = null): array;
}//end interface
