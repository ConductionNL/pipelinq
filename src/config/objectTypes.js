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
			key: 'intake',
			name: t(APP, 'Intake & Automation'),
			description: t(APP, 'Public intake forms and automation'),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
		{
			key: 'service',
			name: t(APP, 'Service & Feedback'),
			description: t(APP, 'Contact moments, complaints and customer surveys'),
			registerConfigKey: 'register',
			registerSlug: 'pipelinq',
		},
		{
			key: 'pos',
			name: t(APP, 'Point of Sale'),
			description: t(APP, 'POS transactions, receipts and refunds'),
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
		{ slug: 'client', group: 'core', label: t(APP, 'Client'), description: t(APP, 'Companies and organisations') },
		{ slug: 'contact', group: 'core', label: t(APP, 'Contact'), description: t(APP, 'Contact persons') },
		{ slug: 'lead', group: 'core', label: t(APP, 'Lead'), description: t(APP, 'Sales leads') },
		{ slug: 'request', group: 'core', label: t(APP, 'Request'), description: t(APP, 'Customer requests') },
		{ slug: 'pipeline', group: 'core', label: t(APP, 'Pipeline'), description: t(APP, 'Pipeline stages') },
		{ slug: 'product', group: 'core', label: t(APP, 'Product'), description: t(APP, 'Products and services') },
		{ slug: 'productCategory', group: 'core', label: t(APP, 'Product Category'), description: t(APP, 'Product categories') },
		{ slug: 'leadProduct', group: 'core', label: t(APP, 'Lead Product'), description: t(APP, 'Product line items on leads') },
		{ slug: 'relationship', group: 'core', label: t(APP, 'Relationship'), description: t(APP, 'Typed relationships between contacts and clients') },
		{ slug: 'queue', group: 'core', label: t(APP, 'Queue'), description: t(APP, 'Work queues for routing') },
		{ slug: 'skill', group: 'core', label: t(APP, 'Skill'), description: t(APP, 'Skills for agent routing') },
		{ slug: 'agentProfile', group: 'core', label: t(APP, 'Agent Profile'), description: t(APP, 'Agent skill profiles') },
		// Intake & Automation (group: intake)
		{ slug: 'intakeForm', group: 'intake', label: t(APP, 'Intake Form'), description: t(APP, 'Public intake forms for capturing leads') },
		{ slug: 'intakeSubmission', group: 'intake', label: t(APP, 'Intake Submission'), description: t(APP, 'Submissions received through intake forms') },
		{ slug: 'automation', group: 'intake', label: t(APP, 'Automation'), description: t(APP, 'Automation rules') },
		{ slug: 'automationLog', group: 'intake', label: t(APP, 'Automation Log'), description: t(APP, 'Automation execution logs') },
		// Service & Feedback (group: service)
		{ slug: 'contactmoment', group: 'service', label: t(APP, 'Contact Moment'), description: t(APP, 'Registered interactions with a client') },
		{ slug: 'complaint', group: 'service', label: t(APP, 'Complaint'), description: t(APP, 'Customer complaints for tracking and resolution') },
		{ slug: 'survey', group: 'service', label: t(APP, 'Survey'), description: t(APP, 'Customer satisfaction (KTO) survey definitions') },
		{ slug: 'surveyResponse', group: 'service', label: t(APP, 'Survey Response'), description: t(APP, 'Completed survey responses') },
		// Point of Sale (group: pos)
		{ slug: 'posTransaction', group: 'pos', label: t(APP, 'POS Transaction'), description: t(APP, 'Point-of-sale transactions (kassabon)') },
		{ slug: 'posTransactionLine', group: 'pos', label: t(APP, 'POS Line Item'), description: t(APP, 'Line items on a POS transaction') },
		{ slug: 'refundReason', group: 'pos', label: t(APP, 'Refund Reason'), description: t(APP, 'Reason codes for POS refunds') },
		{ slug: 'posRefund', group: 'pos', label: t(APP, 'POS Refund'), description: t(APP, 'Refund and return documents for POS transactions') },
		{ slug: 'posRefundLine', group: 'pos', label: t(APP, 'POS Refund Line'), description: t(APP, 'Returned items on a POS refund') },
		{ slug: 'receiptTemplate', group: 'pos', label: t(APP, 'Receipt Template'), description: t(APP, 'Customizable POS receipt templates') },
		{ slug: 'receiptPrintLog', group: 'pos', label: t(APP, 'Receipt Print Log'), description: t(APP, 'Audit log of printed or emailed receipts') },
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
