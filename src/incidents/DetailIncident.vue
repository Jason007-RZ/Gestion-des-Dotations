<script setup>
// Procès-verbal de perte ou de détérioration, imprimable.
import { ref, onMounted, onBeforeUnmount } from 'vue'
import api, { messageErreur } from '@/services/api'
import { imprimerDocument } from '@/services/impression'
import { dateCourte } from '@/militaires/format'
import { TYPES_INCIDENT } from './format'

const props = defineProps({ id: { type: Number, required: true } })
const emit = defineEmits(['fermer'])
const incident = ref(null)
const erreur = ref('')
const surTouche = (e) => e.key === 'Escape' && emit('fermer')
onMounted(async () => {
  window.addEventListener('keydown', surTouche)
  try {
    incident.value = (await api.get(`/incidents/${props.id}`)).data
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger le procès-verbal.')
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', surTouche)
  document.body.classList.remove('impression-bon')
})
</script>

<template>
  <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-[60] p-0 sm:p-4 backdrop-blur-sm" @click.self="emit('fermer')">
    <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-2xl w-full shadow-2xl max-h-[94vh] flex flex-col" role="dialog" aria-modal="true" :aria-label="incident ? 'Procès-verbal ' + incident.pv_numero : 'Procès-verbal'">
      <header class="px-5 sm:px-6 py-3 border-b border-gray-100 flex items-center gap-2">
        <h3 class="flex-1 text-base font-semibold text-gray-900">{{ incident ? 'Procès-verbal ' + incident.pv_numero : 'Procès-verbal' }}</h3>
        <button v-if="incident" type="button" @click="imprimerDocument" class="px-3 py-1.5 rounded-lg border border-gray-300 text-xs font-semibold hover:bg-gray-50">Imprimer</button>
        <button type="button" @click="emit('fermer')" aria-label="Fermer" class="p-2 -m-1 rounded-lg text-gray-500 hover:bg-gray-100 text-xl leading-none">×</button>
      </header>
      <div class="flex-1 overflow-y-auto px-5 sm:px-8 py-5">
        <p v-if="erreur" class="p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
        <p v-else-if="!incident" class="text-center text-sm text-gray-400 py-8">Chargement…</p>
        <article v-else class="zone-impression text-gray-900 text-sm">
          <div class="flex flex-wrap justify-between gap-4 border-b-2 border-gray-900 pb-3 mb-4">
            <p class="text-xs font-semibold uppercase tracking-wide">Ministère des Forces Armées</p>
            <div class="text-right">
              <p class="text-lg font-bold">Procès-verbal de {{ incident.type === 'perte' ? 'perte' : 'détérioration' }}</p>
              <p class="font-mono text-sm">{{ incident.pv_numero }}</p>
            </div>
          </div>
          <dl class="grid grid-cols-2 gap-x-4 gap-y-2 mb-4">
            <div><dt class="text-xs text-gray-500">Date</dt><dd class="font-medium">{{ dateCourte(incident.date) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Nature</dt><dd><span :class="TYPES_INCIDENT[incident.type]?.classe" class="text-xs px-2 py-0.5 rounded-full border">{{ TYPES_INCIDENT[incident.type]?.libelle }}</span></dd></div>
            <div class="col-span-2"><dt class="text-xs text-gray-500">Militaire</dt><dd class="font-medium">{{ incident.militaire?.grade?.libelle }} {{ incident.militaire?.nom }} {{ incident.militaire?.prenoms }} — {{ incident.militaire?.matricule }} ({{ incident.militaire?.unite?.code }})</dd></div>
            <div class="col-span-2"><dt class="text-xs text-gray-500">Effet concerné</dt><dd class="font-medium">{{ incident.ligne?.quantite }} × {{ incident.ligne?.article?.designation }}<span v-if="incident.ligne?.taille"> (taille {{ incident.ligne.taille.valeur }})</span> — perçu sur le bon {{ incident.ligne?.dotation?.numero_bon }} du {{ dateCourte(incident.ligne?.dotation?.date) }}</dd></div>
            <div class="col-span-2"><dt class="text-xs text-gray-500">Imputabilité</dt><dd class="font-medium">{{ incident.imputable ? 'Imputable au militaire' : 'Non imputable au militaire' }}</dd></div>
            <div v-if="incident.observation" class="col-span-2"><dt class="text-xs text-gray-500">Circonstances</dt><dd>{{ incident.observation }}</dd></div>
            <div class="col-span-2"><dt class="text-xs text-gray-500">Remplacement</dt><dd>{{ incident.remplacement ? `Bon ${incident.remplacement.numero_bon} du ${dateCourte(incident.remplacement.date)}` : 'Pas encore remplacé' }}</dd></div>
          </dl>
          <div class="grid grid-cols-3 gap-4 mt-8 text-xs">
            <div><p class="font-semibold">Le militaire</p><div class="h-14 border-b border-gray-400"></div></div>
            <div><p class="font-semibold">Le magasinier</p><div class="h-14 border-b border-gray-400"></div></div>
            <div><p class="font-semibold">Le chef d'unité</p><div class="h-14 border-b border-gray-400"></div></div>
          </div>
        </article>
      </div>
    </section>
  </div>
</template>
