<script setup>
// Détail d'un bon de dotation, imprimable (format A4, signatures du magasinier et du bénéficiaire).
import { ref, onMounted, onBeforeUnmount } from 'vue'
import api, { messageErreur } from '@/services/api'
import { dateCourte } from '@/militaires/format'
import { imprimerDocument } from '@/services/impression'
import { TYPES_BON, ETATS_LIGNE } from './format'

const props = defineProps({ id: { type: Number, required: true } })
const emit = defineEmits(['fermer'])

const bon = ref(null)
const erreur = ref('')
onMounted(async () => {
  try {
    bon.value = (await api.get(`/dotations/${props.id}`)).data
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger le bon.')
  }
  window.addEventListener('keydown', surTouche)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', surTouche)
  document.body.classList.remove('impression-bon')
})
const surTouche = (e) => e.key === 'Escape' && emit('fermer')

</script>

<template>
  <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-[60] p-0 sm:p-4 backdrop-blur-sm" @click.self="emit('fermer')">
    <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-3xl w-full shadow-2xl max-h-[94vh] flex flex-col" role="dialog" aria-modal="true" :aria-label="bon ? 'Bon ' + bon.numero_bon : 'Bon de dotation'">
      <header class="px-5 sm:px-6 py-3 border-b border-gray-100 flex items-center gap-2">
        <h3 class="flex-1 text-base font-semibold text-gray-900">{{ bon ? 'Bon ' + bon.numero_bon : 'Bon de dotation' }}</h3>
        <button v-if="bon" type="button" @click="imprimerDocument" class="px-3 py-1.5 rounded-lg border border-gray-300 text-xs font-semibold hover:bg-gray-50">Imprimer</button>
        <button type="button" @click="emit('fermer')" aria-label="Fermer" class="p-2 -m-1 rounded-lg text-gray-500 hover:bg-gray-100 text-xl leading-none">×</button>
      </header>

      <div class="flex-1 overflow-y-auto px-5 sm:px-8 py-5">
        <p v-if="erreur" class="p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
        <p v-else-if="!bon" class="text-center text-sm text-gray-400 py-8">Chargement…</p>

        <article v-else class="zone-impression text-gray-900">
          <div class="flex flex-wrap justify-between gap-4 border-b-2 border-gray-900 pb-3 mb-4">
            <div class="text-xs leading-snug">
              <p class="font-semibold uppercase tracking-wide">Ministère des Forces Armées</p>
              <p>{{ bon.magasin?.libelle }}</p>
            </div>
            <div class="text-right">
              <p class="text-lg font-bold">Bon de dotation</p>
              <p class="font-mono text-sm">{{ bon.numero_bon }}</p>
            </div>
          </div>

          <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-2 text-sm mb-5">
            <div><dt class="text-xs text-gray-500">Date</dt><dd class="font-medium">{{ dateCourte(bon.date) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Type</dt><dd><span :class="TYPES_BON[bon.type]?.classe" class="text-xs px-2 py-0.5 rounded-full border">{{ TYPES_BON[bon.type]?.libelle }}</span></dd></div>
            <div class="col-span-2"><dt class="text-xs text-gray-500">Bénéficiaire</dt><dd class="font-medium">{{ bon.militaire?.grade?.libelle }} {{ bon.militaire?.nom }} {{ bon.militaire?.prenoms }}</dd></div>
            <div><dt class="text-xs text-gray-500">Matricule</dt><dd class="font-mono">{{ bon.militaire?.matricule }}</dd></div>
            <div><dt class="text-xs text-gray-500">Unité</dt><dd>{{ bon.militaire?.unite?.code }}</dd></div>
            <div class="col-span-2"><dt class="text-xs text-gray-500">Magasinier</dt><dd>{{ bon.magasinier?.name }}</dd></div>
          </dl>

          <table class="w-full text-sm border-collapse">
            <thead>
              <tr class="border-y border-gray-300 text-xs text-gray-600">
                <th class="text-left py-2 pr-2">Article</th>
                <th class="text-center py-2 px-2">Taille</th>
                <th class="text-center py-2 px-2">Qté</th>
                <th class="text-left py-2 px-2">Échéance</th>
                <th class="text-left py-2 pl-2 hidden sm:table-cell imprime-cellule">État</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in bon.lignes" :key="l.id" class="border-b border-gray-100">
                <td class="py-1.5 pr-2">{{ l.article?.designation }} <span class="text-[11px] font-mono text-gray-400">{{ l.article?.code }}</span></td>
                <td class="text-center py-1.5 px-2 tabular-nums">{{ l.taille?.valeur ?? '—' }}</td>
                <td class="text-center py-1.5 px-2 tabular-nums">{{ l.quantite }}</td>
                <td class="py-1.5 px-2 whitespace-nowrap">{{ l.date_echeance ? dateCourte(l.date_echeance) : 'À réintégrer' }}</td>
                <td class="py-1.5 pl-2 hidden sm:table-cell imprime-cellule" :class="ETATS_LIGNE[l.etat]?.classe">{{ ETATS_LIGNE[l.etat]?.libelle }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="text-xs text-gray-600"><td class="pt-2" colspan="5">{{ bon.nb_lignes }} ligne(s), {{ bon.nb_pieces }} pièce(s)</td></tr>
            </tfoot>
          </table>

          <p v-if="bon.observation" class="mt-3 text-sm"><span class="text-xs text-gray-500">Observation :</span> {{ bon.observation }}</p>

          <div class="grid grid-cols-2 gap-6 mt-8 text-xs">
            <div><p class="font-semibold">Le magasinier</p><div class="h-16 border-b border-gray-400"></div></div>
            <div><p class="font-semibold">Le bénéficiaire (« reçu les effets ci-dessus »)</p><div class="h-16 border-b border-gray-400"></div></div>
          </div>
        </article>
      </div>
    </section>
  </div>
</template>
