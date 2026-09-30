<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Ticket-detail in-body section (kind:'section') for a question a resident
  - asked about their Woo dossier (questions-about-a-citizen-dossier, hydra
  - woo-citizen-journey C4). Shows the ticket's `subjectReference`: the dossier
  - title and each document as a link to the public publication.
  -
  - It is a snapshot taken when the question was asked. The employee sees what
  - the resident saw and has no live access to the dossier, so this section
  - reads the ticket only, never the dossier. Renders nothing for a ticket
  - without a snapshot.
  -->
<template>
	<section
		v-if="snapshot"
		class="dossier-snapshot-section"
		:aria-label="t('pipelinq', 'Asked about')">
		<h3 class="dossier-snapshot-section__title">
			{{
				t('pipelinq', 'Question about the dossier "{title}"', {
					title: snapshot.title,
				})
			}}
		</h3>
		<p class="dossier-snapshot-section__hint">
			{{ t('pipelinq', 'This is what the resident saw when they asked.') }}
		</p>
		<ul v-if="snapshot.items.length" class="dossier-snapshot-section__items">
			<li v-for="(item, index) in snapshot.items" :key="index">
				<a
					v-if="item.url"
					:href="item.url"
					target="_blank"
					rel="noopener noreferrer"
					>{{ item.title }}</a
				>
				<span v-else>{{ item.title }}</span>
			</li>
		</ul>
		<p v-else class="dossier-snapshot-section__hint">
			{{
				t(
					'pipelinq',
					'The dossier held no documents when the question was asked.',
				)
			}}
		</p>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'DossierSnapshotSection',

	props: {
		/** The ticket id, token-resolved from `@objectId`. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			ticket: null,
		}
	},

	computed: {
		/**
		 * The dossier snapshot in a safe shape, or null when the ticket has none.
		 *
		 * @return {object|null} `{ title, items: [{ title, url }] }`.
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-sees-what-the-resident-asked-about-req-qcd-006
		 */
		snapshot() {
			const reference = this.ticket && this.ticket.subjectReference
			if (!reference || typeof reference !== 'object') {
				return null
			}
			const items = Array.isArray(reference.items) ? reference.items : []
			return {
				title: String(reference.title || ''),
				items: items
					.filter((item) => item && typeof item === 'object')
					.map((item) => ({
						title: String(item.title || ''),
						url: /^https?:\/\//.test(String(item.url || ''))
							? String(item.url)
							: '',
					})),
			}
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-sees-what-the-resident-asked-about-req-qcd-006
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the ticket the page shows.
		 *
		 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-sees-what-the-resident-asked-about-req-qcd-006
		 */
		async load() {
			this.ticket = this.objectId
				? await useObjectStore().fetchObject('ticket', this.objectId)
				: null
		},
	},
}
</script>

<style scoped>
.dossier-snapshot-section__title {
	margin: 0 0 4px;
	font-size: 1.1em;
}

.dossier-snapshot-section__hint {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}

.dossier-snapshot-section__items {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.dossier-snapshot-section__items a {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
