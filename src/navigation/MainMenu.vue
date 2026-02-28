<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Dashboard')"
				:to="{ name: 'Dashboard' }">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Clients')"
				:to="{ name: 'Clients' }">
				<template #icon>
					<AccountGroup :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Contacts')"
				:to="{ name: 'Contacts' }">
				<template #icon>
					<AccountBox :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Leads')"
				:to="{ name: 'Leads' }">
				<template #icon>
					<TrendingUp :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Requests')"
				:to="{ name: 'Requests' }">
				<template #icon>
					<FileDocument :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'Pipeline')"
				:to="{ name: 'Pipeline' }">
				<template #icon>
					<ViewColumn :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('pipelinq', 'My Work')"
				:to="{ name: 'MyWork' }">
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
					:to="{ name: 'Pipelines' }">
					<template #icon>
						<Pipe :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="t('pipelinq', 'Configuration')"
					@click="$emit('open-settings')">
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
	methods: {
		openLink(url, target) {
			window.open(url, target)
		},
	},
}
</script>
