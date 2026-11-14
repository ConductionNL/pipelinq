<?php

/**
 * Pipelinq MessageRoute.
 *
 * Who an outbound message goes to and how it is routed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * The contact, an optional pinned vendor, the linked client and the inbound
 * message this one answers.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */
class MessageRoute {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $contact      The pre-loaded contact object.
	 * @param string|null          $providerHint Optional pinned vendor (sms only).
	 * @param string               $clientId     Linked client UUID (audit), or empty for the contact's own.
	 * @param string               $inReplyTo    The contact's inbound message this answers, or empty.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $contact,
		public readonly ?string $providerHint = null,
		public readonly string $clientId = '',
		public readonly string $inReplyTo = '',
	) {
	}//end __construct()

	/**
	 * The audit context the adapters receive: agent, client and reply target.
	 *
	 * @param string $agent Acting user id.
	 *
	 * @return array<string, string> The context.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	public function adapterContext(string $agent): array {
		$client = $this->clientId;
		if ($client === '') {
			$client = (string)($this->contact['client'] ?? '');
		}

		$context = ['agent' => $agent, 'clientId' => $client];
		if ($this->inReplyTo !== '') {
			$context['inReplyTo'] = $this->inReplyTo;
		}

		return $context;
	}//end adapterContext()
}//end class
