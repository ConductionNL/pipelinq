<?php

/**
 * Pipelinq WhatsAppTemplateSend.
 *
 * The resolved template of one WhatsApp send, empty for a free-form send.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

/**
 * The template a WhatsApp send uses; an empty name means free-form.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WhatsAppTemplateSend {
	/**
	 * Constructor.
	 *
	 * @param string                    $templateName Resolved template external id, or empty for free-form.
	 * @param string                    $language     Resolved language.
	 * @param array<int, string>        $parameters   Positional template parameters.
	 * @param array<string, mixed>|null $template     Resolved template row, or null for free-form.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $templateName,
		public readonly string $language,
		public readonly array $parameters,
		public readonly ?array $template,
	) {
	}//end __construct()
}//end class
