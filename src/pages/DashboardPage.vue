<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { ArrowRight, Bookmark, Calendar, CalendarClock, Eye, Heart, MessageCircle, MousePointer2, Plus, Share2, Sparkles, TrendingDown, TrendingUp, UserRound, X } from 'lucide-vue-next'
import { RouterLink } from 'vue-router'
import PlatformIcon from '../components/PlatformIcon.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { useAppStore } from '../stores/app'
import { contentLabels, type Platform, type Post } from '../types'
import { engagement, formatDate, formatFullNumber, formatNumber, isLastMonth, isThisMonth } from '../utils'

type BoardMetric = 'engagement' | 'views' | 'likes'

const store = useAppStore()
const platforms: Platform[] = ['instagram', 'tiktok', 'facebook']
const selectedPost = ref<Post | null>(null)
const published = computed(() => store.state.posts.filter((post) => post.status === 'published' || post.status === 'partial'))
const current = computed(() => published.value.filter((post) => isThisMonth(post.createdAt)))
const previous = computed(() => published.value.filter((post) => isLastMonth(post.createdAt)))
const totals = computed(() => Object.fromEntries(platforms.map((platform) => [platform, current.value.reduce((sum, post) => sum + engagement(post.metrics[platform]), 0)])) as Record<Platform, number>)
const previousTotals = computed(() => Object.fromEntries(platforms.map((platform) => [platform, previous.value.reduce((sum, post) => sum + engagement(post.metrics[platform]), 0)])) as Record<Platform, number>)

function trend(platform: Platform): number | null {
  const before = previousTotals.value[platform]
  if (!before) return null
  return Math.round(((totals.value[platform] - before) / before) * 1000) / 10
}
const scheduled = computed(() => store.state.posts.filter((post) => post.status === 'scheduled').sort((a, b) => (a.scheduledAt ?? '').localeCompare(b.scheduledAt ?? '')).slice(0, 3))

const boards = [
  { title: 'Engagement Tertinggi', subtitle: 'Total interaksi', metric: 'engagement' as const, icon: MousePointer2 },
  { title: 'Terbanyak Ditonton', subtitle: 'Jumlah views', metric: 'views' as const, icon: Eye },
  { title: 'Terbanyak Disukai', subtitle: 'Jumlah likes', metric: 'likes' as const, icon: Heart },
]

const detailMetrics = [
  { key: 'views', label: 'Views', icon: Eye },
  { key: 'likes', label: 'Likes', icon: Heart },
  { key: 'comments', label: 'Komentar', icon: MessageCircle },
  { key: 'shares', label: 'Dibagikan', icon: Share2 },
  { key: 'saves', label: 'Disimpan', icon: Bookmark },
] as const

function winner(platform: Platform, metric: BoardMetric): Post | undefined {
  return [...current.value].filter((post) => post.metrics[platform]).sort((a, b) => {
    const am = a.metrics[platform]
    const bm = b.metrics[platform]
    const av = metric === 'engagement' ? engagement(am) : am?.[metric] ?? 0
    const bv = metric === 'engagement' ? engagement(bm) : bm?.[metric] ?? 0
    return bv - av
  })[0]
}

function boardEntries(metric: BoardMetric) {
  return platforms.map((platform) => {
    const post = winner(platform, metric)
    const metrics = post?.metrics[platform]
    return {
      platform,
      post,
      metrics,
      value: metric === 'engagement' ? engagement(metrics) : metrics?.[metric] ?? 0,
    }
  })
}

function mediaFor(post: Post | undefined) {
  return store.state.media.find((media) => media.id === post?.mediaIds[0])
}

function openDetail(post: Post | undefined) {
  if (post) selectedPost.value = post
}

function closeDetail() {
  selectedPost.value = null
}

function handleEscape(event: KeyboardEvent) {
  if (event.key === 'Escape' && selectedPost.value) closeDetail()
}

onMounted(() => window.addEventListener('keydown', handleEscape))
onUnmounted(() => window.removeEventListener('keydown', handleEscape))
</script>

<template>
  <section class="welcome-strip">
    <div><span><Sparkles :size="15" /> {{ new Intl.DateTimeFormat('id-ID', { weekday: 'long' }).format(new Date()) }} · workspace aktif</span><h2>Selamat datang kembali, {{ store.activeUser.name.split(' ')[0] }}.</h2><p>Ada <strong>{{ scheduled.length }} post</strong> yang menunggu giliran tayang dan {{ store.state.media.length }} media siap digunakan.</p></div>
    <RouterLink v-if="!store.isViewer" to="/composer" class="button button-light"><Plus :size="18" /> Susun konten baru</RouterLink>
    <div class="welcome-orbit orbit-one"/><div class="welcome-orbit orbit-two"/>
  </section>

  <div class="section-title"><div><p>PERFORMA BULAN INI</p><h2>Satu pandangan, tiga platform</h2></div><RouterLink to="/engagement">Lihat detail <ArrowRight :size="16" /></RouterLink></div>
  <section class="engagement-overview card">
    <div class="overview-heading"><span class="card-icon"><TrendingUp :size="20" /></span><div><h3>Engagement Bulan Ini</h3><p>Likes, komentar, bagikan, dan simpan</p></div></div>
    <div class="platform-stat-grid">
      <article v-for="(platform, index) in platforms" :key="platform" class="platform-stat">
        <div class="platform-stat-label"><PlatformIcon :platform="platform" /><span>{{ platform }}</span></div>
        <strong>{{ formatNumber(totals[platform]) }}</strong>
        <p v-if="trend(platform) !== null" :class="trend(platform)! < 0 ? 'trend-down' : 'trend-up'"><component :is="trend(platform)! < 0 ? TrendingDown : TrendingUp" :size="15" />{{ new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }).format(Math.abs(trend(platform)!)) }}% <span>vs bulan lalu</span></p>
        <p v-else class="trend-up"><TrendingUp :size="15" /><span>Belum ada pembanding bulan lalu</span></p>
      </article>
    </div>
  </section>

  <section class="leaderboard-grid">
    <article v-for="board in boards" :key="board.title" class="card leaderboard-card">
      <div class="leaderboard-head"><span class="card-icon"><component :is="board.icon" :size="19" /></span><div><h3>{{ board.title }}</h3><p>{{ board.subtitle }}</p></div></div>
      <div class="leader-list">
        <button v-for="entry in boardEntries(board.metric)" :key="entry.platform" type="button" class="leader-item" :disabled="!entry.post" :aria-label="entry.post ? `Lihat detail ${entry.post.title} di ${entry.platform}` : `Belum ada data ${entry.platform}`" @click="openDetail(entry.post)">
          <img v-if="entry.post && mediaFor(entry.post)" :src="mediaFor(entry.post)?.thumbnail" alt="" />
          <span v-else class="leader-placeholder"><PlatformIcon :platform="entry.platform" /></span>
          <div class="leader-content">
            <header><div><PlatformIcon :platform="entry.platform" /><strong>{{ entry.post?.title ?? 'Belum ada data' }}</strong></div><b>{{ formatNumber(entry.value) }}</b></header>
            <div v-if="entry.metrics" class="leader-metrics">
              <span><Eye :size="13"/>{{ formatNumber(entry.metrics.views) }}</span>
              <span><Heart :size="13"/>{{ formatNumber(entry.metrics.likes) }}</span>
              <span><MessageCircle :size="13"/>{{ formatNumber(entry.metrics.comments) }}</span>
              <span><Share2 :size="13"/>{{ formatNumber(entry.metrics.shares) }}</span>
              <span><Bookmark :size="13"/>{{ formatNumber(entry.metrics.saves) }}</span>
            </div>
            <small v-else>Data performa belum tersedia</small>
          </div>
        </button>
      </div>
    </article>
  </section>

  <section class="dashboard-bottom">
    <article class="card schedule-preview">
      <div class="card-section-head"><div><span class="card-icon"><CalendarClock :size="20" /></span><div><h3>Segera Tayang</h3><p>Jadwal terdekat Anda</p></div></div><RouterLink to="/schedule">Buka kalender</RouterLink></div>
      <div class="schedule-list">
        <div v-for="post in scheduled" :key="post.id" class="schedule-row">
          <div class="date-block"><strong>{{ new Date(post.scheduledAt!).getDate() }}</strong><span>{{ new Intl.DateTimeFormat('id-ID', { month: 'short' }).format(new Date(post.scheduledAt!)) }}</span></div>
          <img :src="mediaFor(post)?.thumbnail" alt="" />
          <div class="schedule-copy"><strong>{{ post.title }}</strong><span>{{ formatDate(post.scheduledAt!, true) }}</span><div><PlatformIcon v-for="item in post.platforms" :key="item.platform" :platform="item.platform" size="sm" /></div></div>
          <StatusBadge :status="post.status" />
        </div>
      </div>
    </article>
  </section>

  <div v-if="selectedPost" class="modal-backdrop dashboard-detail-backdrop" @click.self="closeDetail">
    <section class="modal-card dashboard-detail-modal" role="dialog" aria-modal="true" aria-labelledby="dashboard-detail-title">
      <button type="button" class="icon-button close-button" aria-label="Tutup detail post" @click="closeDetail"><X :size="20"/></button>
      <div class="dashboard-detail-hero">
        <img v-if="mediaFor(selectedPost)" :src="mediaFor(selectedPost)?.thumbnail" alt="" />
        <div>
          <div class="dashboard-detail-badges"><StatusBadge :status="selectedPost.status"/><span class="content-pill">{{ contentLabels[selectedPost.type] }}</span></div>
          <h2 id="dashboard-detail-title">{{ selectedPost.title }}</h2>
          <p>{{ selectedPost.caption || 'Post ini tidak menggunakan caption.' }}</p>
          <div class="post-meta"><span><Calendar :size="16"/>{{ formatDate(selectedPost.scheduledAt ?? selectedPost.createdAt, true) }}</span><span><UserRound :size="16"/>{{ store.state.users.find((user) => user.id === selectedPost?.createdBy)?.name }}</span></div>
          <div v-if="selectedPost.hashtags" class="post-hashtags">{{ selectedPost.hashtags }}</div>
        </div>
      </div>
      <div class="dashboard-detail-heading"><span>BREAKDOWN PLATFORM</span><h3>Performa setiap platform</h3></div>
      <div class="dashboard-detail-platforms">
        <article v-for="result in selectedPost.platforms" :key="result.platform" class="dashboard-platform-card">
          <header><div><PlatformIcon :platform="result.platform" size="lg"/><div><strong>{{ result.platform }}</strong><span>{{ result.platformPostId ?? 'Belum memiliki ID platform' }}</span></div></div><StatusBadge :status="result.status"/></header>
          <div v-if="selectedPost.metrics[result.platform]" class="metric-grid">
            <div v-for="metric in detailMetrics" :key="metric.key"><span><component :is="metric.icon" :size="16"/>{{ metric.label }}</span><strong>{{ formatFullNumber(selectedPost.metrics[result.platform]?.[metric.key] ?? 0) }}</strong></div>
            <div class="engagement-total"><span>Total engagement</span><strong>{{ formatFullNumber(engagement(selectedPost.metrics[result.platform])) }}</strong></div>
          </div>
          <div v-else class="no-metrics"><span>Data performa belum tersedia.</span><p v-if="result.error">{{ result.error }}</p></div>
        </article>
      </div>
    </section>
  </div>
</template>
