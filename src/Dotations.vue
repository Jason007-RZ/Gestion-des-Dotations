<script setup>
// Bons de dotation (refonte, cahier des charges : tables dotations et dotation_lignes).
// Administrateur et magasinier : création et consultation. Chef d'unité : bons de son unité. Militaire : ses bons.
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { ROLES } from '@/services/roles'
import { chargerReferentiels } from '@/services/referentiels'
import Pagination from './components/Pagination.vue'
import NouveauBon from './dotations/NouveauBon.vue'
import DetailBon from './dotations/DetailBon.vue'
import { TYPES_BON } from './dotations/format'
import { dateCourte } from './militaires/format'

const props = defineProps({ user: { type: Object, default: null } })
const role = computed(() => props.user?.role)
const peutCreer = computed(() => [ROLES.ADMIN, ROLES.MAGASINIER].includes(role.value))

const recherche = ref('')
const type = ref('')
const page = ref(1)
const bons = ref([])
const pages = ref(1)
const total = ref(0)
const chargement = ref(true)
const erreur = ref('')

let jeton = 0
async function charger() {
  const moi = ++jeton
  chargement.value = true
  erreur.value = ''
  try {
    const { data } = await api.get('/dotations', { params: { q: recherche.value.trim() || undefined, type: type.value || undefined, page: page.value, par_page: 20 } })
    if (moi !== jeton) return
    bons.value = enListe(data)
    pages.value = data.last_page || 1
    total.value = data.total ?? bons.value.length
  } catch (e) {
    if (moi === jeton) erreur.value = messageErreur(e, 'Impossible de charger les bons.')
  } finally {
    if (moi === jeton) chargement.value = false
  }
}
let minuterie = null
watch(recherche, () => {
  clearTimeout(minuterie)
  minuterie = setTimeout(() => (page.value === 1 ? charger() : (page.value = 1)), 300)
})
watch(type, () => (page.value === 1 ? charger() : (page.value = 1)))
watch(page, charger)

const nouveau = ref(false)
const detailId = ref(null)
function apresCreation(id) {
  nouveau.value = false
  page.value === 1 ? charger() : (page.value = 1)
  detailId.value = id
}

onMounted(() => {
  if (peutCreer.value) chargerReferentiels().catch(() => {})
  charger()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-3 justify-between items-end mb-5">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Bons de dotation</h1>
        <p class="text-sm text-gray-500">
          <template v-if="role === 'militaire'">Les effets qui vous ont été remis.</template>
          <template v-else-if="role === 'chef_unite'">Bons des militaires de votre unité.</template>
          <template v-else>Dotation initiale, renouvellements et remplacements, avec contrôle du stock.</template>
        </p>
      </div>
      <button v-if="peutCreer" type="button" @click="nouveau = true" class="bg-brand-600 hover:bg-brand-700 text-white font-medium px-4 py-2 rounded-xl text-sm shadow-sm">+ Nouveau bon</button>
    </div>

    <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100 mb-4 space-y-3">
      <input v-if="role !== 'militaire'" v-model="recherche" type="search" aria-label="Rechercher un bon" placeholder="N° de bon, matricule ou nom…" class="w-full px-4 py-2 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500" />
      <div class="flex gap-2 overflow-x-auto pb-1 -mb-1">
        <button type="button" @click="type = ''" :class="type === '' ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">Tous</button>
        <button v-for="(t, cle) in TYPES_BON" :key="cle" type="button" @click="type = cle" :class="type === cle ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">{{ t.libelle }}</button>
      </div>
    </div>

    <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div v-if="chargement && !bons.length" class="p-8 text-center text-sm text-gray-400">Chargement…</div>
      <div v-else-if="!bons.length" class="p-8 text-center text-sm text-gray-400">Aucun bon.</div>
      <ul v-else class="divide-y divide-gray-100" :class="chargement ? 'opacity-60' : ''">
        <li v-for="b in bons" :key="b.id">
          <button type="button" @click="detailId = b.id" class="w-full text-left px-4 sm:px-5 py-3 hover:bg-gray-50/70 flex flex-wrap items-center gap-x-4 gap-y-1">
            <span class="w-36 shrink-0">
              <span class="block font-mono text-xs text-gray-700">{{ b.numero_bon }}</span>
              <span class="block text-xs text-gray-500">{{ dateCourte(b.date) }}</span>
            </span>
            <span class="flex-1 min-w-[12rem]">
              <span class="block text-sm font-medium text-gray-900">{{ b.militaire?.nom }} {{ b.militaire?.prenoms }}</span>
              <span class="block text-xs text-gray-500">{{ b.militaire?.matricule }} · {{ b.militaire?.grade?.libelle }} · {{ b.militaire?.unite?.code }}</span>
            </span>
            <span :class="TYPES_BON[b.type]?.classe" class="text-xs px-2.5 py-0.5 rounded-full border whitespace-nowrap">{{ TYPES_BON[b.type]?.libelle }}</span>
            <span class="w-24 text-right text-xs text-gray-600 tabular-nums">{{ b.nb_pieces }} pièce(s)</span>
          </button>
        </li>
      </ul>
      <Pagination v-model:page="page" :pages="pages" :total="total" />
    </div>

    <NouveauBon v-if="nouveau" :user="user" @fermer="nouveau = false" @cree="apresCreation" />
    <DetailBon v-if="detailId" :id="detailId" @fermer="detailId = null" />
  </div>
</template>
