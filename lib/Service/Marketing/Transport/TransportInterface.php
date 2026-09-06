<?php

/**
 * Pipelinq TransportInterface.
 *
 * Abstract contract every mail transport adapter implements (instance
 * mailer, Mail account, OpenConnector-fronted provider). Mirrors
 * `OCA\Pipelinq\Service\Payment\PaymentProviderInterface`'s shape: one
 * method, no provider-specific arguments leak into the interface, an
 * implementation MUST NOT throw for an ordinary send failure — it reports
 * failure via `SendResult` so `MailTransportService` never wraps an adapter
 * call in its own try/catch.
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
 * Mail transport adapter contract.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
 */
interface TransportInterface {
	/**
	 * Send one rendered mail.
	 *
	 * Implementations MUST NOT throw for an ordinary send failure (transport
	 * unavailable, provider rejected the call, malformed configuration) —
	 * they return a failed {@see SendResult} instead, so a single delivery's
	 * failure never aborts the caller's dispatch loop. Transport-specific
	 * configuration (the resolved `mailTransport` row) is injected via the
	 * adapter's constructor by `MailTransportService`, not passed here —
	 * mirroring `PaymentProviderInterface`'s adapters, which receive their
	 * config once and expose a config-free call surface.
	 *
	 * @param RenderedMail $mail The rendered mail to send.
	 *
	 * @return SendResult The outcome.
	 *
	 * @spec openspec/changes/marketing-mail-transports/specs/marketing-blast/spec.md#requirement-send-via-openconnector-with-per-tenant-provider
	 */
	public function send(RenderedMail $mail): SendResult;
}//end interface
