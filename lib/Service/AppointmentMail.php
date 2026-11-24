<?php

/**
 * Pipelinq AppointmentMail.
 *
 * What an appointment confirmation or reminder email carries.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * One appointment email: recipient, subject, body and optional calendar attachment.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class AppointmentMail {
	/**
	 * Constructor.
	 *
	 * @param string  $recipient  The recipient address.
	 * @param string  $subject    Subject line.
	 * @param string  $body       Plain-text body.
	 * @param ?string $icsContent Optional `.ics` attachment body.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function __construct(
		public readonly string $recipient,
		public readonly string $subject,
		public readonly string $body,
		public readonly ?string $icsContent,
	) {
	}//end __construct()
}//end class
