<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowDownUp, BarChart3, Eye, Filter, Heart, MessageCircle, Search, Share2 } from 'lucide-vue-next'
import PlatformIcon from '../components/PlatformIcon.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { useAppStore } from '../stores/app'
import { contentLabels, type Platform } from '../types'
import { engagement, formatDate, formatFullNumber, timeAgo } from '../utils'

const store = useAppStore()
const query = ref('')
const platform = ref<'all' | Platform>('all')
const status = ref('all')
const sort = ref<'date' | 'views' | 'likes'>('date')
const nonDraftPosts = computed(() => store.state.posts.filter((post) => post.status !== 'draft'))
const lastUpdated = computed(() => {
  const timestamps = store.state.posts.flatMap((post) => Object.values(post.metrics).map((metric) => metric?.fetchedAt)).filter(Boolean) as string[]
  return timestamps.sort().at(-1)
})
const posts = computed(() => [...store.state.posts].filter((post) => {
  return post.status !== 'draft' && post.title.toLowerCase().includes(query.value.toLowerCase()) && (platform.value === 'all' || post.platforms.some((item) => item.platform === platform.value)) && (status.value === 'all' || post.status === status.value)
}).sort((a, b) => {
  if (sort.value === 'date') return b.createdAt.localeCompare(a.createdAt)
  const total = (post: typeof a) => Object.values(post.metrics).reduce((sum, item) => sum + (item?.[sort.value as 'views' | 'likes'] ?? 0), 0)
  return total(b) - total(a)
}))

function metricTotal(post: typeof store.state.posts[number], field: 'views' | 'likes' | 'comments' | 'shares') {
  return Object.values(post.metrics).reduce((sum, metric) => sum + (metric?.[field] ?? 0), 0)
}
</script>

<template>
  <section class="engagement-summary-grid">
    <article class="summary-stat"><span><Eye :size="21"/></span><div><p>Total tayangan</p><strong>{{ formatFullNumber(store.state.posts.reduce((sum, post) => sum + metricTotal(post, 'views'), 0)) }}</strong></div><small>Semua platform</small></article>
    <article class="summary-stat"><span><Heart :size="21"/></span><div><p>Total disukai</p><strong>{{ formatFullNumber(store.state.posts.reduce((sum, post) => sum + metricTotal(post, 'likes'), 0)) }}</strong></div><small>Semua platform</small></article>
    <article class="summary-stat"><span><MessageCircle :size="21"/></span><div><p>Total komentar</p><strong>{{ formatFullNumber(store.state.posts.reduce((sum, post) => sum + metricTotal(post, 'comments'), 0)) }}</strong></div><small>Semua platform</small></article>
    <article class="summary-stat"><span><Share2 :size="21"/></span><div><p>Total dibagikan</p><strong>{{ formatFullNumber(store.state.posts.reduce((sum, post) => sum + metricTotal(post, 'shares'), 0)) }}</strong></div><small>Semua platform</small></article>
  </section>
  <section class="card data-card">
    <div class="data-toolbar"><div class="search-box"><Search :size="18"/><input v-model="query" placeholder="Cari judul post..."/></div><select v-model="platform"><option value="all">Semua platform</option><option value="instagram">Instagram</option><option value="tiktok">TikTok</option><option value="facebook">Facebook</option></select><select v-model="status"><option value="all">Semua status</option><option value="published">Tayang</option><option value="scheduled">Terjadwal</option><option value="partial">Sebagian</option><option value="failed">Gagal</option></select><select v-model="sort"><option value="date">Terbaru</option><option value="views">Views tertinggi</option><option value="likes">Likes tertinggi</option></select></div>
    <div class="table-wrap"><table class="content-table"><thead><tr><th>Konten</th><th>Platform</th><th>Tanggal</th><th><Eye :size="14"/> Views</th><th><Heart :size="14"/> Likes</th><th><MessageCircle :size="14"/> Komentar</th><th>Status</th><th /></tr></thead><tbody><tr v-for="post in posts" :key="post.id"><td><RouterLink :to="`/engagement/${post.id}`" class="content-cell"><img :src="store.state.media.find((media) => media.id === post.mediaIds[0])?.thumbnail" alt=""/><div><strong>{{ post.title }}</strong><span>{{ contentLabels[post.type] }} · {{ engagement(Object.values(post.metrics)[0]) }} interaksi</span></div></RouterLink></td><td><div class="table-platforms"><PlatformIcon v-for="item in post.platforms" :key="item.platform" :platform="item.platform" size="sm"/></div></td><td>{{ formatDate(post.scheduledAt ?? post.createdAt) }}</td><td>{{ formatFullNumber(metricTotal(post, 'views')) }}</td><td>{{ formatFullNumber(metricTotal(post, 'likes')) }}</td><td>{{ formatFullNumber(metricTotal(post, 'comments')) }}</td><td><StatusBadge :status="post.status"/></td><td><RouterLink :to="`/engagement/${post.id}`" class="table-action"><ArrowDownUp :size="16"/></RouterLink></td></tr></tbody></table></div>
    <div class="mobile-content-list"><RouterLink v-for="post in posts" :key="post.id" :to="`/engagement/${post.id}`" class="mobile-content-card"><img :src="store.state.media.find((media) => media.id === post.mediaIds[0])?.thumbnail" alt=""/><div><strong>{{ post.title }}</strong><span>{{ contentLabels[post.type] }} · {{ formatDate(post.createdAt) }}</span><div class="mobile-metrics"><span><Eye :size="13"/>{{ formatFullNumber(metricTotal(post, 'views')) }}</span><span><Heart :size="13"/>{{ formatFullNumber(metricTotal(post, 'likes')) }}</span></div></div><StatusBadge :status="post.status"/></RouterLink></div>
    <div class="data-footer"><span>Menampilkan {{ posts.length }} dari {{ nonDraftPosts.length }} post</span><small><BarChart3 :size="14"/> {{ lastUpdated ? `Diperbarui ${timeAgo(lastUpdated)}` : 'Belum ada data engagement' }}</small></div>
  </section>
</template>
