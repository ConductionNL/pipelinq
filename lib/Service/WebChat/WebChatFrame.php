<?php

/**
 * The page a website chat's window is: the channel it is for, and the sites that may frame it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\WebChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\WebChat;

use OCP\AppFramework\Http\ContentSecurityPolicy;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the channel a chat window is opened for, and builds the policy that
 * lets only that channel's own websites frame the window (D3).
 */
class WebChatFrame {

	/**
	 * Constructor.
	 *
	 * @param WebChatStore    $store  Where the channel is read.
	 * @param LoggerInterface $logger Where an unreadable channel is logged.
	 */
	public function __construct(
		private readonly WebChatStore $store,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The active channel with this token, or null when there is none or it cannot be read.
	 *
	 * @param string $token The channel token.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.2
	 */
	public function channel(string $token): ?array {
		try {
			return $this->store->activeChannel(token: $token);
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq web chat: the window could not read its channel', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end channel()

	/**
	 * The frame policy for a window: the channel's own websites, or nobody without a channel.
	 *
	 * @param array<string, mixed>|null $channel The channel, null when there is none.
	 *
	 * @return ContentSecurityPolicy
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.2
	 */
	public function policy(?array $channel): ContentSecurityPolicy {
		$origins = [];
		if ($channel !== null) {
			$origins = self::framingOrigins(origins: ($channel['allowedOrigins'] ?? []));
		}

		return self::framePolicy(origins: $origins);
	}//end policy()

	/**
	 * The origins a frame policy may name: exact http(s) origins only, so nothing else reaches the header.
	 *
	 * @param mixed $origins The channel's allowed origins.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.2
	 */
	public static function framingOrigins(mixed $origins): array {
		if (is_array($origins) === false) {
			return [];
		}

		$kept = [];
		foreach ($origins as $origin) {
			if (is_string($origin) === false) {
				continue;
			}

			$origin = rtrim(trim($origin), '/');
			if (preg_match('#^https?://[a-z0-9.-]+(:[0-9]{1,5})?$#i', $origin) === 1) {
				$kept[] = strtolower($origin);
			}
		}

		return array_values(array_unique($kept));
	}//end framingOrigins()

	/**
	 * A policy that lets exactly these origins frame the window, and nothing else, not even this site.
	 *
	 * @param list<string> $origins The origins.
	 *
	 * @return ContentSecurityPolicy
	 *
	 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-2.2
	 */
	public static function framePolicy(array $origins): ContentSecurityPolicy {
		$csp = new ContentSecurityPolicy();
		$csp->disallowFrameAncestorDomain('\'self\'');
		foreach ($origins as $origin) {
			$csp->addAllowedFrameAncestorDomain($origin);
		}

		return $csp;
	}//end framePolicy()
}//end class
