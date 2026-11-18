<script setup>
// Prévisions des besoins : renouvellements attendus sur une période (échéances des lignes en service),
// plus les retards, comparés au stock de tous les magasins. Administrateur (filtre unité) et chef d'unité (son unité).
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur } from '@/services/api'
import { ROLES } from '@/services/roles'
import { referentiels, chargerReferentiels } from '@/services/referentiels'
import ColumnChart from './components/ColumnChart.vue'
import { dateCourte } from './militaires/format'

const props = defineProps({ user: { type: Object, default: null } })
const admin = computed(() => props.user?.role === ROLES.ADMIN)

const aujourdhui = () => new Date().toISOString().slice(0, 10)
const plusMois = (iso, n) => {
  const d = new Date(iso + 'T00:00:00Z')
  d.setUTCMonth(d.getUTCMonth() + n)
  return d.toISOString().slice(0, 10)
}
const horizon = ref(12) // mois, ou 'perso'
const du = ref(aujourdhui())
const au = ref(plusMois(aujourdhui(), 12))
watch(horizon, (h) => {
  if (h === 'perso') return
  du.value = aujourdhui()
  au.value = plusMois(du.value, h)
})
const uniteId = ref('')

const donnees = ref(null)
const chargement = ref(false)
const erreur = ref('')
async function charger() {
  if (!du.value || !au.value || au.value < du.value) return
  chargement.value = true
  erreur.value = ''
  try {
    donnees.value = (await api.get('/previsions', { params: { du: du.value, au: au.value, unite_id: uniteId.value || undefined } })).data
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de calculer les prévisions.')
  } finally {
    chargement.value = false
  }
}
watch([du, au, uniteId], charger)
onMounted(() => {
  if (admin.value) chargerReferentiels().catch(() => {})
  charger()
})

const points = computed(() =>
  (donnees.value?.par_mois || []).map((m) => ({ cle: m.mois, label: m.mois.slice(5) + '/' + m.mois.slice(2, 4), valeur: m.quantite }))
)
const totaux = computed(() => {
  const l = donnees.value?.lignes || []
  return { renouveler: l.reduce((s, x) => s + x.a_renouveler, 0), retard: l.reduce((s, x) => s + x.en_retard, 0), commander: l.reduce((s, x) => s + x.a_commander, 0) }
})
</script>

<template>
  <div>
    <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight mb-1">Prévisions des besoins</h1>
    <p class="text-sm text-gray-500 mb-5">Effets à renouveler sur la période (d'après les échéances), comparés au stock de tous les magasins.</p>

    <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100 mb-4 flex flex-wrap items-end gap-3">
      <div class="flex gap-2 flex-wrap" role="group" aria-label="Horizon">
        <button v-for="h in [3, 6, 12, 24]" :key="h" type="button" :aria-pressed="horizon === h" @click="horizon = h" :class="horizon === h ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold">{{ h }} mois</button>
        <button type="button" :aria-pressed="horizon === 'perso'" @click="horizon = 'perso'" :class="horizon === 'perso' ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold">Dates précises</button>
      </div>
      <template v-if="horizon === 'perso'">
        <label class="text-xs text-gray-600 flex items-center gap-1.5">Du <input v-model="du" type="date" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="text-xs text-gray-600 flex items-center gap-1.5">au <input v-model="au" type="date" :min="du" class="border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
      </template>
      <select v-if="admin" v-model="uniteId" aria-label="Unité" class="w-full sm:w-auto sm:max-w-xs sm:ml-auto border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white">
        <option value="">Toutes les unités</option>
        <option v-for="u in referentiels.unites" :key="u.id" :value="u.id">{{ u.code }} — {{ u.libelle }}</option>
      </select>
    </div>

    <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
    <div v-else-if="chargement && !donnees" class="p-8 text-center text-sm text-gray-400">Calcul…</div>

    <template v-if="donnees">
      <div class="grid grid-cols-3 gap-2 sm:gap-3 mb-4" :class="chargement ? 'opacity-60' : ''">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 sm:p-4"><p class="text-xs text-gray-500">À renouveler</p><p class="text-xl sm:text-2xl font-semibold tabular-nums">{{ totaux.renouveler }}</p><p class="text-[11px] text-gray-400">du {{ dateCourte(donnees.du) }} au {{ dateCourte(donnees.au) }}</p></div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 sm:p-4"><p class="text-xs text-gray-500">En retard</p><p class="text-xl sm:text-2xl font-semibold tabular-nums" :class="totaux.retard ? 'text-red-700' : ''">{{ totaux.retard }}</p><p class="text-[11px] text-gray-400">déjà échus</p></div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 sm:p-4"><p class="text-xs text-gray-500">À commander</p><p class="text-xl sm:text-2xl font-semibold tabular-nums" :class="totaux.commander ? 'text-amber-700' : ''">{{ totaux.commander }}</p><p class="text-[11px] text-gray-400">au-delà du stock</p></div>
      </div>

      <ColumnChart titre="Renouvellements par mois" sous-titre="Nombre de pièces arrivant à échéance" :points="points" unite="pièces" :chargement="chargement" />

      <section class="mt-4 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <h2 class="px-4 sm:px-5 pt-4 pb-2 text-base font-semibold text-gray-900">Besoins par article et par taille</h2>
        <p v-if="!donnees.lignes.length" class="px-5 pb-5 text-sm text-gray-400">Aucun renouvellement sur la période.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs text-gray-500 border-y border-gray-100 bg-gray-50/70">
                <th class="text-left font-semibold px-4 sm:px-5 py-2">Article</th>
                <th class="text-right font-semibold px-2 py-2">À renouveler</th>
                <th class="text-right font-semibold px-2 py-2">En retard</th>
                <th class="text-right font-semibold px-2 py-2">Stock</th>
                <th class="text-right font-semibold px-4 sm:px-5 py-2">À commander</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="(l, i) in donnees.lignes" :key="i">
                <td class="px-4 sm:px-5 py-2">{{ l.article?.designation }}<span v-if="l.taille" class="text-gray-500"> · {{ l.taille.valeur }}</span></td>
                <td class="text-right px-2 py-2 tabular-nums">{{ l.a_renouveler }}</td>
                <td class="text-right px-2 py-2 tabular-nums" :class="l.en_retard ? 'text-red-700 font-semibold' : 'text-gray-400'">{{ l.en_retard }}</td>
                <td class="text-right px-2 py-2 tabular-nums text-gray-600">{{ l.stock }}</td>
                <td class="text-right px-4 sm:px-5 py-2 tabular-nums font-semibold" :class="l.a_commander ? 'text-amber-700' : 'text-gray-400'">{{ l.a_commander }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>
