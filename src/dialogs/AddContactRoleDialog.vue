<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  AddContactRoleDialog — attach a contact to a lead with a role.

  Extracted from LeadContactRoles' hand-rolled `create-overlay`, which was a
  bare <div> with @click.self to dismiss. hydra gate-32 reported that overlay
  because a click handler on a non-semantic element has no keyboard path — and
  the honest fix is NOT to give a BACKDROP role="button" + tabindex, which
  would put it in the tab order and announce it as a button. A backdrop is
  presentational; what it was missing is Escape, a focus trap, and a labelled
  dialog role. NcDialog provides all three.

  Lives in its own file per ADR-004 (modal-isolation): gate-13 reports any
  <NcDialog> inlined in a component outside src/modals/ or src/dialogs/.

  @spec openspec/specs/contact-relationship-mapping/spec.md
-->
<template>
	<NcDialog :name="t('pipelinq', 'Add contact role')" @closing="$emit('cancel')">
		<div class="form-group">
			<label for="add-contact-role-contact"
				>{{ t('pipelinq', 'Contact') }} *</label
			>
			<NcSelect
				id="add-contact-role-contact"
				v-model="form.toContact"
				:options="contactOptions"
				:aria-label-combobox="t('pipelinq', 'Contact')"
				:placeholder="t('pipelinq', 'Search contacts…')"
				label="name"
				:reduce="(opt) => opt.id"
				@search="(term) => $emit('searchContacts', term)" />
		</div>
		<div class="form-group">
			<label for="add-contact-role-type">{{ t('pipelinq', 'Role') }} *</label>
			<NcSelect
				id="add-contact-role-type"
				v-model="form.type"
				:options="roleOptions"
				:aria-label-combobox="t('pipelinq', 'Role')"
				:placeholder="t('pipelinq', 'Select role…')"
				label="label"
				:reduce="(opt) => opt.value" />
		</div>
		<div class="form-group">
			<label for="add-contact-role-notes">{{ t('pipelinq', 'Notes') }}</label>
			<textarea id="add-contact-role-notes" v-model="form.notes" rows="2" />
		</div>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit"
				@click="$emit('submit', { ...form })">
				{{ t('pipelinq', 'Add') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'AddContactRoleDialog',
	components: { NcButton, NcDialog, NcSelect },
	props: {
		/** Selectable contacts, shape { id, name }. */
		contactOptions: { type: Array, default: () => [] },
		/** Selectable roles, shape { value, label }. */
		roleOptions: { type: Array, default: () => [] },
	},

	emits: ['submit', 'cancel', 'searchContacts'],
	data() {
		return {
			form: { toContact: null, type: null, notes: '' },
		}
	},

	computed: {
		/**
		 * Both selects are required, matching the button guard the overlay had.
		 *
		 * @return {boolean} Whether the form may be submitted.
		 *
		 * @spec openspec/specs/contact-relationship-mapping/spec.md
		 */
		canSubmit() {
			return !!this.form.toContact && !!this.form.type
		},
	},
}
</script>

<style scoped>
.form-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}
</style>
