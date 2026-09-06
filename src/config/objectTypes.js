/**
 * Single source of truth for Pipelinq object types.
 *
 * Every object type the app registers in the OpenRegister-backed store lives
 * here, grouped for the admin "Register Configuration" UI. Both the store
 * bootstrap (`doInitializeStores` → `registerObjectType`) and the settings UI
 * (`Settings.vue` → `CnRegisterMapping`) derive from this list, so the two can
 * no longer drift: adding a type here is enough for it to be registered AND
 * configurable.
 *
 * `t()` is called lazily inside the exported functions (never at module load)
 * so translations are already registered by the time they run, while the
 * string literals stay visible to the l10n extractor.
 */
import { translate as t } from '@nextcloud/l10n'

const APP = 'pipelinq'

/**
 * Object-type groups, rendered as sections in the admin Register Configuration
 * UI. `registerConfigKey` is the app-config key holding the id of the register
 * the group's schemas live in (used to gate registration and by the admin UI).
 * `registerSlug` is that register's slug, used to register object types by slug
 * so the store builds slug-based API URLs (`/objects/pipelinq/posTransaction`)
 * — consistent with the manifest-driven index/detail pages.
 *
 * @return {Array<{key: string, name: string, description: string, registerConfigKey: string, registerSlug: string}>} Group definitions.
 */
export function objectTypeGroups() {
	return [
		{
			key: 'core',
			name: t(APP, 'Pipelinq Objects'),
			description: t(APP, 'Core CRM object types used by Pipelinq'),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
		{
			key: 'pos',
			name: t(APP, 'Point of Sale'),
			description: t(
				APP,
				'POS transactions, receipts, refunds, cash drawer and staff',
			),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
		{
			key: 'appointments',
			name: t(APP, 'Appointment Booking'),
			description: t(
				APP,
				'Bookable services, resources, bookings and walk-in tickets',
			),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
		{
			key: 'projects',
			name: t(APP, 'Projects'),
			description: t(
				APP,
				'Project / WBS hierarchy: projects, phases, tasks and activities',
			),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
		{
			key: 'export',
			name: t(APP, 'BI Export'),
			description: t(
				APP,
				'Data-warehouse export jobs, destinations and run history',
			),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
		{
			key: 'marketing',
			name: t(APP, 'Marketing'),
			description: t(APP, 'Marketing blasts and campaigns'),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
	]
}

/**
 * Every object type the app uses, in display order, tagged with its group key.
 *
 * @return {Array<{slug: string, group: string, label: string, description: string}>} Object type definitions.
 */
export function objectTypes() {
	return [
		// Core CRM (group: core)
		{
			slug: 'client',
			group: 'core',
			label: t(APP, 'Client'),
			description: t(APP, 'Companies and organisations'),
		},
		{
			slug: 'contact',
			group: 'core',
			label: t(APP, 'Contact'),
			description: t(APP, 'Contact persons'),
		},
		{
			slug: 'lead',
			group: 'core',
			label: t(APP, 'Lead'),
			description: t(APP, 'Sales leads'),
		},
		// Unified ticket supertype (unify-ticket-supertype): one `ticket` schema with a
		// `ticketType` discriminator (request | complaint | contactmoment) replaces the
		// former `request`, `complaint` and `contactmoment` schemas. Callers narrow by
		// passing a `ticketType` filter/value, never by a separate schema slug.
		{
			slug: 'ticket',
			group: 'core',
			label: t(APP, 'Ticket'),
			description: t(APP, 'Requests, complaints and contact moments'),
		},
		{
			slug: 'pipeline',
			group: 'core',
			label: t(APP, 'Pipeline'),
			description: t(APP, 'Pipeline stages'),
		},
		{
			slug: 'product',
			group: 'core',
			label: t(APP, 'Product'),
			description: t(APP, 'Products and services'),
		},
		{
			slug: 'productCategory',
			group: 'core',
			label: t(APP, 'Product Category'),
			description: t(APP, 'Product categories'),
		},
		{
			slug: 'leadProduct',
			group: 'core',
			label: t(APP, 'Lead Product'),
			description: t(APP, 'Product line items on leads'),
		},
		{
			slug: 'relationship',
			group: 'core',
			label: t(APP, 'Relationship'),
			description: t(APP, 'Typed relationships between contacts and clients'),
		},
		{
			slug: 'skill',
			group: 'core',
			label: t(APP, 'Skill'),
			description: t(APP, 'Skills for agent routing'),
		},
		{
			slug: 'agentProfile',
			group: 'core',
			label: t(APP, 'Agent Profile'),
			description: t(APP, 'Agent skill profiles'),
		},
		{
			slug: 'billingCategory',
			group: 'core',
			label: t(APP, 'Billing Category'),
			description: t(APP, 'Billable categories and tags'),
		},
		{
			slug: 'crmTask',
			group: 'core',
			label: t(APP, 'Task'),
			description: t(APP, 'Work items / tasks'),
		},
		// Point of Sale (group: pos)
		{
			slug: 'posTransaction',
			group: 'pos',
			label: t(APP, 'POS Transaction'),
			description: t(APP, 'Point-of-sale transactions (kassabon)'),
		},
		{
			slug: 'posTransactionLine',
			group: 'pos',
			label: t(APP, 'POS Line Item'),
			description: t(APP, 'Line items on a POS transaction'),
		},
		{
			slug: 'refundReason',
			group: 'pos',
			label: t(APP, 'Refund Reason'),
			description: t(APP, 'Reason codes for POS refunds'),
		},
		{
			slug: 'posRefund',
			group: 'pos',
			label: t(APP, 'POS Refund'),
			description: t(APP, 'Refund and return documents for POS transactions'),
		},
		{
			slug: 'posRefundLine',
			group: 'pos',
			label: t(APP, 'POS Refund Line'),
			description: t(APP, 'Returned items on a POS refund'),
		},
		{
			slug: 'receiptTemplate',
			group: 'pos',
			label: t(APP, 'Receipt Template'),
			description: t(APP, 'Customizable POS receipt templates'),
		},
		{
			slug: 'receiptPrintLog',
			group: 'pos',
			label: t(APP, 'Receipt Print Log'),
			description: t(APP, 'Audit log of printed or emailed receipts'),
		},
		{
			slug: 'cashShift',
			group: 'pos',
			label: t(APP, 'Cash Shift'),
			description: t(APP, 'POS cash-drawer shifts'),
		},
		{
			slug: 'cashDrop',
			group: 'pos',
			label: t(APP, 'Cash Drop'),
			description: t(APP, 'Cash drops and pickups during a shift'),
		},
		{
			slug: 'posCashCount',
			group: 'pos',
			label: t(APP, 'Cash Count'),
			description: t(APP, 'Cash count entries for a shift'),
		},
		{
			slug: 'cashDiff',
			group: 'pos',
			label: t(APP, 'Cash Difference'),
			description: t(APP, 'Over/short differences on cash reconciliation'),
		},
		{
			slug: 'posRole',
			group: 'pos',
			label: t(APP, 'POS Role'),
			description: t(APP, 'POS staff roles and permissions'),
		},
		{
			slug: 'posStaff',
			group: 'pos',
			label: t(APP, 'POS Staff'),
			description: t(APP, 'POS staff members with PIN access'),
		},
		{
			slug: 'posZReport',
			group: 'pos',
			label: t(APP, 'Z-Report'),
			description: t(APP, 'POS end-of-day Z-reports'),
		},
		// Appointment booking (group: appointments)
		{
			slug: 'appointmentService',
			group: 'appointments',
			label: t(APP, 'Service'),
			description: t(APP, 'Bookable services'),
		},
		{
			slug: 'appointmentResource',
			group: 'appointments',
			label: t(APP, 'Resource'),
			description: t(APP, 'Bookable resources (staff, rooms, equipment)'),
		},
		{
			slug: 'appointmentBooking',
			group: 'appointments',
			label: t(APP, 'Booking'),
			description: t(APP, 'Appointment bookings'),
		},
		{
			slug: 'walkInTicket',
			group: 'appointments',
			label: t(APP, 'Walk-in Ticket'),
			description: t(APP, 'Walk-in queue tickets'),
		},
		{
			slug: 'availabilityCache',
			group: 'appointments',
			label: t(APP, 'Availability Cache'),
			description: t(APP, 'Cached bookable-slot availability'),
		},
		// BI export (bi-export-and-data-warehouse-sink) — REQ-BIE-002.
		{
			slug: 'exportJob',
			group: 'export',
			label: t(APP, 'Export Job'),
			description: t(APP, 'BI / data-warehouse export jobs'),
		},
		{
			slug: 'exportDestination',
			group: 'export',
			label: t(APP, 'Export Destination'),
			description: t(APP, 'BI export destinations (data warehouse / BI tool)'),
		},
		{
			slug: 'exportRun',
			group: 'export',
			label: t(APP, 'Export Run'),
			description: t(APP, 'BI export run history'),
		},
		// Marketing (marketing-segmentation-and-blast)
		{
			slug: 'blast',
			group: 'marketing',
			label: t(APP, 'Blast'),
			description: t(APP, 'Marketing blasts / campaigns'),
		},
		// Campaigns and attribution (marketing-campaigns). The Campaigns index
		// and detail pages read these through the object store, so both slugs
		// must be registered or fetchCollection() throws and the page is blank.
		{
			slug: 'campaign',
			group: 'marketing',
			label: t(APP, 'Campaign'),
			description: t(
				APP,
				'Campaigns that group mailings, posts and a landing page',
			),
		},
		{
			slug: 'touchpoint',
			group: 'marketing',
			label: t(APP, 'Touchpoint'),
			description: t(APP, 'One attributable interaction with a campaign'),
		},
		// Journeys and the weekly review (marketing-integrated-campaigns). The
		// Journeys index and detail pages read these through the object store,
		// so both slugs must be registered or fetchCollection() throws and the
		// page renders blank.
		{
			slug: 'journey',
			group: 'marketing',
			label: t(APP, 'Journey'),
			description: t(APP, 'A trigger, a wait, a condition and one action'),
		},
		{
			slug: 'journeyRun',
			group: 'marketing',
			label: t(APP, 'Journey run'),
			description: t(APP, 'What a journey did for one contact'),
		},
		{
			slug: 'weeklyReview',
			group: 'marketing',
			label: t(APP, 'Weekly review'),
			description: t(APP, 'What moved last week, and what to try'),
		},
		// Outbound messaging (outbound-messaging-provider-wiring). These slugs are
		// self-fetched by the conversation section on client/contact detail and by
		// the Messaging settings page; register them so fetchCollection() resolves
		// instead of throwing "Object type X is not registered" — which otherwise
		// blanks the whole Messaging settings page and errors on every detail page.
		{
			slug: 'channelConversation',
			group: 'marketing',
			label: t(APP, 'Conversation'),
			description: t(APP, 'Messaging conversations (WhatsApp / SMS)'),
		},
		{
			slug: 'channelMessage',
			group: 'marketing',
			label: t(APP, 'Message'),
			description: t(APP, 'Outbound / inbound messages within a conversation'),
		},
		{
			slug: 'channelProvider',
			group: 'marketing',
			label: t(APP, 'Channel Provider'),
			description: t(
				APP,
				'Messaging channel providers (WhatsApp / SMS gateways)',
			),
		},
		{
			slug: 'mailTransport',
			group: 'marketing',
			label: t(APP, 'Mail Transport'),
			description: t(
				APP,
				'How a mailing leaves the instance: the mail server, a Mail account or a bulk provider',
			),
		},
		{
			slug: 'messageSendBudget',
			group: 'marketing',
			label: t(APP, 'Message Send Budget'),
			description: t(APP, 'Per-channel outbound send budgets'),
		},
		{
			slug: 'messageTemplate',
			group: 'marketing',
			label: t(APP, 'Message Template'),
			description: t(APP, 'Reusable outbound message templates'),
		},
	]
}

/**
 * Lookup of group key → group definition, for the store bootstrap.
 *
 * @return {{[key: string]: {key: string, name: string, description: string, registerConfigKey: string, registerSlug: string}}} Map of group key to group.
 */
export function objectTypeGroupsByKey() {
	return Object.fromEntries(objectTypeGroups().map((group) => [group.key, group]))
}
