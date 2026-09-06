<?php

/**
 * Pipelinq SocialPublishRequest.
 *
 * Everything an adapter needs to shape one post for one account, and nothing
 * more. In particular it carries a `credentialRef` and never a token: an
 * adapter has no way to read the grant it publishes with, which is the whole
 * arrangement rule 2 of the marketing architecture asks for.
 *
 * The text and the link are already resolved by the time this object exists.
 * `SocialPostService` has merged the network's variant onto the post and
 * decorated the link with the campaign's UTM parameters, so an adapter never
 * decides what the words are, only how the network wants them wrapped.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Social
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Social;

/**
 * One resolved post, for one account, ready to be shaped.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */
class SocialPublishRequest {
	/**
	 * Constructor.
	 *
	 * @param string $network The network the account lives on.
	 * @param string $body The resolved text, variant already merged onto the post.
	 * @param string $link The resolved link, campaign UTM already applied, or an empty string.
	 * @param array<int, array<string, mixed>> $media The media the post carries.
	 * @param string $credentialRef The broker credential UUID. A reference, never a secret.
	 * @param string $externalAccountId The network's own id for the account, when it needs one in the path.
	 * @param string $accountKind `organisation` or `person`, which changes the LinkedIn author URN.
	 * @param string $handle The account handle, for the composer deep link on the share path.
	 * @param string|null $actingUserId The account owner, asserted to the broker on the sessionless job path.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $network,
		public readonly string $body,
		public readonly string $link = '',
		public readonly array $media = [],
		public readonly string $credentialRef = '',
		public readonly string $externalAccountId = '',
		public readonly string $accountKind = 'organisation',
		public readonly string $handle = '',
		public readonly ?string $actingUserId = null,
	) {
	}//end __construct()

	/**
	 * The body with the link appended, for the networks that carry no separate
	 * link field and expect it inside the text.
	 *
	 * A link already present in the body is not appended twice, because a
	 * marketer who typed it there meant it to be where they put it.
	 *
	 * @return string The text to publish.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-a-posts-link-carries-its-campaign
	 */
	public function bodyWithLink(): string {
		$text = trim($this->body);
		if ($this->link === '' || str_contains($text, $this->link) === true) {
			return $text;
		}

		if ($text === '') {
			return $this->link;
		}

		return $text . "\n\n" . $this->link;
	}//end bodyWithLink()

	/**
	 * The first image the post carries that has a publicly reachable address.
	 *
	 * The networks that fetch media rather than accept an upload need a URL,
	 * so a file that only exists as a Nextcloud path is not one of them.
	 *
	 * @return array<string, mixed>|null The media item, or null when there is none.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-each-networks-request-is-shaped-as-that-network-documents-it
	 */
	public function firstPublicMedia(): ?array {
		foreach ($this->media as $item) {
			if (is_array($item) === true && trim((string)($item['url'] ?? '')) !== '') {
				return $item;
			}
		}

		return null;
	}//end firstPublicMedia()
}//end class
