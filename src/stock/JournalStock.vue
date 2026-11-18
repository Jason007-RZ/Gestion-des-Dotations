<script setup>
// Journal des mouvements de stock d'un magasin (entrées, sorties, réintégrations, réformes).
import { ref, watch } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import Pagination from '@/components/Pagination.vue'
import { dateCourte } from '@/militaires/format'

const props = defineProps({ magasinId: { type: Number, default: null } })

const TYPES = {
  entree: { libelle: 'Entrée', signe: '+', classe: 'bg-brand-50 text-brand-700 border-brand-200/60' },
  sortie: { libelle: 'Sortie', signe: '−', classe: 'bg-sky-50 text-sky-800 border-sky-200' },
  reintegration: { libelle: 'Réintégration', signe: '+', classe: 'bg-violet-50 text-violet-800 border-violet-200' },
  reforme: { libelle: 'Réforme', signe: '−', classe: 'bg-gray-100 text-gray-700 border-gray-300' }
}

const type = ref('')
const reference = ref('')
const du = ref('')
const au = ref('')
const page = ref(1)
const mouvements = ref([])
const pages = ref(1)
const total = ref(0)
const chargement = ref(false)
const erreur = ref('')

async function charger() {
  if (!props.magasinId) return
  chargement.value = true
  erreur.value = ''
  try {
    const { data } = await api.get('/mouvements-stock', {
      params: { magasin_id: props.magasinId, type: type.value || undefined, reference: reference.value.trim() || undefined, du: du.value || undefined, au: au.value || undefined, page: page.value, par_page: 25 }
    })
    mouvements.value = enListe(data)
    pages.value = data.last_page || 1
    total.value = data.total ?? mouvements.value.length
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger le journal.')
  } finally {
    chargement.value = false
  }
}
let minuterie = null
watch(reference, () => {
  clearTimeout(minuterie)
  minuterie = setTimeout(() => (page.value === 1 ? charger() : (page.value = 1)), 300)
})
watch([type, du, au, () => props.magasinId], () => (page.value === 1 ? charger() : (page.value = 1)))
watch(page, charger)
charger()
defineExpose({ charger })

const heure = (iso) => String(iso || '').slice(11, 16)
</script>

<template>
  <div>
    <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100 mb-4 space-y-3">
      <div class="flex gap-2 overflow-x-auto pb-1 -mb-1">
        <button type="button" @click="type = ''" :class="type === '' ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">Tous</button>
        <button v-for="(t, cle) in TYPES" :key="cle" type="button" @click="type = cle" :class="type === cle ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">{{ t.libelle }}s</button>
      </div>
      <div class="flex flex-wrap gap-2">
        <input v-model="reference" type="search" aria-label="Référence" placeholder="N° de bon ou de PV…" class="flex-1 min-w-[10rem] px-3 py-2 bg-gray-50/70 border border-gray-200 rounded-xl text-sm" />
        <label class="flex items-center gap-1.5 text-xs text-gray-600">Du <input v-model="du" type="date" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="flex items-center gap-1.5 text-xs text-gray-600">au <input v-model="au" type="date" :min="du || undefined" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
      </div>
    </div>

    <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div v-if="chargement && !mouvements.length" class="p-8 text-center text-sm text-gray-400">Chargement…</div>
      <div v-else-if="!mouvements.length" class="p-8 text-center text-sm text-gray-400">Aucun mouvement.</div>
      <ul v-else class="divide-y divide-gray-100" :class="chargement ? 'opacity-60' : ''">
        <li v-for="m in mouvements" :key="m.id" class="px-4 sm:px-5 py-3 flex flex-wrap items-start gap-x-4 gap-y-1">
          <div class="w-24 shrink-0 text-xs text-gray-500 tabular-nums">{{ dateCourte(m.date) }}<span class="block">{{ heure(m.date) }}</span></div>
          <div class="flex-1 min-w-[12rem]">
            <p class="text-sm text-gray-900">
              <span :class="TYPES[m.type]?.classe" class="text-[11px] px-2 py-0.5 rounded-full border mr-1.5 align-middle">{{ TYPES[m.type]?.libelle || m.type }}</span>
              {{ m.article?.designation }}<span v-if="m.taille" class="text-gray-500"> · {{ m.taille.valeur }}</span>
            </p>
            <p class="text-xs text-gray-500 mt-0.5"><span class="font-mono">{{ m.reference || '—' }}</span> · {{ m.user?.name }}<span v-if="m.motif"> · {{ m.motif }}</span></p>
          </div>
          <p class="text-sm font-semibold tabular-nums" :class="TYPES[m.type]?.signe === '−' ? 'text-gray-700' : 'text-brand-700'">{{ TYPES[m.type]?.signe }}{{ m.quantite }}</p>
        </li>
      </ul>
      <Pagination v-model:page="page" :pages="pages" :total="total" />
    </div>
  </div>
</template>
