<?php

/**
 * Pipelinq Portal Contribution Provider
 *
 * Pipelinq's contribution to the shared Portaliq external portal (hydra
 * ADR-046 + contract v2, 2026-07-06 amendment). Portaliq — the ONE shared
 * portal for people WITHOUT Nextcloud accounts — discovers this class by
 * convention FQCN (`OCA\{Namespace}\Portal\PortalContributionProvider`) and
 * duck-types it via method_exists(), never instanceof. This class is therefore
 * deliberately PLAIN: no portaliq imports, no `implements` clause, no info.xml
 * dependency, no constructor dependencies. Without portaliq installed it is
 * inert and pipelinq behaves exactly as before.
 *
 * It declares — for the `client` (B2B org contact) and `customer` (B2C)
 * audiences — the OpenRegister collections a portal subject may read and the
 * whitelisted create-actions they may perform. Scoping claims
 * (claims.pipelinq.clientId / contactId / customerUid) are pipelinq's STABLE
 * claim contract; see openspec/changes/portal-contribution/design.md. The
 * bespoke in-app portal (lib/Controller/Portal*, lib/Service/Portal/*) is
 * untouched here; its retirement is a later phase on Conduction/pipelinq#343.
 *
 * @category Portal
 * @package  OCA\Pipelinq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Portal;

/**
 * Declares what an external portal subject may see and do in pipelinq.
 *
 * The contribution is a declarative manifest (pure data — no I/O, no
 * callbacks). All subject identity (subjectRef, audience, organisation, trust)
 * is derived server-side by portaliq's auth edge and MUST never be trusted
 * from the client (ADR-005). Scoping uses UUID domain refs (client object
 * UUID, contact object UUID, Nextcloud contact UID) — never Nextcloud user
 * ids, because externals have no Nextcloud account by premise.
 *
 * Field-projected surfaces (portaliq now whitelist-projects rows after per-row
 * verification — identifiers always survive, a malformed `fields` declaration
 * degrades to identifiers-only): `contactmoment` (client) and `booking`
 * (customer) ship with explicit `fields` whitelists that drop every staff-only/
 * internal property — agent identity + CTI call internals on contactmoment;
 * `internalNotes`, the audit `statusHistory` and resource assignments on
 * booking. The `berichtenboxMessage` inbox stays excluded (BSN-scoped, not
 * contact/customer-scoped). Rationale + whitelist tables:
 * openspec/changes/portal-projected-collections/design.md.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
class PortalContributionProvider
{
    /**
     * The OpenRegister register slug every collection below lives in.
     *
     * @var string
     */
    private const REGISTER = 'pipelinq';

    /**
     * The audiences this provider contributes to (contract v2, preferred).
     *
     * The registry probes for this method first. Pipelinq serves B2B client
     * organisation contacts (`client`) and B2C customers (`customer`).
     *
     * @return array<int, string> The audience identifiers.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function getAudiences(): array
    {
        return ['client', 'customer'];
    }//end getAudiences()

    /**
     * The primary audience this provider contributes to (contract v1 fallback).
     *
     * Kept alongside getAudiences() so the provider also works against a v1
     * registry that predates multi-audience support.
     *
     * @return string The primary audience identifier.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function getAudience(): string
    {
        return 'client';
    }//end getAudience()

    /**
     * Build the declarative portal manifest for one resolved subject.
     *
     * The subject array is server-derived by portaliq (subjectRef UUID,
     * audience, organisation, trust level low|substantial|high). Returns null
     * for any audience pipelinq does not serve (fail-closed; the registry
     * already filters by audience, but a provider must not rely on that).
     * Wave 1 declares create-actions only — no endpoint actions (receiver-side
     * assertion verification does not exist yet).
     *
     * @param array<string, mixed> $subject The resolved portal subject.
     *
     * @return array<string, mixed>|null The manifest, or null when not contributing.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    public function getContribution(array $subject): ?array
    {
        $audience = $subject['audience'] ?? '';

        if ($audience === 'client') {
            return $this->clientContribution();
        }

        if ($audience === 'customer') {
            return $this->customerContribution();
        }

        return null;
    }//end getContribution()

    /**
     * Manifest for the `client` audience (B2B organisation contact).
     *
     * Read surfaces are org-scoped: the subject's `claims.pipelinq.clientId`
     * (the pipelinq `client` object UUID) matches `request.client`,
     * `complaint.client`, `contract.clientRef` and `contactmoment.client`.
     * `contactmoment` ships field-projected — only `subject`, `channel`,
     * `outcome` and `contactedAt` — so the internal `notes`, raw
     * `channelMetadata`, `duration`, agent identity and CTI call internals
     * (recording URL, disposition notes) never reach the client. Create actions
     * whitelist intake fields only; status, assignment, pipeline/queue and SLA
     * fields stay back-office-only.
     *
     * @return array<string, mixed> The client manifest.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    private function clientContribution(): array
    {
        return [
            'label'         => 'Pipelinq',
            'collections'   => [
                [
                    'id'          => 'clientRequests',
                    'register'    => self::REGISTER,
                    'schema'      => 'request',
                    'scopeField'  => 'client',
                    'scopeClaim'  => 'clientId',
                    'label'       => 'My requests',
                    'listable'    => true,
                    // Contribution-manifest-v3 UI (ADR-063), presentation-only:
                    // a column set, a detail layout, and a newest-first sort. The
                    // back-office `assignee`/`pipeline`/`stage` fields are simply
                    // not columns here — and stay unreadable because they are not
                    // in any `fields` projection the client can widen.
                    'columns'     => [
                        ['field' => 'title', 'label' => 'Onderwerp'],
                        ['field' => 'category', 'label' => 'Categorie'],
                        ['field' => 'status', 'label' => 'Status', 'render' => 'badge'],
                        ['field' => 'requestedAt', 'label' => 'Ingediend', 'render' => 'date'],
                    ],
                    'detail'      => ['layout' => 'card', 'fields' => ['title', 'category', 'status', 'description', 'requestedAt']],
                    'defaultSort' => ['field' => 'requestedAt', 'direction' => 'desc'],
                ],
                [
                    'id'         => 'clientComplaints',
                    'register'   => self::REGISTER,
                    'schema'     => 'complaint',
                    'scopeField' => 'client',
                    'scopeClaim' => 'clientId',
                    'label'      => 'My complaints',
                    'listable'   => true,
                ],
                [
                    'id'         => 'clientContracts',
                    'register'   => self::REGISTER,
                    'schema'     => 'contract',
                    'scopeField' => 'clientRef',
                    'scopeClaim' => 'clientId',
                    'label'      => 'My contracts',
                    'listable'   => true,
                ],
                [
                    'id'         => 'clientContactmoments',
                    'register'   => self::REGISTER,
                    'schema'     => 'contactmoment',
                    'scopeField' => 'client',
                    'scopeClaim' => 'clientId',
                    'label'      => 'My contact history',
                    'listable'   => true,
                    'fields'     => [
                        'subject',
                        'channel',
                        'outcome',
                        'contactedAt',
                    ],
                ],
            ],
            'actions'       => [
                [
                    'id'               => 'createRequest',
                    'type'             => 'create',
                    'label'            => 'Submit a request',
                    'register'         => self::REGISTER,
                    'schema'           => 'request',
                    'fields'           => [
                        'title',
                        'description',
                        'category',
                    ],
                    // Contribution-manifest-v3 form config. `fieldConfigs` may only
                    // describe whitelisted fields; the category dropdown is a
                    // static enum here (a scoped `collection` provider would be
                    // used for a live-OR-backed picker).
                    'fieldConfigs'     => [
                        'title'       => [
                            'label'       => 'Onderwerp',
                            'required'    => true,
                            'size'        => 'large',
                            'placeholder' => 'Waar gaat uw verzoek over?',
                        ],
                        'description' => [
                            'label'       => 'Omschrijving',
                            'required'    => true,
                            'size'        => 'full',
                            'placeholder' => 'Beschrijf uw verzoek zo volledig mogelijk',
                        ],
                        'category'    => ['label' => 'Categorie'],
                    ],
                    'optionsProviders' => [
                        'category' => [
                            'type'    => 'static',
                            'options' => [
                                ['value' => 'Support', 'label' => 'Support'],
                                ['value' => 'Facturatie', 'label' => 'Facturatie'],
                                ['value' => 'Technisch', 'label' => 'Technisch'],
                                ['value' => 'Accountbeheer', 'label' => 'Accountbeheer'],
                                ['value' => 'Sales', 'label' => 'Sales'],
                                ['value' => 'Integratie', 'label' => 'Integratie'],
                            ],
                        ],
                    ],
                    'submitLabel'      => 'Verzoek indienen',
                    'successMessage'   => 'Uw verzoek is ingediend',
                ],
                [
                    'id'       => 'createComplaint',
                    'type'     => 'create',
                    'label'    => 'File a complaint',
                    'register' => self::REGISTER,
                    'schema'   => 'complaint',
                    'fields'   => [
                        'title',
                        'description',
                        'category',
                    ],
                ],
            ],
            // Contribution-manifest-v3 page composition: one screen per surface,
            // each block resolved within this contribution. The requests page
            // leads with a short intro, the intake form, then the scoped table.
            'pages'         => [
                [
                    'id'     => 'requests',
                    'label'  => 'Verzoeken',
                    'icon'   => 'MessageText',
                    'blocks' => [
                        [
                            'type'     => 'richText',
                            'markdown' => '## Mijn verzoeken'."\n".'Dien een nieuw verzoek in of bekijk de status van uw lopende verzoeken.',
                        ],
                        ['type' => 'action', 'action' => 'createRequest'],
                        ['type' => 'collection', 'collection' => 'clientRequests'],
                    ],
                ],
                [
                    'id'     => 'complaints',
                    'label'  => 'Klachten',
                    'icon'   => 'AlertCircle',
                    'blocks' => [
                        ['type' => 'action', 'action' => 'createComplaint'],
                        ['type' => 'collection', 'collection' => 'clientComplaints'],
                    ],
                ],
                [
                    'id'     => 'contracts',
                    'label'  => 'Contracten',
                    'icon'   => 'FileDocument',
                    'blocks' => [
                        ['type' => 'collection', 'collection' => 'clientContracts'],
                    ],
                ],
                [
                    'id'     => 'contactmoments',
                    'label'  => 'Contactmomenten',
                    'icon'   => 'Phone',
                    'blocks' => [
                        ['type' => 'collection', 'collection' => 'clientContactmoments'],
                    ],
                ],
            ],
            'notifications' => [],
        ];
    }//end clientContribution()

    /**
     * Manifest for the `customer` audience (B2C).
     *
     * The `avgVerzoek` DSAR self-service collection + intake action were removed
     * by consume-or-dsar (ADR-047 Phase 3) — DSAR moved to OpenRegister.
     * `klantLoyaltyAccount` is scoped by `klantId`
     * via `claims.pipelinq.customerUid` (Nextcloud contact UID — a DIFFERENT
     * identifier space than contactId, see design.md). `booking` is scoped by
     * `customerId` (also a Nextcloud addressbook contact ref → `customerUid`)
     * and ships field-projected + `minTrust` `substantial`, because its
     * customer-facing `notes` may carry special-category data (the schema's own
     * example is allergies); staff-only `internalNotes`, the audit
     * `statusHistory`, `resourceAssignments`, cancellation actor and provenance
     * fields are dropped. The `berichtenboxMessage` inbox stays absent
     * (BSN-scoped). The single create action is DSAR intake with a strict
     * whitelist; lifecycle, handler, deadline and BSN verification fields are
     * server-authoritative.
     *
     * @return array<string, mixed> The customer manifest.
     *
     * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
     */
    private function customerContribution(): array
    {
        // NOTE: the citizen "My privacy requests" collection + "Submit a privacy
        // (GDPR) request" action were removed by consume-or-dsar (ADR-047 Phase
        // 3): pipelinq no longer owns the avgVerzoek schema — DSAR cases live in
        // OpenRegister's data-subject-requests register and are surfaced through
        // OpenRegister's own AVG/portal surface. Re-adding a citizen DSAR intake
        // pointed at OR's register is a portal follow-up, not part of this change.
        return [
            'label'         => 'Pipelinq',
            'collections'   => [
                [
                    'id'         => 'customerLoyalty',
                    'register'   => self::REGISTER,
                    'schema'     => 'klantLoyaltyAccount',
                    'scopeField' => 'klantId',
                    'scopeClaim' => 'customerUid',
                    'label'      => 'My loyalty account',
                    'listable'   => true,
                ],
                [
                    'id'         => 'customerBookings',
                    'register'   => self::REGISTER,
                    'schema'     => 'booking',
                    'scopeField' => 'customerId',
                    'scopeClaim' => 'customerUid',
                    'label'      => 'My appointments',
                    'listable'   => true,
                    'minTrust'   => 'substantial',
                    'fields'     => [
                        'serviceId',
                        'startAt',
                        'endAt',
                        'status',
                        'notes',
                        'depositAmount',
                        'depositPaidAt',
                    ],
                ],
            ],
            'actions'       => [],
            'notifications' => [],
        ];
    }//end customerContribution()
}//end class
