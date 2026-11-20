<?php

/**
 * Contract of CampaignService
 *
 * The public surface of CampaignService that other components call, so they depend
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
 * Contract of CampaignService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CampaignServiceInterface {

	/**
	 * Every campaign matching a filter set.
	 *
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The campaigns.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function all(array $filters = []): array;

	/**
	 * One campaign by id or slug.
	 *
	 * @param string $id Campaign UUID or slug.
	 *
	 * @return array<string, mixed>|null The campaign, or null.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function find(string $id): ?array;

	/**
	 * The canonical id of a campaign row.
	 *
	 * @param array<string, mixed>|null $campaign The campaign.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function idOf(?array $campaign): string;

	/**
	 * Create or update a campaign.
	 *
	 * On a create the slug is minted from the name. On an update the
	 * stored slug wins over anything the caller sent, so a rename cannot
	 * move it. A source or medium outside the vocabulary is refused with
	 * the offending value and the allowed list named, rather than
	 * silently lowercased: correcting it here would hide a typo that
	 * splits a campaign in two.
	 *
	 * @param array<string, mixed> $payload The campaign fields.
	 * @param string|null $id Existing campaign id when updating.
	 * @param string $uid The user making the change.
	 *
	 * @return array{error: string, value: string, allowed: array<int, string>, campaign: array<string, mixed>|null}
	 *         `error` empty on success.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function save(array $payload, ?string $id = null, string $uid = ''): array;

	/**
	 * The allowed source and medium values.
	 *
	 * Both in one call because they are one decision: an administrator
	 * who adds a source almost always adds the medium it arrives on, and
	 * a page that offers one picker without the other is half a form.
	 *
	 * @return array{sources: array<int, string>, mediums: array<int, string>}
	 *         Both vocabularies, lowercase and unique.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function vocabularies(): array;
}//end interface
