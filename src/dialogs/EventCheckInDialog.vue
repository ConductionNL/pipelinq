<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - EventCheckInDialog is the door list of one event: the registrations with a
  - place, a search on name or street, and a Check in button per person.
  - Opened by the "Check in at the door" header action on EventDetail; the
  - event comes from the route, because a header action's modal props are not
  - resolved against the page's object. The check-in itself is
  - POST /api/event-registrations/{id}/check-in, which stamps checkedInAt once.
  -
  - @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Check in at the door')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="event-check-in" data-testid="event-check-in">
			<NcTextField
				v-model="search"
				:label="t('pipelinq', 'Search a name or street')"
				:showTrailingButton="search !== ''"
				@trailingButtonClick="search = ''" />
			<NcLoadingIcon v-if="loading" :size="24" />
			<p v-else-if="error" class="event-check-in__error" role="alert">
				{{ error }}
			</p>
			<p v-else-if="shown.length === 0" class="event-check-in__empty">
				{{ t('pipelinq', 'Nobody on the list matches.') }}
			</p>
			<ul v-else class="event-check-in__list">
				<li v-for="row in shown" :key="row.id" class="event-check-in__row">
					<span class="event-check-in__who">
						<strong>{{ row.name }}</strong>
						<span>{{ row.address }}</span>
					</span>
					<CnStatusBadge
						v-if="row.checkedInAt"
						:label="t('pipelinq', 'Checked in')"
						variant="success"
						size="small" />
					<NcButton
						v-else
						variant="secondary"
						:disabled="busy === row.id"
						@click="checkIn(row)">
						{{ t('pipelinq', 'Check in') }}
					</NcButton>
				</li>
			</ul>
		</div>
	</NcDialog>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'

export default {
	name: 'EventCheckInDialog',
	components: {
		CnStatusBadge,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		/** The event; read from the route on an event page when not given. */
		eventId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			error: '',
			search: '',
			busy: '',
			rows: [],
		}
	},

	computed: {
		/**
		 * The event this list is for.
		 *
		 * @return {string} The event id.
		 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
		 */
		resolvedEventId() {
			return this.eventId || this.$route?.params?.id || ''
		},

		/**
		 * The registrations matching the search on name or address.
		 *
		 * @return {Array<object>} The rows to show.
		 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
		 */
		shown() {
			const needle = this.search.trim().toLowerCase()
			if (needle === '') {
				return this.rows
			}
			return this.rows.filter((row) => `${row.name} ${row.address}`.toLowerCase().includes(needle))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the confirmed registrations of the event.
		 *
		 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/pipelinq/eventRegistration'),
					{ params: { eventId: this.resolvedEventId, status: 'confirmed', _limit: 500 } },
				)
				const list = data?.results || []
				this.rows = list
					.filter((row) => row.eventId === this.resolvedEventId)
					.map((row) => ({
						id: row.id || row['@self']?.id || row.uuid,
						name: row.name || '',
						address: row.address || '',
						checkedInAt: row.checkedInAt || '',
					}))
					.sort((a, b) => a.name.localeCompare(b.name))
			} catch {
				this.error = this.t('pipelinq', 'Could not load the registrations.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Check one registration in and show it as checked in.
		 *
		 * @param {object} row The registration row.
		 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-registration-is-checked-in-at-the-door
		 */
		async checkIn(row) {
			this.busy = row.id
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(`/apps/pipelinq/api/event-registrations/${row.id}/check-in`),
				)
				row.checkedInAt = data?.registration?.checkedInAt || new Date().toISOString()
			} catch (e) {
				this.error = e?.response?.status === 409
					? this.t('pipelinq', 'This registration was cancelled.')
					: this.t('pipelinq', 'Could not check this registration in.')
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped lang="scss">
.event-check-in {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 12px;
}

.event-check-in__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.event-check-in__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.event-check-in__who {
	display: flex;
	flex-direction: column;

	span {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}

.event-check-in__empty {
	color: var(--color-text-maxcontrast);
}

.event-check-in__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
