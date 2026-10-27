<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowLeft, Bookmark, Calendar, Eye, Heart, MessageCircle, Share2, UserRound } from 'lucide-vue-next'
import PlatformIcon from '../components/PlatformIcon.vue'
import StatusBadge from '../components/StatusBadge.vue'
import EmptyState from '../components/EmptyState.vue'
import { useAppStore } from '../stores/app'
import { contentLabels } from '../types'
import { engagement, formatDate, formatFullNumber, timeAgo } from '../utils'

const store = useAppStore()
const route = useRoute()
const post = computed(() => store.state.posts.find((item) => item.id === route.params.id))
const media = computed(() => store.state.media.find((item) => item.id === post.value?.mediaIds[0]))
const lastUpdated = computed(() => {
  const timestamps = Object.values(post.value?.metrics ?? {}).map((metric) => metric?.fetchedAt).filter(Boolean) as string[]
  return timestamps.sort().at(-1)
})
const metrics = [
  { key: 'views', label: 'Views', icon: Eye }, { key: 'likes', label: 'Likes', icon: Heart }, { key: 'comments', label: 'Komentar', icon: MessageCircle }, { key: 'shares', label: 'Dibagikan', icon: Share2 }, { key: 'saves', label: 'Disimpan', icon: Bookmark },
] as const
</script>

<template>
  <template v-if="post">
    <RouterLink to="/engagement" class="back-link"><ArrowLeft :size="17"/> Kembali ke engagement</RouterLink>
    <section class="post-hero card"><img :src="media?.thumbnail" alt=""/><div class="post-hero-content"><div><StatusBadge :status="post.status"/><span class="content-pill">{{ contentLabels[post.type] }}</span></div><h2>{{ post.title }}</h2><p>{{ post.caption || 'Post ini tidak menggunakan caption.' }}</p><div class="post-meta"><span><Calendar :size="16"/>{{ formatDate(post.scheduledAt ?? post.createdAt, true) }}</span><span><UserRound :size="16"/>{{ store.state.users.find((user) => user.id === post?.createdBy)?.name }}</span></div><div class="post-hashtags">{{ post.hashtags }}</div></div></section>
    <div class="section-title"><div><p>BREAKDOWN PLATFORM</p><h2>Performa tidak digabungkan</h2></div><span>{{ lastUpdated ? `Terakhir diperbarui ${timeAgo(lastUpdated)}` : 'Belum ada snapshot engagement' }}</span></div>
    <section class="platform-detail-grid">
      <article v-for="result in post.platforms" :key="result.platform" class="card platform-detail-card"><header><div><PlatformIcon :platform="result.platform" size="lg"/><div><strong>{{ result.platform }}</strong><span>{{ result.platformPostId ?? 'Belum memiliki ID platform' }}</span></div></div><StatusBadge :status="result.status"/></header><div v-if="post.metrics[result.platform]" class="metric-grid"><div v-for="metric in metrics" :key="metric.key"><span><component :is="metric.icon" :size="16"/>{{ metric.label }}</span><strong>{{ formatFullNumber(post.metrics[result.platform]?.[metric.key] ?? 0) }}</strong></div><div class="engagement-total"><span>Total engagement</span><strong>{{ formatFullNumber(engagement(post.metrics[result.platform])) }}</strong></div></div><div v-else class="no-metrics"><span>Data performa belum tersedia.</span><p v-if="result.error">{{ result.error }}</p></div></article>
    </section>
  </template>
  <EmptyState v-else title="Post tidak ditemukan" message="Data post mungkin telah dihapus atau ID tidak valid."><RouterLink class="button button-primary" to="/engagement">Kembali</RouterLink></EmptyState>
</template>
