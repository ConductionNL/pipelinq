<?php

/**
 * Pipelinq MailAddressing.
 *
 * Who a rendered mail is from, who it is to, and where replies go.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing\Transport
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
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

/**
 * The sender, the reply address and the one recipient of a rendered mail.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */
final class MailAddressing {
	/**
	 * Constructor.
	 *
	 * @param string $fromEmail Sender email address.
	 * @param string $fromName  Sender display name (may be empty).
	 * @param string $replyTo   Reply-To address (may be empty, then replies go to `$fromEmail`).
	 * @param string $toEmail   Recipient email address (one delivery, one recipient).
	 */
	public function __construct(
		public readonly string $fromEmail,
		public readonly string $fromName,
		public readonly string $replyTo,
		public readonly string $toEmail,
	) {
	}//end __construct()
}//end class
