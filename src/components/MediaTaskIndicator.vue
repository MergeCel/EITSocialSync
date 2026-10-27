<script setup lang="ts">
import { Check, CloudDownload, LoaderCircle, Upload, X } from 'lucide-vue-next'
import { useAppStore } from '../stores/app'

const store = useAppStore()

function label(status: string): string {
  if (status === 'uploading') return 'Mengunggah ke server'
  if (status === 'queued') return 'Menunggu antrean'
  if (status === 'processing') return 'Memproses media'
  if (status === 'completed') return 'Selesai'
  return 'Gagal'
}
</script>

<template>
  <aside v-if="store.mediaTasks.length" class="media-task-panel" aria-live="polite" aria-label="Progres media">
    <header>
      <div><LoaderCircle v-if="store.hasActiveMediaTasks" :size="17" class="media-task-spin"/><Check v-else :size="17"/><strong>Aktivitas media</strong></div>
      <span>{{ store.mediaTasks.length }}</span>
    </header>
    <div class="media-task-list">
      <article v-for="task in store.mediaTasks.slice(0, 4)" :key="task.id" :class="`media-task-${task.status}`">
        <span class="media-task-icon"><CloudDownload v-if="task.source === 'google-drive'" :size="17"/><Upload v-else :size="17"/></span>
        <div class="media-task-copy">
          <strong>{{ task.name }}</strong>
          <p>{{ label(task.status) }}<template v-if="task.status !== 'failed'"> · {{ task.progress }}%</template></p>
          <div v-if="task.status !== 'failed'" class="media-task-progress"><span :style="{ width: `${task.progress}%` }"/></div>
          <small v-else>{{ task.error || 'Media tidak dapat diproses.' }}</small>
        </div>
        <button v-if="task.status === 'completed' || task.status === 'failed'" type="button" aria-label="Tutup status" @click="store.dismissMediaTask(task.id)"><X :size="15"/></button>
      </article>
    </div>
  </aside>
</template>
