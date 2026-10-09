<?php

/**
 * Pipelinq CreateSalesGroup repair step.
 *
 * Creates the Nextcloud group `sales` that five notification rules address
 * (newContact, newLead, leadWon, newEnquiry, newTicket). Without it those
 * rules resolved no recipient for the group. The step only creates a missing
 * group: an existing one, its name and its members stay as they are, and it
 * never adds or removes a member.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/create-sales-group/specs/notifications/spec.md#requirement-the-group-the-notification-rules-address-exists-req-raf-071
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Creates the `sales` group when it does not exist.
 *
 * @spec openspec/changes/create-sales-group/specs/notifications/spec.md#requirement-the-group-the-notification-rules-address-exists-req-raf-071
 */
class CreateSalesGroup implements IRepairStep {

	/**
	 * The group id the notification rules address.
	 */
	public const GROUP_ID = 'sales';

	/**
	 * The display name a created group gets.
	 */
	public const DISPLAY_NAME = 'Sales';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager   $groupManager Looks up and creates the group.
	 * @param LoggerInterface $logger       Records a creation.
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/create-sales-group/specs/notifications/spec.md#requirement-the-group-the-notification-rules-address-exists-req-raf-071
	 */
	public function getName(): string {
		return 'Create the sales group the notification rules address';
	}//end getName()

	/**
	 * Create the group when it is missing; change nothing otherwise.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/create-sales-group/specs/notifications/spec.md#requirement-the-group-the-notification-rules-address-exists-req-raf-071
	 */
	public function run(IOutput $output): void {
		if ($this->groupManager->groupExists(gid: self::GROUP_ID) === true) {
			return;
		}

		$group = $this->groupManager->createGroup(gid: self::GROUP_ID);
		if ($group === null) {
			$output->warning('Could not create group '.self::GROUP_ID.'.');
			$this->logger->warning('[pipelinq] Could not create the group '.self::GROUP_ID.' the notification rules address.');
			return;
		}

		$group->setDisplayName(displayName: self::DISPLAY_NAME);
		$output->info('Created group '.self::GROUP_ID.'.');
		$this->logger->info(
			'[pipelinq] Created the group '.self::GROUP_ID.' ('.self::DISPLAY_NAME.') the notification rules address.'
			.' It has no members; an administrator adds them.'
		);
	}//end run()
}//end class
