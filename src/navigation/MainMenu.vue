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
				:name="t('pipelinq', 'Requests')"
				:class="{ active: currentRoute === 'requests' || currentRoute === 'request-detail' }"
				@click="$emit('navigate', 'requests')">
				<template #icon>
					<FileDocument :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		ViewDashboard,
		AccountGroup,
		FileDocument,
	},
	props: {
		currentRoute: {
			type: String,
			default: 'dashboard',
		},
	},
}
</script>
