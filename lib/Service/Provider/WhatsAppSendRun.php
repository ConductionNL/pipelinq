<?php

/**
 * Pipelinq WhatsAppSendRun.
 *
 * One WhatsAppAdapter::send() call: the message, the template and the tenant.
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
 * What every provider attempt of one WhatsApp send shares.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WhatsAppSendRun {
	/**
	 * Constructor.
	 *
	 * @param WhatsAppMessage      $message      The message to send.
	 * @param WhatsAppTemplateSend $templateSend The resolved template, or an empty one for free-form.
	 * @param string               $tenantId     Tenant id, for the budget.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly WhatsAppMessage $message,
		public readonly WhatsAppTemplateSend $templateSend,
		public readonly string $tenantId,
	) {
	}//end __construct()

	/**
	 * The stored outbound body: a `[template:NAME]` marker for a template
	 * send, the free-form body otherwise.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function outboundBody(): string {
		if ($this->templateSend->templateName !== '') {
			return sprintf('[template:%s]', $this->templateSend->templateName);
		}

		return $this->message->body;
	}//end outboundBody()
}//end class
