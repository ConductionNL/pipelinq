// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for pipelinq (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import Account from 'vue-material-design-icons/Account.vue'
import AccountArrowRightOutline from 'vue-material-design-icons/AccountArrowRightOutline.vue'
import AccountBox from 'vue-material-design-icons/AccountBox.vue'
import AccountBoxOutline from 'vue-material-design-icons/AccountBoxOutline.vue'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import AccountClock from 'vue-material-design-icons/AccountClock.vue'
import AccountCog from 'vue-material-design-icons/AccountCog.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountKey from 'vue-material-design-icons/AccountKey.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountSearch from 'vue-material-design-icons/AccountSearch.vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import AccountStarOutline from 'vue-material-design-icons/AccountStarOutline.vue'
import AccountTie from 'vue-material-design-icons/AccountTie.vue'
import AccountVoice from 'vue-material-design-icons/AccountVoice.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOctagon from 'vue-material-design-icons/AlertOctagon.vue'
import AlertOctagonOutline from 'vue-material-design-icons/AlertOctagonOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import BellRing from 'vue-material-design-icons/BellRing.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import BullhornOutline from 'vue-material-design-icons/BullhornOutline.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import CalendarWeek from 'vue-material-design-icons/CalendarWeek.vue'
import CardAccountDetails from 'vue-material-design-icons/CardAccountDetails.vue'
import CartArrowDown from 'vue-material-design-icons/CartArrowDown.vue'
import CartOutline from 'vue-material-design-icons/CartOutline.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import CashCheck from 'vue-material-design-icons/CashCheck.vue'
import CashMinus from 'vue-material-design-icons/CashMinus.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import CashRefund from 'vue-material-design-icons/CashRefund.vue'
import CashRegister from 'vue-material-design-icons/CashRegister.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ChartTimelineVariant from 'vue-material-design-icons/ChartTimelineVariant.vue'
import ChartTimelineVariantShimmer from 'vue-material-design-icons/ChartTimelineVariantShimmer.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ClipboardAccountOutline from 'vue-material-design-icons/ClipboardAccountOutline.vue'
import ClipboardCheck from 'vue-material-design-icons/ClipboardCheck.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import ClockAlertOutline from 'vue-material-design-icons/ClockAlertOutline.vue'
import ClockCheckOutline from 'vue-material-design-icons/ClockCheckOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Counter from 'vue-material-design-icons/Counter.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import DatabaseClock from 'vue-material-design-icons/DatabaseClock.vue'
import DatabaseExport from 'vue-material-design-icons/DatabaseExport.vue'
import DatabaseExportOutline from 'vue-material-design-icons/DatabaseExportOutline.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import Email from 'vue-material-design-icons/Email.vue'
import EmailEditOutline from 'vue-material-design-icons/EmailEditOutline.vue'
import EmailFastOutline from 'vue-material-design-icons/EmailFastOutline.vue'
import EmailMultiple from 'vue-material-design-icons/EmailMultiple.vue'
import EmailMultipleOutline from 'vue-material-design-icons/EmailMultipleOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import Export from 'vue-material-design-icons/Export.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileChart from 'vue-material-design-icons/FileChart.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentCheck from 'vue-material-design-icons/FileDocumentCheck.vue'
import FileDocumentMultiple from 'vue-material-design-icons/FileDocumentMultiple.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import FilterVariant from 'vue-material-design-icons/FilterVariant.vue'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import FormatListBulletedSquare from 'vue-material-design-icons/FormatListBulletedSquare.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import FormTextboxPassword from 'vue-material-design-icons/FormTextboxPassword.vue'
import ForumOutline from 'vue-material-design-icons/ForumOutline.vue'
import Gauge from 'vue-material-design-icons/Gauge.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import Heart from 'vue-material-design-icons/Heart.vue'
import History from 'vue-material-design-icons/History.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Key from 'vue-material-design-icons/Key.vue'
import Link from 'vue-material-design-icons/Link.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import MapMarkerCheck from 'vue-material-design-icons/MapMarkerCheck.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import NewspaperVariantOutline from 'vue-material-design-icons/NewspaperVariantOutline.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import Package from 'vue-material-design-icons/Package.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import PhoneMessage from 'vue-material-design-icons/PhoneMessage.vue'
import Pipe from 'vue-material-design-icons/Pipe.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Receipt from 'vue-material-design-icons/Receipt.vue'
import ReceiptOutline from 'vue-material-design-icons/ReceiptOutline.vue'
import ReceiptText from 'vue-material-design-icons/ReceiptText.vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import RouterWireless from 'vue-material-design-icons/RouterWireless.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import SchoolOutline from 'vue-material-design-icons/SchoolOutline.vue'
import SendCheckOutline from 'vue-material-design-icons/SendCheckOutline.vue'
import Server from 'vue-material-design-icons/Server.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import SourceMerge from 'vue-material-design-icons/SourceMerge.vue'
import Star from 'vue-material-design-icons/Star.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import TableColumn from 'vue-material-design-icons/TableColumn.vue'
import Tag from 'vue-material-design-icons/Tag.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import ThumbsUpDown from 'vue-material-design-icons/ThumbsUpDown.vue'
import TicketOutline from 'vue-material-design-icons/TicketOutline.vue'
import Timer from 'vue-material-design-icons/Timer.vue'
import Tray from 'vue-material-design-icons/Tray.vue'
import TrayFull from 'vue-material-design-icons/TrayFull.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import Trophy from 'vue-material-design-icons/Trophy.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import ViewGridOutline from 'vue-material-design-icons/ViewGridOutline.vue'
import WalletOutline from 'vue-material-design-icons/WalletOutline.vue'

export default {
	Account,
	AccountArrowRightOutline,
	AccountBox,
	AccountBoxOutline,
	AccountCheckOutline,
	AccountClock,
	AccountCog,
	AccountGroup,
	AccountKey,
	AccountMultiple,
	AccountMultipleOutline,
	AccountSearch,
	AccountSearchOutline,
	AccountStarOutline,
	AccountTie,
	AccountVoice,
	AlertCircleOutline,
	AlertOctagon,
	AlertOctagonOutline,
	AlertOutline,
	BellRing,
	BookOpenPageVariant,
	BookOpenVariantOutline,
	BullhornOutline,
	Calendar,
	CalendarCheck,
	CalendarClockOutline,
	CalendarWeek,
	CardAccountDetails,
	CartArrowDown,
	CartOutline,
	Cash,
	CashCheck,
	CashMinus,
	CashMultiple,
	CashRefund,
	CashRegister,
	ChartBar,
	ChartBoxOutline,
	ChartLine,
	ChartTimelineVariant,
	ChartTimelineVariantShimmer,
	Check,
	CheckCircle,
	CheckboxMarkedCircleOutline,
	CheckboxMarkedOutline,
	ClipboardAccountOutline,
	ClipboardCheck,
	ClipboardCheckOutline,
	ClipboardList,
	ClipboardTextOutline,
	ClockAlertOutline,
	ClockCheckOutline,
	ClockOutline,
	Cog,
	Counter,
	CreditCardOutline,
	CurrencyEur,
	DatabaseClock,
	DatabaseExport,
	DatabaseExportOutline,
	DatabaseImportOutline,
	Domain,
	Email,
	EmailEditOutline,
	EmailFastOutline,
	EmailMultiple,
	EmailMultipleOutline,
	EmailOutline,
	Export,
	Eye,
	EyeOff,
	EyeOutline,
	FileChart,
	FileDocument,
	FileDocumentCheck,
	FileDocumentMultiple,
	FileDocumentOutline,
	FileOutline,
	FileSign,
	FilterVariant,
	FolderOpen,
	FolderOutline,
	FormTextboxPassword,
	FormatListBulleted,
	FormatListBulletedSquare,
	FormatListChecks,
	FormatListNumbered,
	ForumOutline,
	Gauge,
	Gavel,
	HandshakeOutline,
	Heart,
	History,
	InformationOutline,
	Key,
	Link,
	LinkVariant,
	Magnify,
	MapMarkerCheck,
	MapMarkerPath,
	MessageOutline,
	MessageText,
	MessageTextOutline,
	NewspaperVariantOutline,
	NoteTextOutline,
	OfficeBuilding,
	Package,
	PackageVariantClosed,
	PencilOutline,
	PhoneMessage,
	Pipe,
	Plus,
	Receipt,
	ReceiptOutline,
	ReceiptText,
	Repeat,
	RouterWireless,
	ScaleBalance,
	SchoolOutline,
	Server,
	SendCheckOutline,
	ShieldAccountOutline,
	ShieldCheck,
	ShieldCheckOutline,
	Sitemap,
	SourceBranch,
	SourceMerge,
	Star,
	StoreOutline,
	TableColumn,
	Tag,
	TagOutline,
	ThumbsUpDown,
	TicketOutline,
	Timer,
	Tray,
	TrayFull,
	TrendingUp,
	Trophy,
	ViewDashboard,
	ViewGridOutline,
	WalletOutline,
}
