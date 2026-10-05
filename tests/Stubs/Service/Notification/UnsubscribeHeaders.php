<?php

/**
 * Test stub for OCA\OpenRegister\Service\Notification\UnsubscribeHeaders.
 *
 * Mirrors the public contract of OpenRegister's shared helper
 * (openregister branch feat/opt-out-before-send, REQ-ERO-005):
 * `apply(IMessage, array): bool`, never throws, prefers `oneClickUrl` over
 * `url`, accepts only http(s), and sets both RFC 8058 headers through the
 * mailer's Symfony email when it is exposed.
 *
 * Declaration only. Loaded by tests/bootstrap.php only when the real class is
 * absent, so production never loads it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Stubs\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Notification;

use OCP\Mail\IMessage;
use Throwable;

if (class_exists(UnsubscribeHeaders::class, false) === false) {
	/**
	 * Sets List-Unsubscribe and List-Unsubscribe-Post on a Nextcloud mail message.
	 */
	class UnsubscribeHeaders {

		public const HEADER_UNSUBSCRIBE = 'List-Unsubscribe';

		public const HEADER_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';

		public const ONE_CLICK = 'List-Unsubscribe=One-Click';

		/**
		 * Set both headers from integriq's unsubscribe material.
		 *
		 * @param IMessage            $message     The message.
		 * @param array<string,mixed> $unsubscribe The material.
		 *
		 * @return bool True when both headers are set.
		 */
		public function apply(IMessage $message, array $unsubscribe): bool {
			$url = trim((string)($unsubscribe['oneClickUrl'] ?? ''));
			if ($url === '') {
				$url = trim((string)($unsubscribe['url'] ?? ''));
			}

			if ($url === '' || preg_match('#^https?://[^\s<>]+$#i', $url) !== 1) {
				return false;
			}

			if (method_exists($message, 'getSymfonyEmail') === false) {
				return false;
			}

			try {
				$headers = $message->getSymfonyEmail()->getHeaders();
				foreach ([self::HEADER_UNSUBSCRIBE, self::HEADER_UNSUBSCRIBE_POST] as $name) {
					if ($headers->has($name) === true) {
						$headers->remove($name);
					}
				}

				$headers->addTextHeader(self::HEADER_UNSUBSCRIBE, '<'.$url.'>');
				$headers->addTextHeader(self::HEADER_UNSUBSCRIBE_POST, self::ONE_CLICK);
			} catch (Throwable $e) {
				return false;
			}

			return true;
		}//end apply()
	}//end class
}//end if
