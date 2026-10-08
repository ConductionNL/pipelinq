<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

Which structure the app shows: the simple one (the default) or the full one,
and which modules join the simple menu.

The choice is read at page load by the app's boot code, before it builds the
navigation, so a change shows the next time somebody opens Pipelinq. The
section says so, because a setting that seems to do nothing gets changed back.

@spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
-->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Menu structure')"
		:description="
			t(
				'pipelinq',
				'Choose how much the menu shows. No page is removed: both menus open the same pages.',
			)
		"
		data-testid="menu-structure">
		<fieldset class="menu-structure__choices" :disabled="saving">
			<legend class="hidden-visually">
				{{ t('pipelinq', 'Menu structure') }}
			</legend>
			<NcCheckboxRadioSwitch
				v-model="structure"
				value="simple"
				name="menu_structure"
				type="radio"
				data-testid="menu-structure-simple"
				@update:modelValue="save">
				{{ t('pipelinq', 'Simple') }}
			</NcCheckboxRadioSwitch>
			<p class="menu-structure__help">
				{{
					t(
						'pipelinq',
						'Nine menu entries for customer contact. Everything else is one step further, on the Modules page or in settings.',
					)
				}}
			</p>
			<NcCheckboxRadioSwitch
				v-model="structure"
				value="full"
				name="menu_structure"
				type="radio"
				data-testid="menu-structure-full"
				@update:modelValue="save">
				{{ t('pipelinq', 'Full') }}
			</NcCheckboxRadioSwitch>
			<p class="menu-structure__help">
				{{ t('pipelinq', 'Every entry in the menu, as it was before.') }}
			</p>
		</fieldset>

		<fieldset
			class="menu-structure__choices"
			:disabled="saving || structure !== 'simple'"
			data-testid="menu-modules">
			<legend class="menu-structure__legend">
				{{ t('pipelinq', 'Modules in the simple menu') }}
			</legend>
			<p class="menu-structure__help menu-structure__help--flush">
				{{
					t(
						'pipelinq',
						'Switch on what your organisation uses. A module that is off stays reachable from the Modules page.',
					)
				}}
			</p>
			<NcCheckboxRadioSwitch
				v-for="module in moduleOptions"
				:key="module.key"
				v-model="modules"
				:value="module.key"
				name="menu_modules"
				type="checkbox"
				:data-testid="`menu-module-${module.key}`"
				@update:modelValue="save">
				{{ t('pipelinq', module.label) }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<p class="menu-structure__note" role="status">
			{{
				saved
					? t(
							'pipelinq',
							'Saved. People see the change the next time they open Pipelinq.',
						)
					: ''
			}}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</NcSettingsSection>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection } from '@nextcloud/vue'
import simpleMenuLayout from '../../menu-layout.simple.json'
import { saveMenuStructure } from '../../services/menuStructureSetting.js'
import { MODULES_SETTING, resolveMenuModules } from '../../utils/menuModules.js'
import {
	resolveStructureProfile,
	STRUCTURE_SETTING,
} from '../../utils/structureProfile.js'

/**
 * The menu structure choice on the admin settings page.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
 */
export default {
	name: 'MenuStructureSettings',
	components: { NcCheckboxRadioSwitch, NcNoteCard, NcSettingsSection },
	data() {
		const structure = resolveStructureProfile(
			loadState('pipelinq', STRUCTURE_SETTING, ''),
		)
		const modules = resolveMenuModules(
			loadState('pipelinq', MODULES_SETTING, ''),
			simpleMenuLayout,
		)
		return {
			structure,
			modules,
			stored: { structure, modules: [...modules] },
			saving: false,
			saved: false,
			error: null,
		}
	},

	computed: {
		/**
		 * The modules the simple profile declares, in its order.
		 *
		 * @return {Array<{key: string, label: string}>} One option per module.
		 *
		 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-104
		 */
		moduleOptions() {
			return Object.entries(simpleMenuLayout.modules).map(([key, module]) => ({
				key,
				label: module.label,
			}))
		},
	},

	methods: {
		t,
		/**
		 * Store the choice, and put the controls back when that fails.
		 *
		 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
		 */
		async save() {
			this.saving = true
			this.saved = false
			this.error = null
			try {
				const stored = await saveMenuStructure(
					{
						structure: this.structure,
						modules: resolveMenuModules(
							this.modules.join(','),
							simpleMenuLayout,
						),
					},
					{
						url: generateUrl('/apps/pipelinq/api/settings'),
						requestToken: OC.requestToken,
					},
				)
				this.stored = {
					structure: stored.structure,
					modules: stored.modules,
				}
				this.modules = [...stored.modules]
				this.saved = true
			} catch {
				this.structure = this.stored.structure
				this.modules = [...this.stored.modules]
				this.error = t('pipelinq', 'The menu could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.menu-structure__choices {
	border: 0;
	margin: 0 0 calc(var(--default-grid-baseline) * 4);
	padding: 0;
}

.menu-structure__legend {
	font-weight: bold;
	padding: 0;
}

.menu-structure__help {
	color: var(--color-text-maxcontrast);
	margin: 0 0 calc(var(--default-grid-baseline) * 2)
		calc(var(--default-grid-baseline) * 9);
}

.menu-structure__help--flush {
	margin-inline-start: 0;
}

.menu-structure__note {
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 2);
}
</style>
