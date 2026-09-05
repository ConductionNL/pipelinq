<?php

/**
 * Pipelinq SchemaMapService.
 *
 * Service for resolving schema IDs to entity types.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/openregister-integration/spec.md#requirement-schema-to-iappconfig-mapping
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for resolving schema IDs to entity types.
 *
 * @spec exclude infrastructure utility with no feature requirement of its own; it is
 *   exercised through the features that call it
 */
class SchemaMapService {
	/**
	 * Schema config key to entity type mapping.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_MAPPING = [
		'client_schema' => 'client',
		'contact_schema' => 'contact',
		'lead_schema' => 'lead',
		// Unified inbound-matter supertype (unify-ticket-supertype). Replaced the
		// retired `request` / `complaint` / `contactmoment` schemas; a subtype is
		// selected with the `ticketType` discriminator, not a separate schema.
		'ticket_schema' => 'ticket',
		'pipeline_schema' => 'pipeline',
		'skill_schema' => 'skill',
		'agentProfile_schema' => 'agentProfile',
		// The entity type follows the renamed slug; the app-config KEY deliberately
		// does not, matching `klantLoyaltyAccount_schema` below. `timeEntry` was
		// global per organisation and three apps declared one — humaniq's HR
		// booking, planninq's project booking and this app's billing/WIP record —
		// so `SchemaMapper::find()` answered whichever it reached first.
		'timeEntry_schema' => 'billingTimeEntry',
		'task_schema' => 'crmTask',
		'posTransaction_schema' => 'posTransaction',
		// POS staff PIN + role permissions (pos-staff-pin-permissions).
		'posRole_schema' => 'posRole',
		'posStaff_schema' => 'posStaff',
		// Loyalty programme schemas (loyalty-program).
		'loyaltyProgramme_schema' => 'loyaltyProgramme',
		'pointsRule_schema' => 'pointsRule',
		'tierRule_schema' => 'tierRule',
		// The entity type follows the renamed slug; the app-config KEY deliberately
		// does not. See SettingsLoadService::SCHEMA_CONFIG_KEYS — the key is live
		// persisted state and stays until a migration moves it.
		'klantLoyaltyAccount_schema' => 'customerLoyaltyAccount',
		'pointsLedgerEntry_schema' => 'pointsLedgerEntry',
		'redemptionOption_schema' => 'redemptionOption',
		'redemption_schema' => 'redemption',
		'giftCard_schema' => 'giftCard',
		'giftCardTransaction_schema' => 'giftCardTransaction',
		// Expense → Shillinq AP integration (pipelinq-expense-to-shillinq-ap / REQ-AP-001).
		'expense_schema' => 'expense',
		// Billing categories (billable-categories-and-tags / REQ-BCT-001).
		'billingCategory_schema' => 'billingCategory',
	];

	/**
	 * Cached schema map.
	 *
	 * @var ?array
	 */
	private ?array $schemaMap = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve a schema ID to an entity type.
	 *
	 * @param ?string $schemaId The schema ID to resolve.
	 *
	 * @return ?string The entity type or null.
	 * @spec   openspec/changes/reverse-2026-05-26-be-settings/tasks.md#task-11
	 */
	public function resolveEntityType(?string $schemaId): ?string {
		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		if ($this->schemaMap === null) {
			$this->buildSchemaMap();
		}

		return $this->schemaMap[$schemaId] ?? null;
	}//end resolveEntityType()

	/**
	 * Build the schema ID to entity type map from settings.
	 *
	 * @return void
	 */
	private function buildSchemaMap(): void {
		$this->schemaMap = [];

		try {
			$settings = $this->settingsService->getSettings();

			foreach (self::SCHEMA_MAPPING as $configKey => $entityType) {
				$schemaId = $settings[$configKey] ?? '';
				if ($schemaId !== '') {
					$this->schemaMap[$schemaId] = $entityType;
				}
			}
		} catch (\Exception $e) {
			$this->logger->error(
				'Failed to build Pipelinq schema map',
				[
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end buildSchemaMap()
}//end class
