// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// V2 component registry for pipelinq.
//
// Every entry here corresponds to a manifest `type: "custom"` page, a
// dashboard widget rendered via a `slots` mapping, or a
// `headerComponent` / `actionsComponent` override on a typed page.
//
// Recognised kinds: page, modal, widget, form-field, cell-renderer
//
// Resolution order (v2 renderer):
//   1. Built-in page types   (CnIndexPage, CnDetailPage, CnDashboardPage, …)
//   2. Built-in widget types (version-info, register-mapping, …)
//   3. registry (this file)  ← consumer-injected components
//   4. customComponents      ← v1 fallback, kept during transition
//
// See:
//   - openspec/changes/pipelinq-manifest-v1/design.md
//   - hydra/openspec/architecture/adr-036-manifest-v2.md

// --- Shillinq time-intake billing handoff (time-billing-handoff-emit): the
//     real emit side of the delegated time-approval-workflow. Self-fetches
//     its availability endpoint and falls back to the existing Shillinq
//     deep-link — same self-fetching-by-props pattern as the sections above. ---
// BookingDetail is now a declarative type:"detail" page (pipelinq-pos-mdm-detail-
// declarative); its array-on-object tables + computed timeline + notes editor
// stay in the page body via this kind:'section', and its TIME-WINDOW-gated admin
// actions are the page's actionsComponent.
import BookingDetailSection from './components/bookings/BookingDetailSection.vue'
import BookingHeaderActions from './components/bookings/BookingHeaderActions.vue'
import BookingsCard from './components/bookings/BookingsCard.vue'
import BrpContactPanel from './components/BrpContactPanel.vue'
// --- Client / Contact 360 detail sub-features (pipelinq-client-contact-detail-
//     declarative). The ClientDetail / ContactDetail monolithic page-host views
//     are gone — the pages are declarative type:"detail" manifest entries whose
//     identity/account fields auto-render in the body, KPI chips come from
//     `summaryAggregates`, related lists from `relatedCollections`, the parent-
//     org link from `relationLinks`, and these rich sub-features stay in the
//     page body via `bodyWidgets` (kind:'section'). Each reads the live object
//     via props (token-resolved `@objectId`) — no page host needed. ---
import ContactmomentQuickLog from './components/ContactmomentQuickLog.vue'
import DossierSnapshotSection from './components/DossierSnapshotSection.vue'
import LogVisitAction from './components/LogVisitAction.vue'
import CampaignLandingPageSection from './components/marketing/CampaignLandingPageSection.vue'
import ListSignupFormSection from './components/marketing/ListSignupFormSection.vue'
import ListSubscriberViewSection from './components/marketing/ListSubscriberViewSection.vue'
import SocialPostVariantsSection from './components/marketing/SocialPostVariantsSection.vue'
import SocialPublicationsSection from './components/marketing/SocialPublicationsSection.vue'
// --- Cash-shift detail — declarative type:"detail": the closing count is its
//     actionsComponent; the drops (with Add drop) and the latest/pending
//     cashDiff variance are grid widgets. ---
import CashShiftDropsWidget from './components/pos/CashShiftDropsWidget.vue'
import CashShiftHeaderActions from './components/pos/CashShiftHeaderActions.vue'
import CashShiftVarianceWidget from './components/pos/CashShiftVarianceWidget.vue'
// --- POS refund detail — declarative type:"detail" (pipelinq-pos-mdm-detail-
//     declarative): refund fields auto-render; the manager-gated confirm/reject
//     actions are its actionsComponent, and the cross-schema "Returned items"
//     join and the totals are grid widgets. ---
import PosRefundHeaderActions from './components/pos/PosRefundHeaderActions.vue'
import PosRefundItemsWidget from './components/pos/PosRefundItemsWidget.vue'
import PosRefundTotalsWidget from './components/pos/PosRefundTotalsWidget.vue'
// --- POS transactions. The detail page is a declarative type:"detail" page
//     (pipelinq-pos-mdm-detail-declarative). The status-gated actions are its
//     actionsComponent (bespoke /api/pos-transactions endpoints); the totals
//     and the tender / provider payment panel are grid widgets beside the
//     schema-driven data, related and line-item widgets. The form is a bespoke
//     cart editor. ---
import PosTransactionHeaderActions from './components/pos/PosTransactionHeaderActions.vue'
import PosTransactionPaymentWidget from './components/pos/PosTransactionPaymentWidget.vue'
import PosTransactionTotalsWidget from './components/pos/PosTransactionTotalsWidget.vue'
// --- POS end-of-day Z-report. The per-report page is now a declarative
//     type:"detail" page (pipelinq-detail-pages-declarative-r3): the Z-report's
//     flat fields auto-render via CnObjectDataWidget; the BTW + payment-method
//     breakdown tables (array fields on the object) and the shillinq
//     bookkeeping-status projection + manager-gated re-raise live in one
//     kind:'section' bodyWidget (ZReportBookkeepingSection). The GL journal
//     itself is owned by shillinq (pipelinq-bookkeeping-to-shillinq). ---
import ZReportBookkeepingSection from './components/pos/ZReportBookkeepingSection.vue'
import ProductSupplyWidget from './components/products/ProductSupplyWidget.vue'
// What the resident reads of a request ticket (portal-resident-view-preview). ---
import RoutingSuggestionSection from './components/RoutingSuggestionSection.vue'
// --- AVG (GDPR data-subject request) workflow removed by consume-or-dsar
//     (ADR-047 Phase 3): the data-subject request workflow is owned by
//     OpenRegister's case engine; pipelinq deep-links handlers into OR's AVG
//     surface (/apps/openregister/avg) instead of embedding its own
//     dashboard/detail/intake pages. No local AVG view components remain. ---
// --- Master Data Management (MDM) steward surfaces are no longer hosted in
//     pipelinq (ADR-045 #D). OpenRegister now owns the survivorship / dedup /
//     merge / data-quality surface, driven by the x-openregister-survivorship
//     and x-openregister-merge annotations on the masterEntity schema. The
//     app-local MDM views/sections/modals were removed and a single "Data
//     quality" nav entry deep-links to OR's Data-Quality surface instead
//     (see src/manifest.d/90-master-data-management.json). ---
// --- Contact-aware create overrides (kind:"create-override"). The
//     client/contact schemas mark `contactsUid` REQUIRED, so a plain
//     objectStore.saveObject() 400s. These handlers post the create-form to
//     POST /api/contacts-sync/create (provisions the NC addressbook contact +
//     fills the FK) and return the created object — the same path the bespoke
//     ClientCreateDialog uses. CnPageRenderer resolves a manifest
//     `config.createOverride` string to one of these and forwards it to
//     CnIndexPage's createOverride prop, so the GENERIC Add button on the
//     declarative Clients/Contacts index pages is contact-aware too. ---
// --- Form-dialog slot replacements (page.slots): the ArticleDetail edit
//     form, the new-blast wizard, and segment / template create and edit
//     (marketing-segments-ui-repair) on their index pages. ---
import ArticleDetailFormDialog from './dialogs/ArticleDetailFormDialog.vue'
import BlastWizardDialog from './dialogs/BlastWizardDialog.vue'
import CampaignFormDialog from './dialogs/CampaignFormDialog.vue'
import ClientCreateDialog from './dialogs/ClientCreateDialog.vue'
import JourneyFormDialog from './dialogs/JourneyFormDialog.vue'
import LeadCreateDialog from './dialogs/LeadCreateDialog.vue'
import RequestCreateDialog from './dialogs/RequestCreateDialog.vue'
import SegmentFormDialog from './dialogs/SegmentFormDialog.vue'
import TemplateFormDialog from './dialogs/TemplateFormDialog.vue'
import TicketAnswerDialog from './dialogs/TicketAnswerDialog.vue'
import ArticleEditModal from './modals/ArticleEditModal.vue'
import ExportTestRunModal from './modals/ExportTestRunModal.vue'
// Make a letter from a filinq template (work-letter-from-filinq-template): the
//     header action on ClientDetail and TicketDetail opens this dialog. ---
import ForecastOverrideModal from './modals/ForecastOverrideModal.vue'
import LetterFromTemplateModal from './modals/LetterFromTemplateModal.vue'
import PipelineCardFieldModal from './modals/PipelineCardFieldModal.vue'
import PipelineSettingsModal from './modals/PipelineSettingsModal.vue'
import SendMessageModal from './modals/SendMessageModal.vue'
import SocialPostComposeModal from './modals/SocialPostComposeModal.vue'
import SubscriptionManageModal from './modals/SubscriptionManageModal.vue'
import WriteEmailModal from './modals/WriteEmailModal.vue'
// --- Marketing segmentation + blast (marketing-segmentation-and-blast 07):
//     the list, the new-blast wizard (BlastWizardDialog, which embeds the
//     missing-consent modal) and the live monitor, which polls /api/blasts/:id
//     every 2s and stops on terminal status. ---
import BlastMonitorView from './views/blasts/BlastMonitor.vue'
import BlastPerformanceDashboardView from './views/blasts/PerformanceDashboard.vue'
import ResourceDetailView from './views/bookings/ResourceDetail.vue'
// --- Appointment booking — admin surface (appointment-booking 11 of 12).
//     Service / Resource / Booking list + detail views; resolved by the v2
//     renderer from the manifest.d fragment at render time. ---
import ServiceDetailView from './views/bookings/ServiceDetail.vue'
// --- Navi AI conversational analytics (openspec/changes/dashboard). ---
import NaviAnalyticsWidget from './views/dashboard/widgets/NaviAnalyticsWidget.vue'
import ExportJobFormView from './views/export/ExportJobForm.vue'
// --- BI export + data-warehouse sink (lib gap: declarative index/detail pages
//     cannot express the bespoke test-connection / test-run / enable / retry
//     actions on the export controllers, nor the run-detail manifest +
//     schema-snapshot drill-down). Object CRUD still flows through the shared
//     object store; these views add the action surface. ---
import KassakoppelingAuditDetailView from './views/kassakoppeling/KassakoppelingAuditDetail.vue'
// --- POS Kassakoppeling-compliant audit log (pos-kassakoppeling-audit):
//     append-only HMAC-SHA256 signed register actions chained per-register
//     with an admin-gated Belastingdienst export pack. Lib gap: declarative
//     type:"index" cannot express the bespoke /api/kassakoppeling/audit
//     endpoint, the verify-button + verification badge ramp on the detail
//     view, or the date-range + format export modal. ---
import KassakoppelingAuditListView from './views/kassakoppeling/KassakoppelingAuditList.vue'
// --- Search intelligence (marketing-search-intelligence, phase 5): the
//     four derivations over those same rows, the competitor watches, and
//     the follow audit. Each is a computed read, not a row list. ---
import KeywordIntelligenceView from './views/marketing/KeywordIntelligence.vue'
// Bespoke kanban board with in-memory search (REQ-PIPE-022).
// See openspec/changes/2026-03-20-pipeline/design.md.
// --- POS cash drawer. The detail page is now a declarative type:"detail" page
//     (pipelinq-pos-mdm-detail-declarative): the shift's float fields auto-render,
//     the drops are a relatedCollections table, and the variance/diff projection
//     + drop/count/reconcile actions (bespoke /api/pos-shifts endpoints) are a
//     kind:'section' bodyWidget. ---
import CashShiftListView from './views/pos/CashShiftList.vue'
import PosRefundFormView from './views/pos/PosRefundForm.vue'
import PosTransactionFormView from './views/pos/PosTransactionForm.vue'
import ProspectsView from './views/prospects/ProspectsView.vue'
import SocialAccountsView from './views/social/SocialAccountsView.vue'
import SocialPerformanceView from './views/social/SocialPerformanceView.vue'
// --- Store — REMOTE objects, which the object-backed index renderer
//     cannot address (ADR-080). ---
import WerkplekClientSelect from './views/werkplek/widgets/WerkplekClientSelect.vue'
import WerkplekHeaderActions from './views/werkplek/widgets/WerkplekHeaderActions.vue'
import ContactAwareObjectListWidget from './components/widgets/ContactAwareObjectListWidget.js'
import { sectionWidget } from './components/widgets/sectionWidget.js'
// --- KCC Werkplek (pipelinq-werkplek-declarative): unified KCC agent workspace
//     rendered as a declarative type:"dashboard" page. Requests, Tasks, the
//     active-interaction form, the summary-driven knowledge base and the client
//     overview are all widgets on the standard dashboard grid (header + actions
//     + single scroll region). One small host widget remains: the header
//     agent-availability toggle. ---
import { createClientWithContact, createWithContact } from './services/contactSyncApi.js'

/*
 * Grid metadata required for every kind:"widget" entry by the ADR-036
 * registry validator in CnAppRoot. pipelinq's dashboard positions widgets
 * via the manifest `config.layout` (GridStack), so these sizes are not
 * consumed at runtime — they exist to satisfy the validator. Sizes mirror
 * the manifest layout for coherence. `allowedSlots` uses the v2 slot
 * literals (body, sidebar, header-actions, footer, modal).
 */
const PANEL_WIDGET_META = {
	defaultSize: { w: 6, h: 4 },
	minSize: { w: 3, h: 2 },
	maxSize: { w: 12, h: 6 },
	allowedSlots: ['body'],
	propsSchema: null,
}
const HEADER_ACTIONS_META = {
	defaultSize: { w: 12, h: 1 },
	minSize: { w: 1, h: 1 },
	maxSize: { w: 12, h: 1 },
	allowedSlots: ['header-actions'],
	propsSchema: null,
}
const SIDEBAR_TAB_META = {
	defaultSize: { w: 1, h: 2 },
	minSize: { w: 1, h: 1 },
	maxSize: { w: 1, h: 6 },
	allowedSlots: ['sidebar'],
	propsSchema: null,
}

/**
 * V2 component registry.
 *
 * Keys must match the `component` strings used in the manifest.
 * All full-page custom routes are kind: "page" — the v2 renderer resolves
 * any `component` key from this registry at render time.
 *
 * @type {Record<string, { kind: string, component: object, _note?: string }>}
 */
const registry = {
	ProspectsView: {
		kind: 'page',
		component: ProspectsView,
		_note: 'Prospects page (refactor-pipelinq-ia-alignment): scored-prospect list with sortable columns + convert-to-lead action over the prospect Pinia store; lib has no declarative type for scored external-source enrichment.',
	},


	// --- Dashboard widgets (rendered as #widget-{id} slots inside
	//     CnDashboardPage via the manifest Dashboard page's `slots` map). ---
	NaviAnalyticsWidget: {
		kind: 'widget',
		component: NaviAnalyticsWidget,
		...PANEL_WIDGET_META,
		_note: 'Conversational analytics chat panel powered by NaviService — natural-language queries return CnChartWidget / CnDataTable / plain text inline, with up to 3 suggested follow-up chips. openspec/changes/dashboard REQ-DASH-001 / REQ-DASH-003.',
	},

	// --- Surveys + Forms migrated to the OpenRegister forms leaf (NC Forms). ---
	// See openspec/changes/migrate-forms-to-forms-leaf.

	// --- Pipeline board (kanban + list with in-memory search). ---



	// --- POS transactions. The PosTransactions list + detail are now declarative
	//     pages (pipelinq-declarative-pages-round1 / pipelinq-pos-mdm-detail-
	//     declarative); only the bespoke cart-editor form view, the detail's
	//     header actions and its two grid widgets stay registered. ---
	PosTransactionHeaderActions: {
		kind: 'widget',
		component: PosTransactionHeaderActions,
		...HEADER_ACTIONS_META,
		_note: 'PosTransactionDetail actionsComponent: Edit (to the cart editor) and the status-gated confirm/park/resume/settle/refund/receipt actions. They POST to bespoke /api/pos-transactions/{id}/{action} endpoints with side-effects, NOT OR /transition, and posTransaction has no x-openregister-lifecycle, so CnLifecycleActions cannot drive them. The page sets showEditAction:false because the schema form cannot edit line items.',
	},
	PosTransactionTotalsWidget: {
		kind: 'widget',
		component: PosTransactionTotalsWidget,
		...PANEL_WIDGET_META,
		_note: 'PosTransactionDetail grid widget: VAT per rate, subtotal/discount/VAT/total and the invoice split, read from the totals the server stored on the transaction (REQ-BTW-005).',
	},
	PosTransactionPaymentWidget: {
		kind: 'widget',
		component: PosTransactionPaymentWidget,
		...PANEL_WIDGET_META,
		_note: 'PosTransactionDetail grid widget: the split tenders with their balance (TenderEntryPanel) and the payment provider status (PaymentStatusCard) when a provider handled the payment. Reloads its tenders on cn:page:refresh.',
	},
	PosTransactionFormView: {
		kind: 'page',
		component: PosTransactionFormView,
		_note: 'Bespoke cart editor: inline line-item rows with product picker + real-time totals; lib has no cart/line-editor page type.',
	},

	// --- POS refunds / returns. The PosRefunds list + detail are now declarative
	//     pages (pipelinq-declarative-pages-round1 / pipelinq-pos-mdm-detail-
	//     declarative). ---
	PosRefundHeaderActions: {
		kind: 'widget',
		component: PosRefundHeaderActions,
		...HEADER_ACTIONS_META,
		_note: 'PosRefundDetail actionsComponent: manager-gated Confirm/Reject, which POST to bespoke /api/pos-refunds/{id}/{action} endpoints (posRefund has no x-openregister-lifecycle).',
	},
	PosRefundTotalsWidget: {
		kind: 'widget',
		component: PosRefundTotalsWidget,
		...PANEL_WIDGET_META,
		_note: 'PosRefundDetail grid widget: refund amount excl. VAT, VAT and total refund over the refund lines. Re-reads on cn:page:refresh.',
	},
	PosRefundItemsWidget: {
		kind: 'widget',
		component: PosRefundItemsWidget,
		...PANEL_WIDGET_META,
		_note: 'PosRefundDetail grid widget: the cross-schema "Returned items" JOIN (each posRefundLine enriched with its original posTransactionLine; an object-list renders ONE schema and cannot join). Re-reads on cn:page:refresh.',
	},
	PosRefundFormView: {
		kind: 'page',
		component: PosRefundFormView,
		_note: 'Bespoke return editor: select original lines with partial quantities, per-line reason + restock toggle and real-time refund totals; lib has no line-selection/refund page type.',
	},

	// --- POS cash drawer. ---
	CashShiftListView: {
		kind: 'page',
		component: CashShiftListView,
		_note: 'Cash-shift list; custom so rows navigate to the drawer-reconciliation detail and the empty state offers "Shift openen".',
	},
	CashShiftHeaderActions: {
		kind: 'widget',
		component: CashShiftHeaderActions,
		...HEADER_ACTIONS_META,
		_note: 'CashShiftDetail actionsComponent: Close and count shift, which POSTs to the bespoke /api/pos-shifts/{id}/count endpoint (cashShift has no x-openregister-lifecycle).',
	},
	CashShiftDropsWidget: {
		kind: 'widget',
		component: CashShiftDropsWidget,
		...PANEL_WIDGET_META,
		_note: "CashShiftDetail grid widget: the cashDrop object-list plus Add drop, which POSTs to /api/pos-shifts/{id}/drop (POS-operator, open-shift and positive-amount checks, server-set droppedBy/droppedAt) instead of the object-list's generic create.",
	},
	CashShiftVarianceWidget: {
		kind: 'widget',
		component: CashShiftVarianceWidget,
		...PANEL_WIDGET_META,
		_note: 'CashShiftDetail grid widget: the latest/pending cashDiff VARIANCE projection (an object-list lists ALL children; it cannot pick the single most-relevant diff with its tolerance verdict) + manager-gated approve/reject via /api/pos-shifts/{id}/diff/{approve|reject}. Re-reads on cn:page:refresh.',
	},

	// POS staff, POS roles and POS tender types are administrator configuration,
	// not operator surfaces, so they are no longer manifest pages: they render as
	// sections on the Nextcloud admin page (/settings/admin/pipelinq) instead:
	// index pages declared in views/settings/adminLists.js, with no registry
	// entry here.
	// (nav-ia-cleanup)

	// --- POS end-of-day bookkeeping. The Z-report list is a declarative
	//     type:"index" page and the per-report page is now a declarative
	//     type:"detail" page (pipelinq-detail-pages-declarative-r3); only this
	//     in-body section (breakdown tables + bookkeeping projection + re-raise)
	//     stays as host-app code. ---
	ZReportBookkeepingSection: {
		kind: 'section',
		component: ZReportBookkeepingSection,
		_note: 'Z-report in-body section for the declarative type:"detail" ZReportDetail page: BTW + payment-method breakdown tables (array fields on the object, not FK children) plus the shillinq bookkeeping-status projection and the manager-gated, idempotent re-raise action (POST /api/pos-bookkeeping/post). Self-fetches by @objectId so it stays in sync after a re-raise. The GL journal itself is owned by shillinq (pipelinq-bookkeeping-to-shillinq).',
	},

	// --- POS Kassakoppeling-compliant audit log (pos-kassakoppeling-audit). ---
	KassakoppelingAuditListView: {
		kind: 'page',
		component: KassakoppelingAuditListView,
		_note: 'Append-only Kassakoppeling audit log list: streams from the bespoke /api/kassakoppeling/audit endpoint (NOT the OR object store, which only stores entries), drives the date / register / operator / action filter bar and the admin-only Belastingdienst export modal (pos-kassakoppeling-audit REQ-AUDIT-003 / REQ-AUDIT-005).',
	},
	KassakoppelingAuditDetailView: {
		kind: 'page',
		component: KassakoppelingAuditDetailView,
		_note: 'Read-only Kassakoppeling audit entry detail: verification status badge ramp (green ok / red tampered / grey pending), summary + entry + crypto cards with truncated hex digests + copy buttons, an optional transaction-link card linking to pos-transaction-core and the manual server-side verify action (pos-kassakoppeling-audit REQ-AUDIT-002 / REQ-AUDIT-004 / REQ-AUDIT-006).',
	},

	// --- Client / Contact 360 detail in-body sections (kind:'section').
	//     Registered for the declarative type:"detail" ClientDetail /
	//     ContactDetail pages' `config.bodyWidgets`. Each is a self-fetching
	//     sub-feature that reads the live object via props (token-resolved
	//     `@objectId`). CnBodySections renders them as titled body sections
	//     (NOT sidebar tabs) and also `provide`s `cnSectionContext`. ---


	ListSignupFormWidget: {
		kind: 'widget',
		component: sectionWidget('ListSignupFormWidget', ListSignupFormSection, ({ objectId }) => ({ listId: objectId })),
		...PANEL_WIDGET_META,
		_note: 'The embed code of a mailing list sign-up form, posting to the public subscribe endpoint, with a copy button. Side card on MailingListDetail (PqMailinglijst).',
	},
	ListSubscriberViewWidget: {
		kind: 'widget',
		component: sectionWidget('ListSubscriberViewWidget', ListSubscriberViewSection, ({ objectId }) => ({ listId: objectId })),
		...PANEL_WIDGET_META,
		_note: 'A still preview of the preference centre a subscriber reaches from every message, with the published lists. Side card on MailingListDetail (PqMailinglijst).',
	},

	CampaignLandingPageSection: {
		kind: 'section',
		component: CampaignLandingPageSection,
		_note: 'In-body section for the declarative type:"detail" CampaignDetail page (marketing-campaigns, placement end). Hosts the Create landing page action, which dispatches portaliq\'s LandingPageRequestedEvent through POST /api/campaigns/{id}/landing-page and shows portaliq\'s own typed error code verbatim. Not a declarative action: the grammar has no cross-app command, and a generic write dialog would collapse five distinct failure codes into one save error. Self-fetches by campaignId (@objectId).',
	},

	SocialPostVariantsSection: {
		kind: 'section',
		component: SocialPostVariantsSection,
		_note: 'In-body section for the declarative type:"detail" SocialPostDetail page (social-publishing, placement before-body). Shows the RESOLVED text per network (the post body with that network\'s variant merged onto it, the same rule SocialPostService::resolveVariant() applies on the way out), and the approvals taken. NOT a declarative text widget: that widget renders a literal manifest string, not a per-network merge. Re-reads on cn:page:refresh, which the approval header actions bump after a move.',
	},

	SocialPublicationsSection: {
		kind: 'section',
		component: SocialPublicationsSection,
		_note: 'In-body section for the declarative type:"detail" SocialPostDetail page (social-publishing, placement end). One row per account the post named, with the failure reason where it did not go out. The Retry button appears only on the two of six failure codes a retry can fix, because a Retry on a dead grant or an unfiled developer application is a button that cannot work. Also hosts the share path for accounts no application may post to: the prepared text, a copy action, a link into the network\'s own composer and the confirmation.',
	},

	ProductSupplyWidget: {
		kind: 'widget',
		component: ProductSupplyWidget,
		...PANEL_WIDGET_META,
		_note: 'Supply card on ProductDetail (board PqProduct): manufacturer, unit, weight, dimensions and the Stock tracked line read live from shillinq via GET /api/products/{id}/stock (products-stock-on-hand).',
	},
	BookingsCard: {
		kind: 'section',
		component: BookingsCard,
		_note: 'Appointment-booking timeline for a customer (client); self-fetches by customerId (REQ-APT-014).',
	},
	ContactmomentQuickLog: {
		kind: 'section',
		component: ContactmomentQuickLog,
		_note: 'Inline contactmoment quick-log form pre-bound to the client (clientId, inline mode). On save it emits @saved; in declarative mode the page is refreshed via the CnDetailPage Refresh action rather than an imperative re-fetch.',
	},

	LogVisitAction: {
		kind: 'section',
		component: LogVisitAction,
		_note: 'Log a visit on a client or lead (REQ-MOB-003): one note and an optional follow-up day; writes an outbound visit contact moment (ticket) and a follow-up crmTask through the object store. Also the LeadDetail actionsComponent, where it binds to the lead and its client from the slot.',
	},

	// --- Contact page sections as grid widgets (detail-pages-read-at-a-glance).
	//     The same components as the section entries above, wrapped so they
	//     sit in the grid and in tab strips instead of a tail below the grid. ---
	ContactAwareObjectList: {
		kind: 'widget',
		component: ContactAwareObjectListWidget,
		...PANEL_WIDGET_META,
		_note: 'CnObjectListWidget whose create goes through POST /api/contacts-sync/create for client and contact, so the required contactsUid is provisioned. The Contacts tab on the client page uses it; a plain object-list POSTs straight to OpenRegister and gets 400 (round5-contact-create-and-task-links).',
	},

	// --- The ticket page's Overview sections as tab widgets (board PqTicket):
	//     the conversation, the dossier asked about, the Woo request and the case
	//     sit inside the Overview tab rather than in a tail below the tab strip. ---
	DossierSnapshotWidget: {
		kind: 'widget',
		component: sectionWidget(
			'DossierSnapshotWidget',
			DossierSnapshotSection,
			({ objectId }) => ({ objectId }),
		),
		...PANEL_WIDGET_META,
		_note: 'DossierSnapshotSection as a tab widget.',
	},

	BrpContactWidget: {
		kind: 'widget',
		component: sectionWidget(
			'BrpContactWidget',
			BrpContactPanel,
			({ objectId }) => ({
				contactId: objectId,
			}),
		),
		...PANEL_WIDGET_META,
		_note: 'BrpContactPanel as a grid widget, for the Profile tab of ContactDetail.',
	},
	BrpContactPanel: {
		kind: 'section',
		component: BrpContactPanel,
		_note: 'BSN / BRP lookup + reveal panel for a contact; self-fetches by contactId, emits @contact-updated (bsn-validatie-en-brp-lookup).',
	},


	DossierSnapshotSection: {
		kind: 'section',
		component: DossierSnapshotSection,
		_note: "The dossier a resident asked about, on TicketDetail (questions-about-a-citizen-dossier REQ-QCD-006): reads the ticket's subjectReference snapshot and lists the documents as links to the public publications. Never reads the dossier itself; renders nothing for a ticket without a snapshot.",
	},

	RoutingSuggestionWidget: {
		kind: 'widget',
		component: sectionWidget(
			'RoutingSuggestionWidget',
			RoutingSuggestionSection,
			({ objectId, objectData, content }) => ({
				objectId,
				category: objectData.category || '',
				entityType: content.entityType || 'request',
				objectType: content.objectType || 'ticket',
				assignee: objectData.assignee || '',
			}),
			{ card: true },
		),
		...PANEL_WIDGET_META,
		_note: 'RoutingSuggestionSection as a side-column card (PqLead "Voorgestelde medewerkers"): the record category and assignee come from the page object.',
	},
	RoutingSuggestionSection: {
		kind: 'section',
		component: RoutingSuggestionSection,
		_note: 'Suggested colleagues for the TicketDetail and LeadDetail pages (pipelinq#2039, pipelinq#2049): mounts RoutingSuggestionPanel for @objectId (ranked by skill match on the record category, availability and workload, capped per agent by maxConcurrent) and writes the chosen colleague into the record assignee through the object store.',
	},

	// --- BI export + data-warehouse sink. ---
	ExportJobFormView: {
		kind: 'page',
		component: ExportJobFormView,
		_note: 'Export-job create/edit form (schemas multi-select, destination, format/mode, watermark, cron, row filter, PII column allowlist) with an inline Test-run action.',
	},

	// CTI telephony, outbound WhatsApp/SMS messaging and the POS payment providers
	// are administrator configuration, so they are no longer manifest pages: they
	// render as sections on the Nextcloud admin page (/settings/admin/pipelinq),
	// which is where an admin already goes to configure an app and where NC's own
	// admin delegation applies. Settings.vue imports the views directly, so they
	// need no registry entry here. (nav-ia-cleanup)

	// --- Marketing blasts (marketing-segmentation-and-blast slice 07). The
	//     Blasts list is now a declarative type:"index" page
	//     (pipelinq-declarative-pages-round1). ---
	BlastMonitorView: {
		kind: 'page',
		component: BlastMonitorView,
		_note: 'Live blast monitor (marketing-segmentation-and-blast 07): progress bar + ETA, totals grid (queued/sent/delivered/bounced/opened/clicked/unsubscribed/complained), reverse-chronological event timeline (last 50), cancel action while sending; polls /api/blasts/:id every 2 seconds and stops on sent/failed/cancelled.',
	},
	BlastPerformanceDashboardView: {
		kind: 'page',
		component: BlastPerformanceDashboardView,
		_note: 'Post-send performance dashboard (marketing-segmentation-and-blast 08): three tabs — Overview (sortable blast table with sent/delivered/open-rate/click-rate/unsubscribed), A/B Testing (side-by-side variant comparison + chi-square p-value once each arm has >=500 delivered and 24h elapsed since send), Attribution (per-blast attributed deal count + summed EUR value from GET /api/blasts/:id/attribution).',
	},

	SocialAccountsView: {
		kind: 'page',
		component: SocialAccountsView,
		_note: "Connected social accounts, and the only place a connection is started, restarted or ended (social-publishing). Custom rather than type:index because Connect is a three-step conversation the grammar has no verb for: Pipelinq answers WHAT to connect, the BROWSER posts that to OpenRegister's own connect endpoint with the user's session, and the consent screen returns to this page's own path. The authorization code and the token never pass through Pipelinq (rule 2 of the marketing architecture, ADR-064). One component serves both SocialAccounts and SocialAccountDetail: the account has to be in the PATH because OpenRegister's safeReturnUrl() drops a query string.",
	},

	SocialPerformanceView: {
		kind: 'page',
		component: SocialPerformanceView,
		_note: 'Engagement ranking per network (social-publishing). Custom because the ranking divides engagement by the follower count the daily pull recorded onto the account, which no single-schema declarative view expresses. Renders its table shell BEFORE the one request that fills it, which is the pipelinq#1781 rule: never await a per-object fan-out before painting.',
	},

	CampaignLandingPageWidget: {
		kind: 'widget',
		component: sectionWidget('CampaignLandingPageWidget', CampaignLandingPageSection, ({ objectId, objectData }) => ({ campaignId: objectId, campaign: objectData })),
		...PANEL_WIDGET_META,
		_note: 'CampaignLandingPageSection as a card of the campaign page\'s Overview tab (PqCampagne).',
	},

	KeywordIntelligenceView: {
		kind: 'page',
		component: KeywordIntelligenceView,
		_note: 'Keyword proposals (marketing-search-intelligence): position buckets, striking-distance queries, cannibalisation findings and content gaps, all derived at read time from the searchQueryDaily rows and served by ONE GET /api/marketing/keyword-proposals. Custom because none of the four is a collection: they are computations over rows, and the keywordTarget objects the page can create are the output rather than the input. Confirming a proposal opens KeywordTargetConfirmModal, which is the only path in the product that writes a keywordTarget.',
	},

	// --- Appointment booking — admin views (appointment-booking 11 of 12). ---
	ServiceDetailView: {
		kind: 'page',
		component: ServiceDetailView,
		_note: 'Service detail + edit page with the multiStep sub-table editor and deposit / cancellation policy cards; the server expires the cached availability on save (REQ-APT-015).',
	},
	ResourceDetailView: {
		kind: 'page',
		component: ResourceDetailView,
		_note: 'Resource detail + edit page with the 7-weekday workingHours grid, vacation list, validation (open<close, startDate<=endDate) and per-resource cache invalidation on save (REQ-APT-002).',
	},
	// --- Booking detail is now a declarative type:"detail" page
	//     (pipelinq-pos-mdm-detail-declarative); the booking's flat fields
	//     auto-render and this in-body section carries what no primitive
	//     expresses: the inline notes editor, the resourceAssignments +
	//     statusHistory array-on-object tables, and the computed timeline. The
	//     six TIME-WINDOW-gated admin actions are the page's actionsComponent. ---
	BookingDetailSection: {
		kind: 'section',
		component: BookingDetailSection,
		_note: 'Booking in-body section for the declarative type:"detail" BookingDetail page. Self-fetches by @objectId and re-reads on cn:page:refresh.',
	},
	// --- Booking page parts as grid widgets (booking-and-service-pages). Each
	//     renders one part of BookingDetailSection, so the page can put them in
	//     its grid and its tab strip. The timeline is the library's `timeline`
	//     widget type (review-part-two). ---
	BookingContextWidget: {
		kind: 'widget',
		component: sectionWidget(
			'BookingContextWidget',
			BookingDetailSection,
			({ objectId }) => ({ bookingId: objectId, part: 'context' }),
			{ card: true },
		),
		...PANEL_WIDGET_META,
		_note: 'BookingDetail: the service and customer names and the deposit state, resolved across schemas.',
	},
	BookingAssignmentsWidget: {
		kind: 'widget',
		component: sectionWidget(
			'BookingAssignmentsWidget',
			BookingDetailSection,
			({ objectId }) => ({ bookingId: objectId, part: 'assignments' }),
		),
		...PANEL_WIDGET_META,
		_note: 'BookingDetail: the resourceAssignments array-on-object table, with resource names.',
	},
	BookingHeaderActions: {
		kind: 'widget',
		component: BookingHeaderActions,
		...HEADER_ACTIONS_META,
		_note: 'BookingDetail actionsComponent: the six admin actions beside Edit. lifecycleActions is intentionally NOT used even though booking has an x-openregister-lifecycle: the real transitions POST to BookingService endpoints with side-effects (confirmation/reminder emails, no-show fees) and time-window gating, and Reschedule creates a new booking UUID — OR /transition would only flip status and bypass those.',
	},

	// --- KCC Werkplek — declarative agent workspace (pipelinq-werkplek-declarative).
	//     The page is a type:"dashboard"; one host widget covers the piece that
	//     isn't pure OpenRegister data: the header agent-availability toggle. ---
	WerkplekHeaderActions: {
		kind: 'widget',
		component: WerkplekHeaderActions,
		...HEADER_ACTIONS_META,
		_note: 'Workspace header actionsComponent: agent availability toggle, hydrated from /api/kcc-werkplek/state.',
	},
	WerkplekClientSelect: {
		kind: 'widget',
		component: WerkplekClientSelect,
		...HEADER_ACTIONS_META,
		_note: 'Workspace title-meta slot: the client in focus, written to @workspace.selectedClient.',
	},

	// --- xWiki integration (xwiki-integration). ---

	// --- AVG (GDPR data-subject request) workflow migrated to OpenRegister
	//     (ADR-047 Phase 3 / consume-or-dsar): the dashboard/detail/intake views
	//     and their bespoke components were removed. OR's case engine owns the
	//     DSAR lifecycle; the AvgRequests nav entry deep-links to OR's AVG page
	//     (/apps/openregister/avg). Pipelinq contributes evidence via
	//     PipelinqEvidenceSourceProvider. ---
	// --- Master Data Management (MDM) steward surfaces migrated to OpenRegister
	//     (ADR-045 #D): the list/detail, duplicate-candidates, data-quality and
	//     sync-queue views + the golden-record/conflict/merge sections & modals
	//     are removed from pipelinq. OR hosts them, driven by the masterEntity
	//     x-openregister-survivorship / x-openregister-merge annotations; a
	//     single "Data quality" nav entry deep-links to OR's surface. ---

	// --- Flows (ADR-110 Decision 4). ---
	// The shared CnFlowIndexPage / CnFlowDetail surfaces over OpenRegister's
	// native flow store, scoped `app: "pipelinq"` so this app sees only its
	// own. Only the SIDEBAR is an app component; the list and the canvas are
	// the shared `flows` / `flow-detail` manifest page types.

	// --- Create modals (kind:"modal"), opened by a manifest header action's
	//     type:"open-modal" + target. These keep the BESPOKE create forms —
	//     a client picker that can create a client inline, and a contact
	//     picker scoped to it — which the generic schema-driven open-form
	//     dialog cannot express. Placement stays declarative; only the form
	//     body is app-owned. CnAppRoot mounts these and forwards `close`, so
	//     each dialog routes to its own detail page on success. ---
	LetterFromTemplateModal: {
		kind: 'modal',
		component: LetterFromTemplateModal,
		propsSchema: null,
		_note: 'Make a letter. Target of the make-letter header action on ClientDetail and TicketDetail; reads the record id from the route.',
	},

	PipelineCardFieldModal: {
		kind: 'modal',
		component: PipelineCardFieldModal,
		propsSchema: null,
		_note: 'Set the assignee or the priority of a Pipeline board card. Target of the Assign and Set priority card actions.',
	},

	PipelineSettingsModal: {
		kind: 'modal',
		component: PipelineSettingsModal,
		propsSchema: null,
		_note: 'The settings of the pipeline the board is narrowed to. Target of the Pipeline settings header action.',
	},

	ExportTestRunModal: {
		kind: 'modal',
		component: ExportTestRunModal,
		propsSchema: null,
		_note: 'Test run of an export job. Target of the Test run row action on the BI export jobs list.',
	},

	LeadCreateDialog: {
		kind: 'modal',
		component: LeadCreateDialog,
		propsSchema: null,
		_note: "New Lead. Target of the Sales/Operational dashboards' new-lead header action and the Leads index Add button.",
	},

	TicketAnswerDialog: {
		kind: 'modal',
		component: TicketAnswerDialog,
		propsSchema: null,
		_note: 'simple-ticket-page. Target of the ticket page Answer action in the simple structure. Saves through POST /api/tickets/{id}/answer, as the page conversation does, and asks the page to reload on close.',
	},

	SendMessageModal: {
		kind: 'modal',
		component: SendMessageModal,
		propsSchema: null,
		_note: "Send a WhatsApp or SMS message. Target of the Send message action of the messages conversation on ContactDetail (props contactId) and ClientDetail (props clientId: the modal asks which of the client's contacts).",
	},

	WriteEmailModal: {
		kind: 'modal',
		component: WriteEmailModal,
		propsSchema: null,
		_note: 'Write an email in Nextcloud Mail. Target of the Write email row action of the Emails field-list on ClientDetail and ContactDetail (props address, contactId or clientId).',
	},

	RequestCreateDialog: {
		kind: 'modal',
		component: RequestCreateDialog,
		propsSchema: null,
		_note: 'New Request (ticketType=request). Target of the Customer Support new-request header action.',
	},

	ClientCreateDialog: {
		kind: 'modal',
		component: ClientCreateDialog,
		propsSchema: null,
		_note: 'New Client, contact-first via POST /api/contacts-sync/create. Also the Clients index Add button (createModal).',
	},

	// Pages' form-dialog slot (page.slots), not header-action modals: the
	// index or detail page mounts them for its Add and Edit.
	BlastWizardDialog: {
		kind: 'modal',
		component: BlastWizardDialog,
		propsSchema: null,
		_note: "New-blast wizard in the Blasts index page's form-dialog slot: basics, audience, content, delivery, A/B test and review, gated on template validation and a consent preflight before POST /api/blasts, then opens the new blast's monitor.",
	},
	ArticleEditModal: {
		kind: 'modal',
		component: ArticleEditModal,
		propsSchema: null,
		_note: 'Create or edit an article: the markdown body and the Files hero picker. The Articles page opens it to create one, which then opens on its detail page.',
	},
	SocialPostComposeModal: {
		kind: 'modal',
		component: SocialPostComposeModal,
		propsSchema: null,
		_note: 'Write a social post: the body, a variant per network with its character count against that network\'s limit, and submit for approval. The Social posts page opens it to write one, which then opens on its detail page.',
	},
	ForecastOverrideModal: {
		kind: 'modal',
		component: ForecastOverrideModal,
		propsSchema: null,
		_note: 'Override one owner\'s committed amount for the quarter, with a reason; the Override row action of the Forecast page opens it with that row\'s period, owner, level and commit.',
	},
	SubscriptionManageModal: {
		kind: 'modal',
		component: SubscriptionManageModal,
		propsSchema: null,
		_note: 'Take a contact off one mailing list or all of them, or make a preference link; the contact page\'s Manage subscriptions header action opens it.',
	},
	ArticleDetailFormDialog: {
		kind: 'modal',
		component: ArticleDetailFormDialog,
		propsSchema: null,
		_note: "The ArticleDetail page's Edit form: its schema form without the title and body, plus a button that opens ArticleEditModal, the dedicated editor with the markdown body and Files hero picker, on top.",
	},
	SegmentFormDialog: {
		kind: 'modal',
		component: SegmentFormDialog,
		propsSchema: null,
		_note: "Segment create/edit, mounted in the Segments index page's form-dialog slot. Custom rather than the built-in form: the rule tree is edited in the library's CnConditionTreeEditor with a debounced preview call, and saving must go through POST/PATCH /api/segments, the only path that validates the rules.",
	},
	TemplateFormDialog: {
		kind: 'modal',
		component: TemplateFormDialog,
		propsSchema: null,
		_note: "CampaignTemplate create/edit, mounted in the Templates index page's form-dialog slot. Custom rather than the built-in form: the fields depend on the channel, saving must go through POST/PATCH /api/templates, which runs the compliance check, and that check's error is placed on the field it is about.",
	},
	CampaignFormDialog: {
		kind: 'modal',
		component: CampaignFormDialog,
		propsSchema: null,
		_note: "Campaign create/edit, mounted in the form-dialog slot of both the Campaigns index page and CampaignDetail. Custom rather than the built-in form: saving must go through POST/PATCH /api/campaigns, because only CampaignService mints the campaign value, freezes it across a rename and refuses a source or medium outside the tenant's vocabulary, and the source and medium pickers read GET /api/campaigns/vocabulary, admin-maintained app config that is not a schema enum.",
	},
	JourneyFormDialog: {
		kind: 'modal',
		component: JourneyFormDialog,
		propsSchema: null,
		_note: "Journey create/edit, mounted in the form-dialog slot of both the Journeys index page and JourneyDetail. Custom rather than the built-in form: every write compiles the journey into an OpenRegister flow through POST and PATCH /api/journeys, and a journey saved through the object API would be stored and never compiled, which looks exactly like a journey whose trigger has not fired. It also surfaces the flow engine's own refusal verbatim.",
	},

	// Contact-aware create for the generic Add button on the Clients index page.
	createClientContactAware: {
		kind: 'create-override',
		handler: (formData) => createClientWithContact(formData),
		_note: 'Routes a generic client create through POST /api/contacts-sync/create so the required contactsUid (FK to a NC addressbook contact) is provisioned + filled instead of 400ing on a straight OpenRegister save.',
	},

	// Contact-aware create for the generic Add button on the Contacts index page.
	createContactContactAware: {
		kind: 'create-override',
		handler: (formData) => createWithContact('contact', formData),
		_note: 'Same contact-FIRST path for the contact schema (also marks contactsUid REQUIRED).',
	},
}

export default registry
