<?php

/**
 * Pipelinq RenameCollidingSchemaSlugs repair step.
 *
 * Moves this app's share of the cross-app slug collisions onto namespaced slugs
 * IN PLACE, before InitializeSettings imports the register.
 *
 * A schema slug is global per organisation and `SchemaMapper::find()` matches
 * `LOWER(slug)`, so when two apps declare one slug the lookup answers with whichever
 * row it reached first. Comparing the declared property sets settles which way out to
 * take: a pair that shares most of its fields is one record and should be consolidated,
 * and a pair that shares almost nothing is two records that reached for the same word
 * and should be renamed apart. Both slugs handled here share NOTHING with the app they
 * collide with, so both are renamed apart.
 *
 * Why a repair step and why FIRST. OpenRegister's import matches an existing schema by
 * (application, slug): `ImportHandler` calls `findByApplicationAndSlug()` and creates a
 * NEW schema when that misses. A slug rename in the shipped fragment therefore does not
 * rename anything — it CREATES a second schema and silently orphans the first, together
 * with every object already written against it. The old schema keeps its shard table and
 * its rows; the app resolves the new id and reads an empty collection. Nothing errors.
 * Renaming the row first means the import finds the schema it was always going to find,
 * keeps its id, and updates it in place.
 *
 * The app-config KEYS are a separate concern and deliberately do not move; see
 * {@see \OCA\Pipelinq\Service\SettingsLoadService::SCHEMA_CONFIG_KEYS}.
 *
 * Idempotent per slug: a no-op once renamed, and a no-op on an install that never had
 * the schema. Refuses a pair when both slugs exist, because picking one would decide
 * which set of objects to abandon.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames this app's colliding schema slugs in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass. Pointing
 *  this at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
class RenameCollidingSchemaSlugs implements IRepairStep {
	/**
	 * Old slug => new slug, with the app it collided with.
	 *
	 * `cashCount` collided with shillinq's kasadministratie Z-report and
	 * `conversation` with hermiq's agent chat thread. Each pair shares ZERO
	 * declared fields, which is why both are renamed apart rather than folded.
	 *
	 * @var array<string, array{to: string, with: string}>
	 */
	private const RENAMES = [
		'cashCount' => ['to' => 'posCashCount', 'with' => 'shillinq'],
		'conversation' => ['to' => 'channelConversation', 'with' => 'hermiq'],
		// `contract` is the one of the three that is NOT renamed apart because
		// the records differ. shillinq, stackiq and this app all carry
		// `contractNumber`, so it is one contract seen three ways. shillinq owns
		// the lifecycle (ADR-066, the same reference the ticket supertype
		// already carries); this is the sales side and it now points at it.
		'contract' => ['to' => 'salesContract', 'with' => 'shillinq'],
		// The portal pair. portaliq owns the portal, so its slugs stay bare.
		// These two are the CRM side: a local credential store, with password
		// hash, MFA secrets and reset tokens, against portaliq's OIDC identity
		// projection. They share an email address, and an email is a contact
		// attribute rather than something that identifies the record, so they
		// are renamed apart rather than folded the way `contract` was.
		'portalAccount' => ['to' => 'crmPortalAccount', 'with' => 'portaliq'],
		'portalSession' => ['to' => 'crmPortalSession', 'with' => 'portaliq'],
		// The appointment resource. shillinq's bookings subsystem is the larger
		// claimant of `resource`, so its slug stays bare; this is the room, chair
		// or person a customer books time with. They share `name`, `status` and
		// `type`, and nothing that identifies the record.
		'resource' => ['to' => 'appointmentResource', 'with' => 'shillinq'],
		// The channel message, alongside `channelConversation` above. hermiq is
		// the messaging app and owns the bare slug; this is one WhatsApp or SMS
		// message on a channel thread. The two share only `conversationId`,
		// which points at a thread rather than identifying the message.
		'message' => ['to' => 'channelMessage', 'with' => 'hermiq'],
		// The appointment booking, alongside `appointmentResource` above.
		// shillinq's bookings subsystem is the larger claimant, so its slug stays
		// bare. The two share `status` and nothing else.
		'booking' => ['to' => 'appointmentBooking', 'with' => 'shillinq'],
		// The bookable service, completing the appointment set. `service` was
		// claimed by three apps and all three share only `name`, so all three
		// namespace: shillinq keeps the bare slug, stackiq took catalogService.
		'service' => ['to' => 'appointmentService', 'with' => 'shillinq, stackiq'],
		// The CRM task. `task` was claimed by three apps and they share only
		// `description`, `priority` and `status`. planninq's project task is the
		// largest and keeps the bare slug; dossiq took caseTask.
		'task' => ['to' => 'crmTask', 'with' => 'planninq, dossiq'],
		// The last two. `expense` is humaniq's employee reimbursement claim
		// against this app's billable client cost; they share generic expense
		// attributes and no receipt or expense number. `mergeOperation` is the
		// same merge MECHANICS as openregister's (snapshot, reversible) applied
		// to a different subject: OR merges objects by uuid, this merges MDM
		// master entities by masterId, which are different id spaces.
		'expense' => ['to' => 'billableExpense', 'with' => 'humaniq'],
		'mergeOperation' => ['to' => 'masterMergeOperation', 'with' => 'openregister'],
	];

	/**
	 * The owning application, as stored on the schema row.
	 *
	 * @var string
	 */
	private const APPLICATION = 'pipelinq';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function getName(): string {
		return 'Namespace the pipelinq schema slugs that collided with another app';
	}//end getName()

	/**
	 * Rename each slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function run(IOutput $output): void {
		foreach (self::RENAMES as $from => $rename) {
			$this->renameOne(output: $output, from: $from, to: $rename['to'], with: $rename['with']);
		}
	}//end run()

	/**
	 * Rename one slug.
	 *
	 * @param IOutput $output Repair output.
	 * @param string $from The slug being moved away from.
	 * @param string $to The namespaced slug.
	 * @param string $with The app this slug collided with.
	 *
	 * @return void
	 */
	private function renameOne(IOutput $output, string $from, string $to, string $with): void {
		$old = $this->schemaIds(slug: $from);
		$new = $this->schemaIds(slug: $to);

		if ($old === null || $new === null) {
			$output->info('RenameCollidingSchemaSlugs: schema table unreadable; leaving `' . $from . '` alone.');
			return;
		}

		if ($old === []) {
			$output->info('RenameCollidingSchemaSlugs: no `' . $from . '` on this install; nothing to do.');
			return;
		}

		if ($new !== []) {
			// Both slugs present: each may own objects, and renaming would collide
			// with the new row. Abandoning either set of objects is not a call a
			// repair step gets to make without being asked.
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: both slugs exist; refusing to merge them.',
				['from' => $from, 'to' => $to, 'old' => $old, 'new' => $new]
			);
			$output->warning(
				'RenameCollidingSchemaSlugs: both `' . $from . '` and `' . $to
				. '` exist; refusing to merge them. Resolve by hand.'
			);
			return;
		}

		if (count($old) > 1) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: duplicate slugs; refusing to guess.',
				['from' => $from, 'ids' => $old]
			);
			$output->warning('RenameCollidingSchemaSlugs: duplicate `' . $from . '` schemas; refusing to guess.');
			return;
		}

		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[$to, $old[0]]
			);
		} catch (Exception $e) {
			// A failure here is safe: the import then creates a new schema rather
			// than updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				'RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema.',
				['from' => $from, 'id' => $old[0], 'exception' => $e->getMessage()]
			);
			$output->warning('RenameCollidingSchemaSlugs: renaming `' . $from . '` failed; see the log.');
			return;
		}

		$output->info(
			'RenameCollidingSchemaSlugs: schema ' . $old[0] . ' renamed `' . $from . '` -> `' . $to
			. '` (collided with ' . $with . '); its objects stay attached.'
		);
	}//end renameOne()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
				[$slug, self::APPLICATION]
			)->fetchAll(\PDO::FETCH_COLUMN);

			return array_values((array)$rows);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: could not read the schema table; skipping.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()
}//end class
