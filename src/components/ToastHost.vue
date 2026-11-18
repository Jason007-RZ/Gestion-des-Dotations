<script setup>
import { toasts, retirer } from '@/services/notify'

const style = {
  succes: 'bg-brand-600',
  erreur: 'bg-red-600',
  info: 'bg-slate-800'
}
const icone = { succes: '✓', erreur: '!', info: 'i' }
</script>

<template>
  <div class="fixed z-[80] bottom-4 right-4 left-4 sm:left-auto sm:w-96 space-y-2 pointer-events-none" aria-live="polite">
    <transition-group name="toast">
      <div
        v-for="t in toasts"
        :key="t.id"
        role="status"
        :class="style[t.type]"
        class="pointer-events-auto flex items-start gap-3 text-white text-sm rounded-xl shadow-lg px-4 py-3"
      >
        <span class="mt-0.5 h-5 w-5 shrink-0 rounded-full bg-white/20 text-xs font-bold flex items-center justify-center">{{ icone[t.type] }}</span>
        <p class="flex-1 leading-snug">{{ t.message }}</p>
        <button @click="retirer(t.id)" class="text-white/70 hover:text-white text-lg leading-none" aria-label="Fermer">&times;</button>
      </div>
    </transition-group>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
</style>
