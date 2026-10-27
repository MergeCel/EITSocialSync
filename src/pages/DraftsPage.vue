<script setup lang="ts">
import { computed, ref } from 'vue'
import { Clock3, FileText, Pencil, Search, Trash2 } from 'lucide-vue-next'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import EmptyState from '../components/EmptyState.vue'
import PlatformIcon from '../components/PlatformIcon.vue'
import { useAppStore } from '../stores/app'
import { contentLabels } from '../types'
import { formatDate } from '../utils'

const store = useAppStore()
const query = ref('')
const deleteTarget = ref<string | null>(null)
const drafts = computed(() => store.state.posts
  .filter((post) => post.status === 'draft' && post.title.toLowerCase().includes(query.value.toLowerCase()))
  .sort((a, b) => b.createdAt.localeCompare(a.createdAt)))
</script>

<template>
  <div class="page-toolbar draft-toolbar">
    <div class="search-box"><Search :size="18"/><input v-model="query" placeholder="Cari judul draft..."/></div>
    <RouterLink to="/composer" class="button button-primary"><FileText :size="18"/> Buat Draft Baru</RouterLink>
  </div>

  <section v-if="drafts.length" class="draft-grid">
    <article v-for="draft in drafts" :key="draft.id" class="card draft-card">
      <div class="draft-thumbnail">
        <img v-if="store.state.media.find((media) => media.id === draft.mediaIds[0])" :src="store.state.media.find((media) => media.id === draft.mediaIds[0])?.thumbnail" alt=""/>
        <span v-else><FileText :size="28"/></span>
        <b>Draft</b>
      </div>
      <div class="draft-content">
        <span>{{ contentLabels[draft.type] }}</span>
        <h2>{{ draft.title }}</h2>
        <p>{{ draft.caption || 'Caption belum diisi.' }}</p>
        <div class="draft-meta"><span><Clock3 :size="14"/>{{ formatDate(draft.createdAt, true) }}</span><div><PlatformIcon v-for="item in draft.platforms" :key="item.platform" :platform="item.platform" size="sm"/></div></div>
      </div>
      <footer><RouterLink :to="`/composer/${draft.id}`" class="button button-primary"><Pencil :size="16"/> Lanjutkan</RouterLink><button class="button button-text-danger" @click="deleteTarget = draft.id"><Trash2 :size="16"/> Hapus</button></footer>
    </article>
  </section>
  <EmptyState v-else title="Belum ada draft" message="Simpan post yang belum selesai agar dapat dilanjutkan kapan saja."><RouterLink to="/composer" class="button button-primary">Buat Post</RouterLink></EmptyState>

  <ConfirmDialog :open="Boolean(deleteTarget)" title="Hapus Draft Ini?" :message="`Draft '${store.state.posts.find((post) => post.id === deleteTarget)?.title ?? ''}' akan dihapus permanen.`" confirm-label="Ya, Hapus" danger @close="deleteTarget = null" @confirm="deleteTarget && store.deleteDraft(deleteTarget); deleteTarget = null"/>
</template>
