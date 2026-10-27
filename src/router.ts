import { createRouter, createWebHashHistory } from 'vue-router'
import DashboardPage from './pages/DashboardPage.vue'
import DrivePage from './pages/DrivePage.vue'
import ComposerPage from './pages/ComposerPage.vue'
import SchedulePage from './pages/SchedulePage.vue'
import EngagementPage from './pages/EngagementPage.vue'
import PostDetailPage from './pages/PostDetailPage.vue'
import AccountsPage from './pages/AccountsPage.vue'
import TeamPage from './pages/TeamPage.vue'
import NotificationsPage from './pages/NotificationsPage.vue'
import SettingsPage from './pages/SettingsPage.vue'
import DraftsPage from './pages/DraftsPage.vue'
import LoginPage from './pages/LoginPage.vue'

export const router = createRouter({
  history: createWebHashHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/login', component: LoginPage, meta: { title: 'Masuk', public: true } },
    { path: '/', redirect: '/dashboard' },
    { path: '/dashboard', component: DashboardPage, meta: { title: 'Dashboard', eyebrow: 'Ringkasan performa konten Anda' } },
    { path: '/drive', component: DrivePage, meta: { title: 'Drive', eyebrow: 'Kelola seluruh aset media' } },
    { path: '/composer', component: ComposerPage, meta: { title: 'Buat Post', eyebrow: 'Susun sekali, bagikan ke banyak platform', requiresEdit: true } },
    { path: '/composer/:id', component: ComposerPage, meta: { title: 'Edit Post', eyebrow: 'Lanjutkan konten yang belum tayang', requiresEdit: true } },
    { path: '/drafts', component: DraftsPage, meta: { title: 'Draft', eyebrow: 'Lanjutkan konten yang belum selesai', requiresEdit: true } },
    { path: '/schedule', component: SchedulePage, meta: { title: 'Kalender Konten', eyebrow: 'Rencanakan ritme publikasi' } },
    { path: '/engagement', component: EngagementPage, meta: { title: 'Engagement', eyebrow: 'Pantau performa seluruh konten' } },
    { path: '/engagement/:id', component: PostDetailPage, meta: { title: 'Detail Post', eyebrow: 'Breakdown performa per platform' } },
    { path: '/accounts', component: AccountsPage, meta: { title: 'Koneksi Akun', eyebrow: 'Kelola saluran publikasi' } },
    { path: '/team', component: TeamPage, meta: { title: 'Tim & Akses', eyebrow: 'Kelola akses workspace internal' } },
    { path: '/notifications', component: NotificationsPage, meta: { title: 'Notifikasi', eyebrow: 'Pembaruan aktivitas dan sistem' } },
    { path: '/settings', component: SettingsPage, meta: { title: 'Pengaturan', eyebrow: 'Data, audit, dan operasional' } },
  ],
})
