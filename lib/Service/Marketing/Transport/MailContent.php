<?php

/**
 * Pipelinq MailContent.
 *
 * What a rendered mail says.
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
 * The rendered subject and the HTML and plain-text bodies.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */
final class MailContent {
	/**
	 * Constructor.
	 *
	 * @param string $subject Rendered subject.
	 * @param string $html    Rendered HTML body.
	 * @param string $text    Rendered plain-text body.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $html,
		public readonly string $text,
	) {
	}//end __construct()
}//end class
