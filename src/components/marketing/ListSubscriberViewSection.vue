<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  What a subscriber sees: a still preview of the preference centre every
  message links to, with the published lists as checkboxes and the one-click
  unsubscribe under them. It shows the page; it changes nothing.

  @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
-->
<template>
	<div class="list-preview" data-testid="list-subscriber-view">
		<p class="list-preview__intro">
			{{ t('pipelinq', 'Every message links to this page. One click there stops every list.') }}
		</p>
		<div class="list-preview__frame" aria-hidden="true">
			<p class="list-preview__address">
				<Web :size="14" />
				<span>{{ address }}</span>
			</p>
			<div class="list-preview__page">
				<strong>{{ t('pipelinq', 'Your mailing lists') }}</strong>
				<span class="list-preview__email">{{ t('pipelinq', 'name@example.org') }}</span>
				<label v-for="list in lists" :key="list.id" class="list-preview__list">
					<input type="checkbox" :checked="list.id === listId" disabled>
					<span>{{ list.name }}</span>
				</label>
				<span class="list-preview__save">{{ t('pipelinq', 'Save') }}</span>
				<span class="list-preview__unsubscribe">{{ t('pipelinq', 'Unsubscribe from everything') }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import Web from 'vue-material-design-icons/Web.vue'

export default {
	name: 'ListSubscriberViewSection',
	components: { Web },

	props: {
		/** The list whose page this is; it shows ticked. */
		listId: {
			type: String,
			required: true,
		},
	},

	data() {
		return { lists: [] }
	},

	computed: {
		/**
		 * The address of the preference centre, without a token.
		 *
		 * @return {string} The address as a subscriber reads it.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
		 */
		address() {
			return window.location.host + generateUrl('/apps/pipelinq/api/lists/preferences/…')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the active lists that take public sign-ups.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
		 */
		async load() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/pipelinq/mailingList'),
					{ params: { status: 'active', _limit: 20 } },
				)
				this.lists = (data?.results || [])
					.filter((list) => list.publicSignup === true || (list.id || list['@self']?.id) === this.listId)
					.map((list) => ({ id: list.id || list['@self']?.id, name: list.name || '' }))
			} catch {
				this.lists = []
			}
		},
	},
}
</script>

<style scoped lang="scss">
.list-preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.list-preview__intro {
	margin: 0;
}

.list-preview__frame {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	padding: 8px;
}

.list-preview__address {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.list-preview__page {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 12px;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	font-size: 13px;
}

.list-preview__email {
	color: var(--color-text-maxcontrast);
}

.list-preview__list {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.list-preview__save {
	align-self: flex-start;
	display: inline-flex;
	align-items: center;
	box-sizing: border-box;
	height: 34px;
	padding: 0 14px;
	border-radius: 8px;
	font-size: 14px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.list-preview__unsubscribe {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
