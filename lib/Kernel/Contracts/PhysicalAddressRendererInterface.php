<?php

/**
 * Contract of PhysicalAddressRenderer
 *
 * The public surface of PhysicalAddressRenderer that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of PhysicalAddressRenderer.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PhysicalAddressRendererInterface {

	/**
	 * Put the template's physical address into one body of an email.
	 *
	 * The address is the template's `footerOverride`. Every address token in
	 * the body (see `TOKENS`) is replaced by it; a body with no token gets it
	 * as a closing paragraph, inside `</body>` when the body is a full HTML
	 * document. An empty body stays empty, and so does a template without an
	 * address, which ComplianceService::validateTemplate() refuses for email
	 * anyway.
	 *
	 * @param string $body The HTML or plain-text body.
	 * @param string $footerOverride The template's physical address.
	 * @param string $format `html` or `text`; HTML escapes the address and keeps its line breaks.
	 *
	 * @return string The body with the address in place.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
	 */
	public function render(string $body, string $footerOverride, string $format): string;
}//end interface
