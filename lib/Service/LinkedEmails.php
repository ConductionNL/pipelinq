<?php

/**
 * Pipelinq LinkedEmails.
 *
 * Reads the email links the OpenRegister email leaf holds for an object.
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
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Throwable;

/**
 * The linked-email rows of an object, and the link of one Mail message among them.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */
final class LinkedEmails {
	/**
	 * Constructor.
	 *
	 * @param object $linkService OR EmailLinkService instance.
	 */
	public function __construct(
		private readonly object $linkService,
	) {
	}//end __construct()

	/**
	 * The id of the link the leaf already holds between an object and a
	 * Mail message, when it holds one.
	 *
	 * @param string         $objectUuid Parent OR object uuid.
	 * @param MailMessageKey $message    The Mail message.
	 *
	 * @return int|null Existing link id, or null.
	 *
	 * @spec openspec/specs/email-calendar-sync/spec.md
	 */
	public function existingLinkId(string $objectUuid, MailMessageKey $message): ?int {
		foreach ($this->itemsFor(objectUuid: $objectUuid) as $row) {
			if ($this->isLinkOf(row: $row, message: $message) === true) {
				return (int)($row['id'] ?? 0);
			}
		}

		return null;
	}//end existingLinkId()

	/**
	 * Whether a linked-email row links the Mail message.
	 *
	 * @param mixed          $row     One linked-email row.
	 * @param MailMessageKey $message The Mail message.
	 *
	 * @return bool True when the row is the message's link.
	 */
	private function isLinkOf(mixed $row, MailMessageKey $message): bool {
		return is_array($row) === true
			&& (int)($row['mailAccountId'] ?? 0) === $message->mailAccountId
			&& (int)($row['mailMessageId'] ?? 0) === $message->mailMessageId;
	}//end isLinkOf()

	/**
	 * The linked-email rows the leaf holds for an object.
	 *
	 * The lookup uses the leaf's mapper indirectly — if introspection isn't
	 * available we return no rows and let the leaf's own idempotency handle
	 * the dedup. The count delta will still be correct because the leaf
	 * returns the same entity row on the second call.
	 *
	 * @param string $objectUuid Parent OR object uuid.
	 *
	 * @return array<mixed> The rows of the first page, empty when unavailable.
	 */
	private function itemsFor(string $objectUuid): array {
		if (method_exists($this->linkService, 'getLinkedEmails') === false) {
			return [];
		}

		try {
			$page = $this->linkService->getLinkedEmails($objectUuid, null, 100);
		} catch (Throwable $e) {
			return [];
		}

		return $this->pageItems(page: $page);
	}//end itemsFor()

	/**
	 * The items of a linked-email page.
	 *
	 * @param mixed $page The page the leaf returned.
	 *
	 * @return array<mixed> The items, empty when the page has none.
	 */
	private function pageItems(mixed $page): array {
		if (is_array($page) === true && isset($page['items']) === true && is_array($page['items']) === true) {
			return $page['items'];
		}

		return [];
	}//end pageItems()
}//end class
