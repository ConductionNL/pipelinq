<?php

/**
 * Pipelinq RolLinkRun.
 *
 * The resolved state of one rol link inside ZrcClient: the request, the
 * ZgwClient, the ZRC URL and the contact's ZGW identification.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

/**
 * One rol link run; built and read by ZrcClient only.
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-010
 */
final class RolLinkRun {
	/**
	 * Constructor.
	 *
	 * @param InitiatorLink        $link     The link request.
	 * @param array<string, mixed> $client   Resolved ZgwClient record.
	 * @param string               $zrcUrl   ZRC component URL.
	 * @param string               $betrType ZGW betrokkeneType of the contact.
	 * @param array<string, mixed> $ident    ZGW identification of the contact.
	 */
	public function __construct(
		public readonly InitiatorLink $link,
		public readonly array $client,
		public readonly string $zrcUrl,
		public readonly string $betrType,
		public readonly array $ident,
	) {
	}//end __construct()
}//end class
