<script setup>
// Stock des magasins (refonte, cahier des charges : tables stocks et mouvements_stock).
// Administrateur : tous les magasins. Magasinier : ses magasins. Entrées, réformes, seuils d'alerte, journal.
import { ref, computed, watch, onMounted } from 'vue'
import { ROLES } from '@/services/roles'
import { referentiels, chargerReferentiels } from '@/services/referentiels'
import EtatStock from './stock/EtatStock.vue'
import BonStock from './stock/BonStock.vue'
import JournalStock from './stock/JournalStock.vue'

const props = defineProps({ user: { type: Object, default: null } })
const admin = computed(() => props.user?.role === ROLES.ADMIN)

// Magasins accessibles : tous pour l'administrateur, ceux du rattachement pour le magasinier.
const magasins = computed(() => (admin.value ? referentiels.magasins : props.user?.magasins || []))
const lireChoix = () => {
  try {
    return Number(sessionStorage.getItem('stock_magasin')) || null
  } catch {
    return null
  }
}
const magasinId = ref(lireChoix())
watch(
  magasins,
  (liste) => {
    if (!liste.some((m) => m.id === magasinId.value)) magasinId.value = liste[0]?.id ?? null
  },
  { immediate: true }
)
watch(magasinId, (id) => {
  try {
    sessionStorage.setItem('stock_magasin', String(id))
  } catch {
    /* préférence non mémorisée */
  }
})
const magasin = computed(() => magasins.value.find((m) => m.id === magasinId.value) || null)

const vue = ref('etat') // 'etat' | 'journal'
const bon = ref(null) // 'entree' | 'reforme' | null
const etat = ref(null)
const journal = ref(null)
const lignes = ref([]) // état du stock courant, partagé avec le formulaire de bon

function apresBon() {
  bon.value = null
  etat.value?.charger()
  journal.value?.charger()
}

onMounted(() => chargerReferentiels().catch(() => {}))
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-3 justify-between items-end mb-5">
      <div class="min-w-0">
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Stock des magasins</h1>
        <p class="text-sm text-gray-500">Quantités par article et par taille, entrées, réformes et seuils d'alerte.</p>
      </div>
      <div v-if="magasin" class="flex flex-wrap gap-2">
        <button type="button" @click="bon = 'entree'" class="bg-brand-600 hover:bg-brand-700 text-white font-medium px-4 py-2 rounded-xl text-sm shadow-sm">+ Entrée en stock</button>
        <button type="button" @click="bon = 'reforme'" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 font-medium px-4 py-2 rounded-xl text-sm">Réforme</button>
      </div>
    </div>

    <div v-if="!magasins.length" class="bg-white rounded-2xl border border-gray-100 p-8 text-center text-sm text-gray-500">
      <template v-if="admin">Aucun magasin : créez-en un dans « Catalogue et dotation » › « Unités et magasins ».</template>
      <template v-else>Vous n'êtes rattaché à aucun magasin. Demandez à l'administrateur de vous rattacher.</template>
    </div>

    <template v-else>
      <div class="flex flex-wrap items-center gap-3 mb-4">
        <label v-if="magasins.length > 1" class="flex-1 min-w-[14rem] max-w-md">
          <span class="sr-only">Magasin</span>
          <select v-model="magasinId" aria-label="Magasin" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white font-medium">
            <option v-for="m in magasins" :key="m.id" :value="m.id">{{ m.libelle }}</option>
          </select>
        </label>
        <p v-else class="text-sm font-medium text-gray-800">{{ magasin?.libelle }}</p>
        <div class="flex rounded-xl bg-gray-100 p-1 text-sm ml-auto" role="tablist" aria-label="Affichage">
          <button type="button" role="tab" :aria-selected="vue === 'etat'" @click="vue = 'etat'" :class="vue === 'etat' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600'" class="px-3 py-1.5 rounded-lg font-medium">État du stock</button>
          <button type="button" role="tab" :aria-selected="vue === 'journal'" @click="vue = 'journal'" :class="vue === 'journal' ? 'bg-white shadow-sm text-gray-900' : 'text-gray-600'" class="px-3 py-1.5 rounded-lg font-medium">Journal</button>
        </div>
      </div>

      <EtatStock v-show="vue === 'etat'" ref="etat" :magasin-id="magasinId" @lignes="lignes = $event" />
      <JournalStock v-if="vue === 'journal'" ref="journal" :magasin-id="magasinId" />
    </template>

    <BonStock v-if="bon && magasin" :mode="bon" :magasin="magasin" :lignes="lignes" @fermer="bon = null" @enregistre="apresBon" />
  </div>
</template>
