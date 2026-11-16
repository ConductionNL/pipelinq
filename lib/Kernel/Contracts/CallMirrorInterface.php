<?php

/**
 * Contract of CallMirror
 *
 * The public surface of CallMirror that other components call, so they depend
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
 * Contract of CallMirror.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */
interface CallMirrorInterface {

	/**
	 * Mirror one call event. A second delivery of the same event writes nothing.
	 *
	 * @param array<string, mixed> $call The event: callId, kind, callerNumber, callerName, openCases, agentId, sourceId, at, durationSeconds.
	 * @param int|null $now Unix time the row is stamped with; null is the clock.
	 *
	 * @return string|null The row's id (the existing one on a repeat), or null when nothing was written.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
	 */
	public function mirror(array $call, ?int $now = null): ?string;

	/**
	 * Remove mirrored calls older than the retention period.
	 *
	 * @param int|null $now Unix time to measure against; null is the clock.
	 *
	 * @return int How many rows were removed.
	 *
	 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
	 */
	public function purgeExpired(?int $now = null): int;
}//end interface
