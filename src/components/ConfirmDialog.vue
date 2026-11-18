<script setup>
import { etatConfirmation as e, repondre } from '@/services/confirm'
</script>

<template>
  <div v-if="e.ouverte" class="fixed inset-0 z-[90] bg-black/50 backdrop-blur-sm flex items-center justify-center p-4" @keydown.esc="repondre(false)">
    <div class="bg-white rounded-2xl max-w-sm w-full p-6 shadow-2xl" role="alertdialog" aria-modal="true">
      <h3 class="text-lg font-bold text-gray-900 mb-1">{{ e.titre }}</h3>
      <p class="text-sm text-gray-500 mb-5">{{ e.message }}</p>
      <div class="flex justify-end space-x-2">
        <button @click="repondre(false)" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
        <button
          @click="repondre(true)"
          :class="e.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-600 hover:bg-brand-700'"
          class="text-white px-5 py-2.5 rounded-xl text-xs font-semibold"
        >
          {{ e.bouton }}
        </button>
      </div>
    </div>
  </div>
</template>
