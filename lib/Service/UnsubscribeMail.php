<?php

/**
 * Pipelinq UnsubscribeMail.
 *
 * Puts integriq's unsubscribe material on a mail: a line in the body, and the
 * List-Unsubscribe and List-Unsubscribe-Post headers through OpenRegister's
 * shared UnsubscribeHeaders helper (hydra decision 6). pipelinq keeps no own
 * copy of the guarded header path.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IL10N;
use OCP\Mail\IMessage;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Body line and headers for one mail.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
 */
class UnsubscribeMail {

	/**
	 * OpenRegister's shared helper, named by string so pipelinq loads without it.
	 */
	public const HEADER_HELPER = 'OCA\\OpenRegister\\Service\\Notification\\UnsubscribeHeaders';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's helper.
	 * @param IL10N              $l10n      Translates the body line.
	 * @param LoggerInterface    $logger    Says why headers were not set.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The plain-text line that carries the link, or empty without a link.
	 *
	 * @param array<string,mixed>|null $unsubscribe Integriq's material.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
	 */
	public function bodyLine(?array $unsubscribe): string {
		$url = trim((string)($unsubscribe['url'] ?? ''));
		if ($url === '') {
			return '';
		}

		return $this->l10n->t('Wilt u deze berichten niet meer ontvangen? Meld u af: %s', [$url]);
	}//end bodyLine()

	/**
	 * A plain-text body with the link line at the end, or the body unchanged without a link.
	 *
	 * @param string                   $body        The body.
	 * @param array<string,mixed>|null $unsubscribe Integriq's material.
	 *
	 * @return string The body.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
	 */
	public function appendLine(string $body, ?array $unsubscribe): string {
		$line = $this->bodyLine(unsubscribe: $unsubscribe);
		if ($line === '') {
			return $body;
		}

		return rtrim($body)."\n\n".$line."\n";
	}//end appendLine()

	/**
	 * Set both headers through OpenRegister's helper.
	 *
	 * @param IMessage                 $message     The message.
	 * @param array<string,mixed>|null $unsubscribe Integriq's material, or `{oneClickUrl}` for a list link.
	 *
	 * @return bool True when both headers were set.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
	 */
	public function applyHeaders(IMessage $message, ?array $unsubscribe): bool {
		if ($unsubscribe === null || $unsubscribe === []) {
			return false;
		}

		try {
			$helper = $this->container->get(self::HEADER_HELPER);
		} catch (Throwable $e) {
			$this->logger->info('Pipelinq: OpenRegister has no UnsubscribeHeaders helper; the mail carries the body link only');
			return false;
		}

		if (is_object($helper) === false || method_exists($helper, 'apply') === false) {
			return false;
		}

		try {
			return ($helper->apply($message, $unsubscribe) === true);
		} catch (Throwable $e) {
			return false;
		}
	}//end applyHeaders()
}//end class
