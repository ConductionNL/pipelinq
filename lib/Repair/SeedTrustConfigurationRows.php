<?php

/**
 * Pipelinq SeedTrustConfigurationRows.
 *
 * Idempotent repair step that seeds pipelinq's three account trust-tier rows
 * into OpenRegister's generic `trust-configuration` register (schema slug
 * `trustConfiguration`), which OR now owns (ADR-045 #D). The rows previously
 * lived in the pipelinq register file as a local `trustConfiguration` schema;
 * that local schema + its seeds are removed in this change, and OR's
 * `TrustTierResolver` reads these rows during survivorship recompute.
 *
 * Runs as a post-migration repair step so OpenRegister's autoloader + its
 * imported `trust-configuration` register are available (a plain migration runs
 * before peer app autoloaders). Writes go through OR's ObjectService (RBAC +
 * multitenancy), never a raw DB insert. Re-running is a safe no-op: each row is
 * matched on its (entityType, attribute, sourceSystem) natural key before write.
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
 * @spec openspec/specs/master-data-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Idempotent seed of pipelinq's trust-tier rows into OR's trust-configuration register.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the small NC + OR
 *  collaborator set a data-seed step needs.
 *
 * @spec openspec/specs/master-data-management/spec.md
 */
class SeedTrustConfigurationRows implements IRepairStep
{
    /**
     * OpenRegister's trust-configuration register slug.
     *
     * @var string
     */
    private const OR_TRUST_REGISTER = 'trust-configuration';

    /**
     * OpenRegister's trustConfiguration schema slug.
     *
     * @var string
     */
    private const OR_TRUST_SCHEMA = 'trustConfiguration';

    /**
     * The three account trust rows migrated one-to-one from the pipelinq
     * register file. Same field shape as OR's trustConfiguration schema.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TRUST_ROWS = [
        [
            'entityType'            => 'account',
            'attribute'             => 'billingAddress',
            'sourceSystem'          => 'kvk-api',
            'trustTier'             => 'gold',
            'freshnessDecayDays'    => 180,
            'manualOverrideAllowed' => true,
            'rationale'             => 'KvK is government-verified source for Dutch business addresses.',
            'effectiveFrom'         => '2026-06-01',
        ],
        [
            'entityType'            => 'account',
            'attribute'             => 'phone',
            'sourceSystem'          => 'shillinq-debiteuren',
            'trustTier'             => 'silver',
            'freshnessDecayDays'    => 90,
            'manualOverrideAllowed' => true,
            'rationale'             => 'Shillinq phone numbers are used for billing communication; fresher than CRM.',
            'effectiveFrom'         => '2026-06-01',
        ],
        [
            'entityType'            => 'account',
            'attribute'             => 'vatNumber',
            'sourceSystem'          => 'kvk-api',
            'trustTier'             => 'gold',
            'freshnessDecayDays'    => 365,
            'manualOverrideAllowed' => false,
            'rationale'             => 'KvK VAT numbers are legally binding; override not permitted.',
            'effectiveFrom'         => '2026-06-01',
        ],
    ];

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager The app manager.
     * @param ContainerInterface $container  The DI container (OR ObjectService lookup).
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string The step name.
     */
    public function getName(): string
    {
        return 'Seed pipelinq trust-tier rows into OpenRegister trust-configuration register (idempotent)';
    }//end getName()

    /**
     * Run the repair step (IRepairStep entry point).
     *
     * @param IOutput $output The output interface.
     *
     * @return void
     *
     * @spec openspec/specs/master-data-management/spec.md
     */
    public function run(IOutput $output): void
    {
        if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
            $output->warning('OpenRegister not installed — skipping trust-configuration seed.');
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $output->warning('OpenRegister ObjectService unavailable — skipping trust-configuration seed.');
            return;
        }

        $seeded  = 0;
        $skipped = 0;
        foreach (self::TRUST_ROWS as $row) {
            try {
                if ($this->rowExists(objectService: $objectService, row: $row) === true) {
                    $skipped++;
                    continue;
                }

                $objectService->saveObject(
                    object: $row,
                    extend: [],
                    register: self::OR_TRUST_REGISTER,
                    schema: self::OR_TRUST_SCHEMA,
                    uuid: null
                );
                $seeded++;
            } catch (\Throwable $e) {
                $output->warning(
                    sprintf(
                        'Trust-configuration seed failed for %s/%s/%s: %s',
                        $row['entityType'],
                        $row['attribute'],
                        $row['sourceSystem'],
                        $e->getMessage()
                    )
                );
                $this->logger->warning(
                    'Pipelinq MDM: trust-configuration seed row failed',
                    ['row' => $row, 'exception' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        $output->info(
            sprintf('Trust-configuration seed complete: %d seeded, %d already present.', $seeded, $skipped)
        );
    }//end run()

    /**
     * Whether a trust row for this (entityType, attribute, sourceSystem) already
     * exists in OpenRegister's trust-configuration register.
     *
     * @param mixed                $objectService The OR ObjectService.
     * @param array<string, mixed> $row           The candidate trust row.
     *
     * @return bool True when a matching row already exists.
     */
    private function rowExists(mixed $objectService, array $row): bool
    {
        $results = $objectService->findAll(
            config: [
                'filters' => [
                    'register'     => self::OR_TRUST_REGISTER,
                    'schema'       => self::OR_TRUST_SCHEMA,
                    'entityType'   => $row['entityType'],
                    'attribute'    => $row['attribute'],
                    'sourceSystem' => $row['sourceSystem'],
                ],
            ]
        );

        return empty($results) === false;
    }//end rowExists()
}//end class
