<?php

/**
 * Pipelinq RenderedMail.
 *
 * The one rendered-mail shape every transport adapter receives. Built once
 * by `MailTransportService` from `BlastService`'s existing per-recipient
 * template rendering, then handed unchanged to whichever adapter the
 * resolved `mailTransport` selects — no adapter re-derives subject/body
 * content, and no adapter sees the recipient's full delivery row.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing\Transport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * RenderedMail: an immutable, transport-agnostic rendered message.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */
final class RenderedMail {
	/**
	 * Constructor.
	 *
	 * @param string $fromEmail Sender email address.
	 * @param string $fromName Sender display name (may be empty).
	 * @param string $replyTo Reply-To address (may be empty — falls back to `$fromEmail`).
	 * @param string $toEmail Recipient email address (one delivery, one recipient).
	 * @param string $subject Rendered subject.
	 * @param string $html Rendered HTML body.
	 * @param string $text Rendered plain-text body.
	 * @param array<string, string> $headers Extra headers to set when the
	 *                                       transport supports it (e.g. RFC 8058
	 *                                       `List-Unsubscribe`). Never required —
	 *                                       every transport degrades soft when it
	 *                                       cannot set them.
	 * @param string $deliveryId The BlastDelivery UUID or slug this mail was rendered for.
	 */
	public function __construct(
		public readonly string $fromEmail,
		public readonly string $fromName,
		public readonly string $replyTo,
		public readonly string $toEmail,
		public readonly string $subject,
		public readonly string $html,
		public readonly string $text,
		public readonly array $headers,
		public readonly string $deliveryId,
	) {
	}//end __construct()
}//end class
