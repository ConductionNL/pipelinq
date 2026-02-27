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
			<NcAppNavigationItem
				:name="t('pipelinq', 'Documentation')"
				@click="openLink('https://pipelinq.app', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationSettings>
				<NcAppNavigationItem
					:name="t('pipelinq', 'Pipelines')"
					:class="{ active: currentRoute === 'pipelines' }"
					@click="$emit('navigate', 'pipelines')">
					<template #icon>
						<Pipe :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('pipelinq', 'Configuration')"
					:class="{ active: currentRoute === 'settings' }"
					@click="$emit('navigate', 'settings')">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationSettings } from '@nextcloud/vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountBox from 'vue-material-design-icons/AccountBox.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Pipe from 'vue-material-design-icons/Pipe.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		ViewDashboard,
		AccountGroup,
		AccountBox,
		FileDocument,
		TrendingUp,
		ViewColumn,
		AccountCheck,
		BookOpenVariantOutline,
		Cog,
		Pipe,
	},
	props: {
		currentRoute: {
			type: String,
			default: 'dashboard',
		},
	},
	methods: {
		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>
