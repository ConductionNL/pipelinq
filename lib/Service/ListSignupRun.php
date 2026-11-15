<?php

/**
 * Pipelinq ListSignupRun.
 *
 * One person being put on one mailing list, as SubscriptionService builds it.
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
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * One membership being started or confirmed: the list and the address, and what
 * the flow learns on the way (the existing membership, the list, the contact).
 * Built and used only by SubscriptionService.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */
final class ListSignupRun {
	/**
	 * The membership the address already holds on the list, if any.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $existing = null;

	/**
	 * The list being joined.
	 *
	 * @var array<string, mixed>
	 */
	public array $list = [];

	/**
	 * The known contact, when the caller has one.
	 *
	 * @var string|null
	 */
	public ?string $contactId = null;

	/**
	 * How the membership started or was confirmed.
	 *
	 * @var string
	 */
	public string $source = '';

	/**
	 * Constructor.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param string $email  The normalised address.
	 */
	public function __construct(
		public readonly string $listId,
		public readonly string $email,
	) {
	}//end __construct()
}//end class
