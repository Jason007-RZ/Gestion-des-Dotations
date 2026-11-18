<script setup>
// Incidents et retours (refonte, cahier des charges : table incidents, réintégration, clôture du dossier).
// Administrateur et magasinier : déclarer, remplacer, réintégrer. Chef d'unité : incidents de son unité. Militaire : les siens.
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { ROLES } from '@/services/roles'
import { chargerReferentiels } from '@/services/referentiels'
import Pagination from './components/Pagination.vue'
import DossierMilitaire from './incidents/DossierMilitaire.vue'
import DetailIncident from './incidents/DetailIncident.vue'
import Remplacement from './incidents/Remplacement.vue'
import DetailBon from './dotations/DetailBon.vue'
import { TYPES_INCIDENT } from './incidents/format'
import { dateCourte } from './militaires/format'

const props = defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['maj'])
const role = computed(() => props.user?.role)
const peutAgir = computed(() => [ROLES.ADMIN, ROLES.MAGASINIER].includes(role.value))

const vue = ref('incidents') // 'incidents' | 'dossier'

const recherche = ref('')
const type = ref('')
const aRemplacer = ref(false)
const page = ref(1)
const incidents = ref([])
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
    const { data } = await api.get('/incidents', {
      params: { q: recherche.value.trim() || undefined, type: type.value || undefined, a_remplacer: aRemplacer.value ? 1 : undefined, page: page.value, par_page: 20 }
    })
    if (moi !== jeton) return
    incidents.value = enListe(data)
    pages.value = data.last_page || 1
    total.value = data.total ?? incidents.value.length
  } catch (e) {
    if (moi === jeton) erreur.value = messageErreur(e, 'Impossible de charger les incidents.')
  } finally {
    if (moi === jeton) chargement.value = false
  }
}
let minuterie = null
watch(recherche, () => {
  clearTimeout(minuterie)
  minuterie = setTimeout(() => (page.value === 1 ? charger() : (page.value = 1)), 300)
})
watch([type, aRemplacer], () => (page.value === 1 ? charger() : (page.value = 1)))
watch(page, charger)

const pvId = ref(null)
const aRemplacerIncident = ref(null)
const bonId = ref(null)
function apresRemplacement(id) {
  aRemplacerIncident.value = null
  bonId.value = id
  charger()
  emit('maj')
}
function apresIncident() {
  charger()
  emit('maj')
}

onMounted(() => {
  if (peutAgir.value) chargerReferentiels().catch(() => {})
  charger()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-3 justify-between items-end mb-5">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Incidents et retours</h1>
        <p class="text-sm text-gray-500">Pertes et détériorations (avec PV), remplacements, réintégrations et clôture des dossiers.</p>
      </div>
      <div v-if="peutAgir" class="flex rounded-xl bg-gray-100 p-1 text-sm" role="tablist" aria-label="Affichage">
        <button type="button" role="tab" :aria-selected="vue === 'incidents'" @click="vue = 'incidents'" :class="vue === 'incidents' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600'" class="px-3 py-1.5 rounded-lg font-medium">Incidents</button>
        <button type="button" role="tab" :aria-selected="vue === 'dossier'" @click="vue = 'dossier'" :class="vue === 'dossier' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600'" class="px-3 py-1.5 rounded-lg font-medium">Dossier d'un militaire</button>
      </div>
    </div>

    <DossierMilitaire v-if="vue === 'dossier' && peutAgir" :user="user" @incident="apresIncident" />

    <template v-else>
      <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100 mb-4 space-y-3">
        <input v-if="role !== 'militaire'" v-model="recherche" type="search" aria-label="Rechercher un incident" placeholder="N° de PV, matricule ou nom…" class="w-full px-4 py-2 bg-gray-50/70 border border-gray-200 rounded-xl text-sm" />
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" @click="type = ''" :class="type === '' ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold">Tous</button>
          <button v-for="(t, cle) in TYPES_INCIDENT" :key="cle" type="button" @click="type = cle" :class="type === cle ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold">{{ t.libelle }}s</button>
          <label class="ml-auto flex items-center gap-1.5 text-xs text-gray-600"><input v-model="aRemplacer" type="checkbox" class="accent-brand-600" /> Remplacement à faire</label>
        </div>
      </div>

      <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div v-if="chargement && !incidents.length" class="p-8 text-center text-sm text-gray-400">Chargement…</div>
        <div v-else-if="!incidents.length" class="p-8 text-center text-sm text-gray-400">Aucun incident.</div>
        <ul v-else class="divide-y divide-gray-100" :class="chargement ? 'opacity-60' : ''">
          <li v-for="i in incidents" :key="i.id" class="px-4 sm:px-5 py-3 flex flex-wrap items-start gap-x-4 gap-y-2">
            <div class="w-32 shrink-0">
              <p class="font-mono text-xs text-gray-700">{{ i.pv_numero }}</p>
              <p class="text-xs text-gray-500">{{ dateCourte(i.date) }}</p>
            </div>
            <div class="flex-1 min-w-[12rem]">
              <p class="text-sm text-gray-900">
                <span :class="TYPES_INCIDENT[i.type]?.classe" class="text-[11px] px-2 py-0.5 rounded-full border mr-1.5 align-middle">{{ TYPES_INCIDENT[i.type]?.libelle }}</span>
                {{ i.ligne?.quantite }} × {{ i.ligne?.article?.designation }}<span v-if="i.ligne?.taille" class="text-gray-500"> · {{ i.ligne.taille.valeur }}</span>
              </p>
              <p class="text-xs text-gray-500 mt-0.5">{{ i.militaire?.nom }} {{ i.militaire?.prenoms }} · {{ i.militaire?.matricule }} · {{ i.militaire?.unite?.code }} · {{ i.imputable ? 'imputable' : 'non imputable' }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-xs font-medium">
              <span v-if="i.remplacement" class="text-brand-700">Remplacé ({{ i.remplacement.numero_bon }})</span>
              <button v-else-if="peutAgir" type="button" @click="aRemplacerIncident = i" class="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-700 text-white">Bon de remplacement</button>
              <span v-else class="text-amber-700">À remplacer</span>
              <button type="button" @click="pvId = i.id" class="text-gray-600 hover:text-gray-900 underline-offset-2 hover:underline">Voir le PV</button>
            </div>
          </li>
        </ul>
        <Pagination v-model:page="page" :pages="pages" :total="total" />
      </div>
    </template>

    <DetailIncident v-if="pvId" :id="pvId" @fermer="pvId = null" />
    <Remplacement v-if="aRemplacerIncident" :incident="aRemplacerIncident" :user="user" @fermer="aRemplacerIncident = null" @cree="apresRemplacement" />
    <DetailBon v-if="bonId" :id="bonId" @fermer="bonId = null" />
  </div>
</template>
