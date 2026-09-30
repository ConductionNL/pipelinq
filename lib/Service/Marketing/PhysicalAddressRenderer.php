<?php

/**
 * Pipelinq PhysicalAddressRenderer.
 *
 * Puts a campaign template's physical address (CAN-SPAM) into an email body.
 * Shared by the send path (MailTransportService) and the template preview
 * (TemplateController), so the preview shows what will send.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * Renders the template's physical address into an email body.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
 */
class PhysicalAddressRenderer {
	/**
	 * Tokens that mark where the physical address goes in an email body.
	 * They hold no address themselves: render() replaces them with the
	 * template's `footerOverride` on send and in the preview.
	 *
	 * @var array<int, string>
	 */
	public const TOKENS = [
		'{{physical_address}}',
		'{{sender_address}}',
		'{{company_address}}',
		'{{address_block}}',
	];

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
	public function render(string $body, string $footerOverride, string $format): string {
		$address = trim($footerOverride);
		if ($address === '' || trim($body) === '') {
			return $body;
		}

		$rendered = $address;
		if ($format === 'html') {
			$rendered = nl2br(htmlspecialchars($address, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
		}

		foreach (self::TOKENS as $token) {
			if (str_contains($body, $token) === true) {
				return strtr($body, array_fill_keys(self::TOKENS, $rendered));
			}
		}

		if ($format === 'html') {
			// A full document keeps the address inside its <body>.
			$paragraph = '<p>' . $rendered . '</p>';
			$close = strripos($body, '</body>');
			if ($close !== false) {
				return substr($body, 0, $close) . $paragraph . substr($body, $close);
			}

			return $body . $paragraph;
		}

		return rtrim($body) . "\n\n" . $rendered;
	}//end render()
}//end class
