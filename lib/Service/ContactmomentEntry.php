<?php

/**
 * Pipelinq ContactmomentEntry.
 *
 * The arguments of one contactmoment logged through the MCP tool surface.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The client, channel, title, direction, outcome and notes of one contactmoment.
 *
 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
 */
final class ContactmomentEntry {
	/**
	 * Constructor.
	 *
	 * @param string      $client    The client UUID.
	 * @param string      $channel   The channel.
	 * @param string      $title     The title.
	 * @param string      $direction The direction.
	 * @param string|null $outcome   The optional outcome.
	 * @param string|null $notes     The optional notes.
	 */
	public function __construct(
		public readonly string $client,
		public readonly string $channel,
		public readonly string $title,
		public readonly string $direction,
		public readonly ?string $outcome,
		public readonly ?string $notes,
	) {
	}//end __construct()

	/**
	 * The required fields by argument name, in argument order.
	 *
	 * @return array<string, string> The client, channel, title and direction.
	 *
	 * @spec openspec/specs/crm-mcp-tool-surface/spec.md#requirement-mcp-provider-exposes-rbac-guarded-crm-write-tools
	 */
	public function requiredFields(): array {
		return [
			'client' => $this->client,
			'channel' => $this->channel,
			'title' => $this->title,
			'direction' => $this->direction,
		];
	}//end requiredFields()
}//end class
