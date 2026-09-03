<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  StoreGallery — browse a remote OpenRegister registry for shareable case
  configuration, and install an item into this instance.

  A custom page rather than type:"index" because store items are REMOTE
  objects. The manifest's object-backed index renderer resolves a local
  register + schema and cannot address something that lives on another
  instance.

  Discovery is NOT implemented here. This component calls /api/store/items,
  which is a thin action over OpenRegister's GenericStoreService (ADR-080
  Decision 2), so the SSRF guard, the redirect refusal, the timeouts and the
  registry token all stay server-side in the engine.

  @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
-->
<template>
	<div class="store-gallery" data-testid="store-page">
		<div class="store-gallery__header">
			<h2 class="store-gallery__title">
				{{ t('pipelinq', 'Store') }}
			</h2>
			<p class="store-gallery__intro">
				{{
					t(
						'pipelinq',
						'Install pipelines, queues, product catalogues and POS setup that other organisations have published.',
					)
				}}
			</p>
		</div>

		<div class="store-gallery__controls">
			<NcTextField
				v-model="query"
				:label="t('pipelinq', 'Search the store')"
				:disabled="offline"
				data-testid="store-search"
				@update:modelValue="onSearchInput" />

			<div
				class="store-gallery__kinds"
				role="group"
				:aria-label="t('pipelinq', 'Filter by kind')">
				<NcButton
					v-for="option in kindOptions"
					:key="option.value"
					:variant="option.value === kind ? 'primary' : 'secondary'"
					:disabled="offline"
					@click="selectKind(option.value)">
					{{ option.label }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" class="store-gallery__loading" />

		<NcNoteCard
			v-else-if="offline"
			type="info"
			data-testid="store-not-configured">
			{{
				t(
					'pipelinq',
					'No store registry is configured, so nothing was requested from the network. An administrator can connect one under Administration settings. The templates below ship with Pipelinq.',
				)
			}}
		</NcNoteCard>

		<NcNoteCard
			v-else-if="unreachable"
			type="warning"
			data-testid="store-unreachable">
			{{
				t(
					'pipelinq',
					'The store registry did not answer. The templates below ship with Pipelinq.',
				)
			}}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="cards.length === 0"
			:name="t('pipelinq', 'Nothing matches that search')"
			:description="
				t('pipelinq', 'Try a different term, or clear the kind filter.')
			" />

		<ul
			v-if="!loading && cards.length > 0"
			class="store-gallery__grid"
			data-testid="store-results">
			<li v-for="card in cards" :key="card.slug" class="store-gallery__card">
				<h3 class="store-gallery__card-title">
					{{ card.title || card.slug }}
				</h3>
				<p v-if="card.kind" class="store-gallery__card-kind">
					{{ card.kind }}
				</p>
				<p class="store-gallery__card-description">
					{{ card.description }}
				</p>
				<div class="store-gallery__card-footer">
					<span v-if="card.version" class="store-gallery__card-version">
						{{ card.version }}
					</span>
					<NcButton
						v-if="canInstall"
						variant="primary"
						:disabled="installing === card.slug"
						@click="install(card)">
						{{
							installing === card.slug
								? t('pipelinq', 'Installing…')
								: t('pipelinq', 'Install')
						}}
					</NcButton>
				</div>
			</li>
		</ul>

		<div v-if="!loading && builtIn.length > 0" class="store-gallery__builtin">
			<h3 class="store-gallery__builtin-title">
				{{ t('pipelinq', 'Included with Pipelinq') }}
			</h3>
			<ul class="store-gallery__grid" data-testid="store-builtin">
				<li
					v-for="item in builtIn"
					:key="item.slug"
					class="store-gallery__card">
					<h4 class="store-gallery__card-title">
						{{ item.title }}
					</h4>
					<p class="store-gallery__card-description">
						{{ item.description }}
					</p>
				</li>
			</ul>
		</div>

		<NcNoteCard
			v-if="report"
			:type="report.type"
			data-testid="store-install-report">
			{{ report.message }}
		</NcNoteCard>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'

/**
 * The kinds a pipelinq store item can declare (ADR-080 Decision 5). A `kind`
 * is what installing the item DOES, which is what lets one registry serve
 * several apps from one schema. These name commercial configuration, so a
 * registry serving both pipelinq and dossiq keeps a case type out of a CRM
 * and a pipeline out of a case system without either app filtering by app id.
 *
 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
 */
const KINDS = [
	'pipeline',
	'queue-routing',
	'catalogue',
	'pos-setup',
	'loyalty-programme',
]

export default {
	name: 'StoreGallery',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			outcome: null,
			cards: [],
			query: '',
			kind: '',
			installing: null,
			report: null,
			searchTimer: null,
		}
	},

	computed: {
		/**
		 * No registry configured. The engine reported this WITHOUT making a
		 * network call, which is the ADR-080 Decision 4 fallback.
		 *
		 * @return {boolean} True when no registry is connected.
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		offline() {
			return this.outcome === 'not_configured'
		},

		/**
		 * The registry is configured but did not answer usefully.
		 *
		 * @return {boolean} True when the registry errored.
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		unreachable() {
			return (
				this.outcome === 'store_unreachable'
				|| this.outcome === 'store_invalid_response'
			)
		},

		/**
		 * Only an administrator may install: the components written are the
		 * shape of the work every handler then operates against. The server
		 * enforces this too; hiding the button keeps a non-admin from
		 * discovering it as a 403.
		 *
		 * @return {boolean} True when the current user is an administrator.
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		canInstall() {
			return getCurrentUser()?.isAdmin === true
		},

		/**
		 * The kind quick-filters, with an "all" entry first.
		 *
		 * @return {Array<{value: string, label: string}>} The filter options.
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		kindOptions() {
			return [
				{ value: '', label: t('pipelinq', 'All kinds') },
				...KINDS.map((value) => ({ value, label: value })),
			]
		},

		/**
		 * Pipelinq's own shipped templates, shown whenever the remote list is
		 * not the primary surface. Not a network call, and not a "Store" on
		 * their own: a local-only card grid is a Templates page.
		 *
		 * @return {Array<{slug: string, title: string, description: string}>} The built-in items.
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		builtIn() {
			if (this.offline === false && this.unreachable === false) {
				return []
			}

			return [
				{
					slug: 'b2b-sales-pipeline',
					title: t('pipelinq', 'B2B sales pipeline'),
					description: t(
						'pipelinq',
						'Qualification through to close, with the stages a longer deal moves along.',
					),
				},
				{
					slug: 'service-desk-routing',
					title: t('pipelinq', 'Service desk routing'),
					description: t(
						'pipelinq',
						'Queues and skills that put an incoming request in front of someone who can answer it.',
					),
				},
				{
					slug: 'retail-pos-setup',
					title: t('pipelinq', 'Retail POS setup'),
					description: t(
						'pipelinq',
						'Tender types, refund reasons, till roles and a receipt template.',
					),
				},
			]
		},
	},

	mounted() {
		this.search()
	},

	/**
	 * Cancel a pending debounced search.
	 *
	 * @return {void}
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	beforeUnmount() {
		if (this.searchTimer !== null) {
			clearTimeout(this.searchTimer)
		}
	},

	methods: {
		t,

		/**
		 * Debounce the search so typing does not fire one remote request per
		 * keystroke against somebody else's registry.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		onSearchInput() {
			if (this.searchTimer !== null) {
				clearTimeout(this.searchTimer)
			}

			this.searchTimer = setTimeout(() => {
				this.search()
			}, 400)
		},

		/**
		 * Select a kind filter and re-search.
		 *
		 * @param {string} value The kind, or an empty string for all kinds.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		selectKind(value) {
			this.kind = value
			this.search()
		},

		/**
		 * Fetch the current page of store cards.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		async search() {
			this.loading = true
			try {
				const params = new URLSearchParams()
				if (this.query) {
					params.set('q', this.query)
				}
				if (this.kind) {
					params.set('kind', this.kind)
				}

				const suffix = params.toString() ? `?${params.toString()}` : ''
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/store/items${suffix}`),
					{
						headers: { requesttoken: window.OC?.requestToken },
					},
				)
				const body = await response.json()

				this.outcome = body.outcome ?? 'store_invalid_response'
				this.cards = Array.isArray(body.cards) ? body.cards : []
			} catch {
				this.outcome = 'store_unreachable'
				this.cards = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Install one item, and report per component.
		 *
		 * A partial install is a real outcome rather than a failure: an item
		 * carrying both configuration and records installs the configuration
		 * and names the records it refused.
		 *
		 * @param {{slug: string, title: string}} card The card to install.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
		 */
		async install(card) {
			this.installing = card.slug
			this.report = null

			try {
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/store/items/${encodeURIComponent(card.slug)}/install`,
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: window.OC?.requestToken,
						},
					},
				)
				const body = await response.json()

				if (response.ok !== true) {
					showError(
						body.message
							?? t('pipelinq', 'The item could not be installed.'),
					)
					return
				}

				const refused = (body.components ?? []).filter(
					(c) => c.status !== 'installed',
				)
				if (refused.length === 0) {
					showSuccess(t('pipelinq', 'Installed.'))
					this.report = null
					return
				}

				this.report = {
					type: body.success === true ? 'info' : 'warning',
					message: t(
						'pipelinq',
						'Some parts were not installed: {schemas}',
						{
							schemas: refused.map((c) => c.schema).join(', '),
						},
					),
				}
			} catch {
				showError(t('pipelinq', 'The item could not be installed.'))
			} finally {
				this.installing = null
			}
		},
	},
}
</script>

<style scoped>
.store-gallery {
	padding: 16px;
	/*
	 * Clear Nextcloud's app-navigation toggle, which floats at the top-left of
	 * the content area. A `type: custom` page renders bare, so nothing reserves
	 * that space the way the typed page headers do, and the h2 below zeroes its
	 * top margin -- so "Store" rendered UNDER the toggle and read as "tore".
	 *
	 * Expressed as the toggle's own footprint rather than a measured offset:
	 * `--default-clickable-area` is what Nextcloud sizes that button with, so
	 * this stays correct if the button changes size. The fallback matches
	 * NC's own default for themes that do not set the variable.
	 */
	padding-top: calc(var(--default-clickable-area, 44px) + 16px);
	max-width: 1200px;
}

.store-gallery__title {
	margin: 0 0 4px;
}

.store-gallery__intro {
	color: var(--color-text-maxcontrast);
	margin: 0 0 16px;
}

.store-gallery__controls {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.store-gallery__kinds {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.store-gallery__loading {
	margin: 32px auto;
}

.store-gallery__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 16px;
	list-style: none;
	padding: 0;
	margin: 16px 0 0;
}

.store-gallery__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.store-gallery__card-title {
	margin: 0;
	font-size: 1rem;
}

.store-gallery__card-kind {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.store-gallery__card-description {
	margin: 0;
	flex: 1;
}

.store-gallery__card-footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.store-gallery__card-version {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.store-gallery__builtin-title {
	margin: 24px 0 0;
}
</style>
