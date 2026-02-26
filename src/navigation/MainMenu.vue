<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Dashboard')"
				:class="{ active: currentRoute === 'dashboard' }"
				@click="$emit('navigate', 'dashboard')">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Clients')"
				:class="{ active: currentRoute === 'clients' || currentRoute === 'client-detail' }"
				@click="$emit('navigate', 'clients')">
				<template #icon>
					<AccountGroup :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Contacts')"
				:class="{ active: currentRoute === 'contacts' || currentRoute === 'contact-detail' }"
				@click="$emit('navigate', 'contacts')">
				<template #icon>
					<AccountBox :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Leads')"
				:class="{ active: currentRoute === 'leads' || currentRoute === 'lead-detail' }"
				@click="$emit('navigate', 'leads')">
				<template #icon>
					<TrendingUp :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Requests')"
				:class="{ active: currentRoute === 'requests' || currentRoute === 'request-detail' }"
				@click="$emit('navigate', 'requests')">
				<template #icon>
					<FileDocument :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Pipeline')"
				:class="{ active: currentRoute === 'pipeline' }"
				@click="$emit('navigate', 'pipeline')">
				<template #icon>
					<ViewColumn :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'My Work')"
				:class="{ active: currentRoute === 'my-work' }"
				@click="$emit('navigate', 'my-work')">
				<template #icon>
					<AccountCheck :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountBox from 'vue-material-design-icons/AccountBox.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		ViewDashboard,
		AccountGroup,
		AccountBox,
		FileDocument,
		TrendingUp,
		ViewColumn,
		AccountCheck,
	},
	props: {
		currentRoute: {
			type: String,
			default: 'dashboard',
		},
	},
}
</script>
