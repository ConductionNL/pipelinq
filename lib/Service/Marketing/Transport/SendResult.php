<?php

/**
 * Pipelinq SendResult.
 *
 * The one result shape every transport adapter returns. Adapters never
 * throw for an ordinary send failure (a missing Mail app, a rejected
 * OpenConnector call, a limit reached) — they report it here so
 * `MailTransportService` can record the failure on the BlastDelivery row
 * without a try/catch around every adapter call.
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
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing\Transport;

/**
 * SendResult: an immutable, transport-agnostic send outcome.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
 */
final class SendResult {
	/**
	 * Constructor.
	 *
	 * @param bool $accepted Whether the transport accepted the message for delivery.
	 * @param string|null $providerId The provider/transport message id, when one was returned.
	 * @param string|null $error A short, loggable reason when `$accepted` is false.
	 */
	public function __construct(
		public readonly bool $accepted,
		public readonly ?string $providerId = null,
		public readonly ?string $error = null,
	) {
	}//end __construct()
}//end class
