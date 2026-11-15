<?php

/**
 * Pipelinq ZtcLookup.
 *
 * One catalogue lookup: the ZTC resource type, the omschrijving and any
 * extra filters.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * A ZTC lookup by omschrijving.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
 */
final class ZtcLookup {
	/**
	 * Constructor.
	 *
	 * @param string                   $resourceType One of ZtcClient::RESOURCE_*.
	 * @param string                   $omschrijving The omschrijving to look up.
	 * @param array<string,string|int> $extraQuery   Extra ZTC filters.
	 */
	public function __construct(
		public readonly string $resourceType,
		public readonly string $omschrijving,
		public readonly array $extraQuery = [],
	) {
	}//end __construct()

	/**
	 * The cache key within the resource type's bucket.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
	 */
	public function cacheKey(): string {
		return $this->omschrijving . '|' . json_encode($this->extraQuery, JSON_UNESCAPED_SLASHES);
	}//end cacheKey()

	/**
	 * The ZTC query: the omschrijving, status definitief and the extra filters.
	 *
	 * @return array<string,string|int>
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-004
	 */
	public function query(): array {
		return array_merge(['omschrijving' => $this->omschrijving, 'status' => 'definitief'], $this->extraQuery);
	}//end query()
}//end class
