<script setup>
// v-model:page + nombre de pages + total. Masqué s'il n'y a qu'une page.
const page = defineModel('page', { type: Number, default: 1 })
defineProps({ pages: { type: Number, default: 1 }, total: { type: Number, default: 0 } })
</script>

<template>
  <div v-if="pages > 1" class="flex items-center justify-between gap-3 px-4 py-3 border-t border-gray-100 text-xs text-gray-500">
    <span>{{ total }} résultat(s)</span>
    <div class="flex items-center space-x-2">
      <button @click="page--" :disabled="page <= 1" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">Précédent</button>
      <span class="font-semibold text-gray-700">{{ page }} / {{ pages }}</span>
      <button @click="page++" :disabled="page >= pages" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">Suivant</button>
    </div>
  </div>
</template>
