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
 * degrades to identifiers-only): the client `ticket` collections and `booking`
 * (customer) ship with explicit `fields` whitelists that drop every staff-only/
 * internal property — assignee identity + CTI call internals on the contactmoment
 * collection; `internalNotes`, the audit `statusHistory` and resource assignments
 * on booking. The `berichtenboxMessage` inbox stays excluded (BSN-scoped, not
 * contact/customer-scoped). Rationale + whitelist tables:
 * openspec/changes/portal-projected-collections/design.md.
 *
 * The former `request`, `complaint` and `contactmoment` schemas were unified into
 * the single `ticket` supertype discriminated by `ticketType`. Each client
 * collection therefore declares a narrowing `filter` on `ticketType`, and each
 * create action declares a `defaults` map stamping `ticketType` server-side (it is
 * `required` on the schema and is never client-editable). Because `ticket` is a
 * supertype it also carries the OTHER kinds' properties — the per-collection
 * `fields` whitelist below is what stops them leaking across kinds.
 *
 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
 */
class PortalContributionProvider {
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
	 * organisation contacts (`client`), B2C customers (`customer`) and
	 * residents (`citizen`), who ask about their Woo dossier (hydra
	 * woo-citizen-journey C4; DigiD currently maps to `client`, so the
	 * question surface is offered to both).
	 *
	 * @return array<int, string> The audience identifiers.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function getAudiences(): array {
		return ['client', 'customer', 'citizen'];
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
	public function getAudience(): string {
		return 'client';
	}//end getAudience()

	/**
	 * Build the declarative portal manifest for one resolved subject.
	 *
	 * The subject array is server-derived by portaliq (subjectRef UUID,
	 * audience, organisation, trust level low|substantial|high). Returns null
	 * for any audience pipelinq does not serve (fail-closed; the registry
	 * already filters by audience, but a provider must not rely on that).
	 * The question actions of the Woo journey are endpoint actions: portaliq
	 * forwards them with a signed assertion that PortalQuestionController
	 * verifies before it checks who owns the dossier.
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<string, mixed>|null The manifest, or null when not contributing.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	public function getContribution(array $subject): ?array {
		$audience = $subject['audience'] ?? '';

		if ($audience === 'client') {
			return $this->clientContribution();
		}

		if ($audience === 'customer') {
			return $this->customerContribution();
		}

		if ($audience === 'citizen') {
			return $this->citizenContribution();
		}

		return null;
	}//end getContribution()

	/**
	 * Manifest for the `client` audience (B2B organisation contact).
	 *
	 * Read surfaces are org-scoped: the subject's `claims.pipelinq.clientId`
	 * (the pipelinq `client` object UUID) matches `ticket.client` and
	 * `contract.clientRef`.
	 *
	 * Requests, complaints and contactmomenten are all rows of the one `ticket`
	 * supertype, so each collection additionally declares a narrowing `filter` on
	 * the `ticketType` discriminator. The filter is merged into the OR query
	 * BEFORE the scope filter, so it can only ever subset the subject's own rows —
	 * it can never widen them. Without it, all three kinds would list together.
	 *
	 * The contactmoment collection ships field-projected — only `title`, `channel`,
	 * `outcome` and `occurredAt` — so the internal `notes`, raw `channelMetadata`,
	 * `duration`, assignee identity and CTI call internals (recording URL,
	 * disposition notes) never reach the client. Create actions whitelist intake
	 * fields only; status, assignment, pipeline/queue and SLA fields stay
	 * back-office-only, and `ticketType` is stamped server-side via `defaults`
	 * rather than accepted from the client.
	 *
	 * @return array<string, mixed> The client manifest.
	 *
	 * @spec openspec/changes/portal-contribution/specs/portal-contribution/spec.md
	 */
	private function clientContribution(): array {
		return [
			'label' => 'Pipelinq',
			'collections' => [
				[
					'id' => 'clientRequests',
					'register' => self::REGISTER,
					'schema' => 'ticket',
					// Narrowing filter on the supertype discriminator: only the
					// subject's REQUEST tickets. Merged before the scope filter, so
					// it can only subset the subject's own rows, never widen them.
					'filter' => ['ticketType' => 'request'],
					'scopeField' => 'client',
					'scopeClaim' => 'clientId',
					'label' => 'My requests',
					'listable' => true,
					// Read-side field projection (the DATA authority): only these
					// client-safe fields (+ identifiers) leave the server. The
					// back-office `assignee`, `pipeline`, `stage`, `contact` and
					// `priority` fields are dropped here — columns alone are
					// presentation-only and would NOT stop them being returned.
					// `ticket` is a supertype, so it also carries the complaint and
					// contactmoment properties; this whitelist is what keeps them
					// out of the request surface.
					// `customerMessage` is the handler's message to the customer
					// (pipelinq#2074); the internal `notes` stay behind.
					'fields' => [
						'title',
						'category',
						'status',
						'description',
						'occurredAt',
						'customerMessage',
					],
					// Contribution-manifest-v3 UI (ADR-063), presentation-only:
					// a column set, a detail layout, and a newest-first sort,
					// over the projected fields above.
					'columns' => [
						['field' => 'title', 'label' => 'Onderwerp'],
						['field' => 'category', 'label' => 'Categorie'],
						['field' => 'status', 'label' => 'Status', 'render' => 'badge'],
						['field' => 'occurredAt', 'label' => 'Ingediend', 'render' => 'date'],
					],
					'detail' => ['layout' => 'card', 'fields' => ['title', 'category', 'status', 'description', 'occurredAt', 'customerMessage']],
					'defaultSort' => ['field' => 'occurredAt', 'direction' => 'desc'],
				],
				[
					'id' => 'clientComplaints',
					'register' => self::REGISTER,
					'schema' => 'ticket',
					// Only the subject's COMPLAINT tickets (see clientRequests).
					'filter' => ['ticketType' => 'complaint'],
					'scopeField' => 'client',
					'scopeClaim' => 'clientId',
					'label' => 'My complaints',
					'listable' => true,
					// This collection was UNPROJECTED while it read the narrow
					// `complaint` schema. `ticket` is a supertype whose property set
					// is a strict superset of it, so leaving it unprojected would
					// newly hand the client the contactmoment CTI internals
					// (recording URL, disposition notes, caller numbers), the
					// internal `notes` and the back-office assignee/pipeline/stage
					// fields. The whitelist below restores the pre-unification
					// surface: complaint facts only.
					'fields' => [
						'title',
						'complaintCategory',
						'status',
						'description',
						'occurredAt',
						'customerMessage',
					],
				],
				[
					'id' => 'clientContracts',
					'register' => self::REGISTER,
					'schema' => 'salesContract',
					'scopeField' => 'clientRef',
					'scopeClaim' => 'clientId',
					'label' => 'My contracts',
					'listable' => true,
				],
				[
					'id' => 'clientContactmoments',
					'register' => self::REGISTER,
					'schema' => 'ticket',
					// Only the subject's CONTACTMOMENT tickets (see clientRequests).
					'filter' => ['ticketType' => 'interaction'],
					'scopeField' => 'client',
					'scopeClaim' => 'clientId',
					'label' => 'My contact history',
					'listable' => true,
					// Client-safe interaction facts only. The internal `notes`, raw
					// `channelMetadata`, `duration`, `assignee` identity, the
					// `parentTicket` backlink and the CTI call internals (recording
					// URL, disposition notes) all stay server-side.
					'fields' => [
						'title',
						'channel',
						'outcome',
						'occurredAt',
					],
				],
				$this->questionsCollection(),
			],
			'actions' => [
				[
					'id' => 'createRequest',
					'type' => 'create',
					'label' => 'Submit a request',
					'register' => self::REGISTER,
					'schema' => 'ticket',
					// Stamped server-side over the whitelisted client payload, so a
					// client can never file a complaint or contactmoment through the
					// request form. `ticketType` is required on the schema and is
					// deliberately NOT a whitelisted field.
					'defaults' => ['ticketType' => 'request'],
					// The owner stamp: portaliq writes the subject's own
					// `clientId` claim into `client`, the field `clientRequests`
					// is scoped on. Without it the ticket was saved with no
					// client and never showed in the resident's own list; a
					// subject without the claim is now refused instead.
					'scopeField' => 'client',
					'scopeClaim' => 'clientId',
					'fields' => [
						'title',
						'description',
						'category',
					],
					// Contribution-manifest-v3 form config. `fieldConfigs` may only
					// describe whitelisted fields; the category dropdown is a
					// static enum here (a scoped `collection` provider would be
					// used for a live-OR-backed picker).
					'fieldConfigs' => [
						'title' => [
							'label' => 'Onderwerp',
							'required' => true,
							'size' => 'large',
							'placeholder' => 'Waar gaat uw verzoek over?',
						],
						'description' => [
							'label' => 'Omschrijving',
							'required' => true,
							'size' => 'full',
							'placeholder' => 'Beschrijf uw verzoek zo volledig mogelijk',
						],
						'category' => ['label' => 'Categorie'],
					],
					'optionsProviders' => [
						'category' => [
							'type' => 'static',
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
					'submitLabel' => 'Verzoek indienen',
					'successMessage' => 'Uw verzoek is ingediend',
				],
				[
					'id' => 'createComplaint',
					'type' => 'create',
					'label' => 'File a complaint',
					'register' => self::REGISTER,
					'schema' => 'ticket',
					// Stamped server-side (see createRequest).
					'defaults' => ['ticketType' => 'complaint'],
					// The owner stamp `clientComplaints` reads (see createRequest).
					'scopeField' => 'client',
					'scopeClaim' => 'clientId',
					// The complaint's classification is `complaintCategory` on the
					// unified ticket — the supertype's plain `category` is the
					// REQUEST's free-text category and is not part of this intake.
					'fields' => [
						'title',
						'description',
						'complaintCategory',
					],
				],
				...$this->questionActions(),
			],
			// Contribution-manifest-v3 page composition: one screen per surface,
			// each block resolved within this contribution. The requests page
			// leads with a short intro, the intake form, then the scoped table.
			'pages' => [
				[
					'id' => 'requests',
					'label' => 'Verzoeken',
					'icon' => 'MessageText',
					'blocks' => [
						[
							'type' => 'richText',
							'markdown' => '## Mijn verzoeken' . "\n" . 'Dien een nieuw verzoek in of bekijk de status van uw lopende verzoeken.',
						],
						['type' => 'action', 'action' => 'createRequest'],
						['type' => 'collection', 'collection' => 'clientRequests'],
					],
				],
				[
					'id' => 'complaints',
					'label' => 'Klachten',
					'icon' => 'AlertCircle',
					'blocks' => [
						['type' => 'action', 'action' => 'createComplaint'],
						['type' => 'collection', 'collection' => 'clientComplaints'],
					],
				],
				[
					'id' => 'contracts',
					'label' => 'Contracten',
					'icon' => 'FileDocument',
					'blocks' => [
						['type' => 'collection', 'collection' => 'clientContracts'],
					],
				],
				[
					'id' => 'contactmoments',
					'label' => 'Contactmomenten',
					'icon' => 'Phone',
					'blocks' => [
						['type' => 'collection', 'collection' => 'clientContactmoments'],
					],
				],
				$this->questionsPage(),
			],
			'notifications' => [$this->answeredRule()],
		];
	}//end clientContribution()

	/**
	 * Manifest for the `customer` audience (B2C).
	 *
	 * The `avgVerzoek` DSAR self-service collection + intake action were removed
	 * by consume-or-dsar (ADR-047 Phase 3) — DSAR moved to OpenRegister.
	 * `customerLoyaltyAccount` is scoped by `customerId`
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
	private function customerContribution(): array {
		// NOTE: the citizen "My privacy requests" collection + "Submit a privacy
		// (GDPR) request" action were removed by consume-or-dsar (ADR-047 Phase
		// 3): pipelinq no longer owns the avgVerzoek schema — DSAR cases live in
		// OpenRegister's data-subject-requests register and are surfaced through
		// OpenRegister's own AVG/portal surface. Re-adding a citizen DSAR intake
		// pointed at OR's register is a portal follow-up, not part of this change.
		return [
			'label' => 'Pipelinq',
			'collections' => [
				[
					'id' => 'customerLoyalty',
					'register' => self::REGISTER,
					'schema' => 'customerLoyaltyAccount',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerUid',
					'label' => 'My loyalty account',
					'listable' => true,
				],
				[
					'id' => 'customerBookings',
					'register' => self::REGISTER,
					'schema' => 'appointmentBooking',
					'scopeField' => 'customerId',
					'scopeClaim' => 'customerUid',
					'label' => 'My appointments',
					'listable' => true,
					'minTrust' => 'substantial',
					'fields' => [
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
			'actions' => [],
			'notifications' => [],
		];
	}//end customerContribution()

	/**
	 * Manifest for the `citizen` audience (a resident on the municipal portal).
	 *
	 * Only the question surface of the Woo citizen journey (hydra
	 * woo-citizen-journey C4): the resident asks about their own dossier,
	 * reads their questions with the answers, and replies. Everything is scoped
	 * by the subject reference stored on the ticket (`portalSubject`), so a
	 * resident only ever reads their own questions.
	 *
	 * @return array<string, mixed> The citizen manifest.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-questions-and-the-answers-req-qcd-003
	 */
	private function citizenContribution(): array {
		return [
			'label' => 'Pipelinq',
			'collections' => [$this->questionsCollection()],
			'actions' => $this->questionActions(),
			'pages' => [$this->questionsPage()],
			'notifications' => [$this->answeredRule()],
		];
	}//end citizenContribution()

	/**
	 * The resident's own questions about a dossier, with the answers.
	 *
	 * Default subject scoping on `portalSubject`: portaliq matches it against
	 * the resident's subject reference, and its change-rule listener uses the
	 * same field to find whom to tell about an answer. The internal `notes`,
	 * assignee and pipeline fields stay behind.
	 *
	 * @return array<string, mixed> The collection declaration.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-questions-and-the-answers-req-qcd-003
	 */
	private function questionsCollection(): array {
		return [
			'id' => 'myQuestions',
			'register' => self::REGISTER,
			'schema' => 'ticket',
			// A question is a request ticket filed through the portal; the
			// scope field below narrows it to the resident's own.
			'filter' => ['ticketType' => 'request', 'channel' => 'portal'],
			'scopeField' => 'portalSubject',
			'label' => 'My questions',
			'listable' => true,
			'fields' => [
				'title',
				'description',
				'status',
				'occurredAt',
				'customerMessage',
				'portalReplies',
				'subjectReference',
			],
			'columns' => [
				['field' => 'title', 'label' => 'Onderwerp'],
				['field' => 'status', 'label' => 'Status', 'render' => 'badge'],
				['field' => 'occurredAt', 'label' => 'Gesteld', 'render' => 'date'],
			],
			'detail' => ['layout' => 'card', 'fields' => ['title', 'description', 'status', 'occurredAt', 'customerMessage', 'portalReplies']],
			'defaultSort' => ['field' => 'occurredAt', 'direction' => 'desc'],
		];
	}//end questionsCollection()

	/**
	 * The two question actions, both endpoint actions portaliq forwards.
	 *
	 * `askAboutDossier` is offered only when opencatalogi, which holds the
	 * dossier, is installed. `replyToQuestion` stays, so a resident can still
	 * answer on a question asked earlier.
	 *
	 * @return array<int, array<string, mixed>> The action declarations.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-replies-to-an-answer-req-qcd-004
	 */
	private function questionActions(): array {
		$actions = [];
		if ($this->isOpenCatalogiInstalled() === true) {
			$actions[] = [
				'id' => 'askAboutDossier',
				'label' => 'Ask a question about this dossier',
				'endpoint' => '/index.php/apps/pipelinq/api/portal/questions',
				'method' => 'POST',
				// Shown on the resident's dossier page (hydra woo-citizen-journey
				// C7): portaliq proves the dossier is the resident's through
				// opencatalogi's own scope and forwards its id as `collectionId`.
				// The receiver checks the owner again.
				'attachTo' => ['app' => 'opencatalogi', 'schema' => 'collection'],
				'rowField' => 'collectionId',
				'fields' => ['question', 'title'],
				'fieldConfigs' => [
					'title' => ['label' => 'Onderwerp', 'size' => 'large', 'placeholder' => 'Waar gaat uw vraag over?'],
					'question' => [
						'label' => 'Uw vraag',
						'required' => true,
						'size' => 'full',
						'help' => 'De medewerker ziet welke documenten in uw dossier staan. Uw aantekeningen ziet de medewerker niet.',
					],
				],
				'submitLabel' => 'Vraag versturen',
				'successMessage' => 'Uw vraag is verstuurd. U krijgt bericht als er een antwoord is.',
			];
		}

		$actions[] = [
			'id' => 'replyToQuestion',
			'label' => 'Reply to the answer',
			'endpoint' => '/index.php/apps/pipelinq/api/portal/questions/reply',
			'method' => 'POST',
			'fields' => ['ticket', 'message'],
			'fieldConfigs' => [
				'ticket' => ['visible' => false, 'required' => true],
				'message' => ['label' => 'Uw reactie', 'required' => true, 'size' => 'full'],
			],
			'submitLabel' => 'Reactie versturen',
			'successMessage' => 'Uw reactie is verstuurd.',
		];

		return $actions;
	}//end questionActions()

	/**
	 * The portal page that lists the resident's questions.
	 *
	 * @return array<string, mixed> The page declaration.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-reads-their-questions-and-the-answers-req-qcd-003
	 */
	private function questionsPage(): array {
		return [
			'id' => 'questions',
			'label' => 'Vragen',
			'icon' => 'CommentQuestion',
			'blocks' => [
				[
					'type' => 'richText',
					'markdown' => '## Mijn vragen'."\n".'Hier leest u de antwoorden op vragen die u over uw dossiers stelde.',
				],
				['type' => 'collection', 'collection' => 'myQuestions'],
			],
		];
	}//end questionsPage()

	/**
	 * The change rule that tells a resident there is an answer (C3 sender).
	 *
	 * The change-rule listener of portaliq compares `customerMessage` on every
	 * update of a `myQuestions` ticket. When it changed, portaliq writes the
	 * inbox message to the resident in `portalSubject` and dispatches this
	 * key, so their preferences decide on email and Berichtenbox. pipelinq
	 * sends nothing itself; without portaliq nobody listens and the answer is
	 * saved as usual.
	 *
	 * @return array<string, mixed> The rule.
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-resident-hears-that-there-is-an-answer-req-qcd-005
	 */
	private function answeredRule(): array {
		return [
			'ruleKey' => 'pipelinq.question.answered',
			'collection' => 'myQuestions',
			'on' => ['field' => 'customerMessage', 'operator' => 'changed'],
			'titleField' => 'title',
		];
	}//end answeredRule()

	/**
	 * Whether opencatalogi, which holds the resident's dossiers, is installed.
	 *
	 * Duck-typed: an enabled app's classes autoload, a missing or disabled one's
	 * do not. Protected so a test can answer it both ways.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-a-resident-asks-a-question-about-a-dossier-they-own-req-qcd-001
	 */
	protected function isOpenCatalogiInstalled(): bool {
		return class_exists('OCA\\OpenCatalogi\\AppInfo\\Application');
	}//end isOpenCatalogiInstalled()
}//end class
