<?php

/**
 * Pipelinq WhatsAppTemplateRequest.
 *
 * The template a WhatsAppAdapter::send() call asks for.
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
 * The template id (none for a free-form send), its positional parameters
 * and the default language a send asks for, before the template is
 * resolved.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class WhatsAppTemplateRequest {
	/**
	 * Constructor.
	 *
	 * @param string|null        $templateId Template UUID/slug, or null for free-form.
	 * @param array<int, string> $parameters Positional template parameters.
	 * @param string             $language   Default language (app-config).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $templateId,
		public readonly array $parameters,
		public readonly string $language,
	) {
	}//end __construct()

	/**
	 * Whether the send names a template.
	 *
	 * @return bool True for a template send.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function isTemplateSend(): bool {
		return ($this->templateId ?? '') !== '';
	}//end isTemplateSend()
}//end class
