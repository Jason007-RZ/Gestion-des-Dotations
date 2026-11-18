<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur } from '@/services/api'
import { ROLES } from '@/services/roles'
import { referentiels, chargerReferentiels } from '@/services/referentiels'
import ColumnChart from './components/ColumnChart.vue'
import { dateCourte } from './militaires/format'

const props = defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['alertes', 'aller'])
const role = computed(() => props.user?.role)
const admin = computed(() => role.value === ROLES.ADMIN)

const uniteId = ref('')
const donnees = ref(null)
const chargement = ref(true)
const erreur = ref('')

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    donnees.value = (await api.get('/tableau-de-bord', { params: { unite_id: uniteId.value || undefined } })).data
    emit('alertes', donnees.value.stock ? donnees.value.stock.alertes + donnees.value.stock.ruptures : 0)
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger le tableau de bord.')
  } finally {
    chargement.value = false
  }
}
watch(uniteId, charger)
onMounted(() => {
  if (admin.value) chargerReferentiels().catch(() => {})
  charger()
})

const tuiles = computed(() => {
  const d = donnees.value
  if (!d) return []
  return [
    { cle: 'echues', libelle: 'Renouvellements échus', valeur: d.echeances.echues, ton: d.echeances.echues ? 'rouge' : '', aller: 'mouvements', aide: 'Préparer les bons' },
    { cle: 'bientot', libelle: 'Échéances sous 30 jours', valeur: d.echeances.a_30_jours, ton: d.echeances.a_30_jours ? 'orange' : '' },
    { cle: 'incidents', libelle: 'Remplacements à faire', valeur: d.incidents_a_remplacer, ton: d.incidents_a_remplacer ? 'orange' : '', aller: 'demandes', aide: 'Voir les incidents' },
    { cle: 'mesurer', libelle: 'Militaires à mesurer', valeur: d.a_mesurer, ton: d.a_mesurer ? 'orange' : '', aller: 'effectifs', aide: 'Voir la liste' },
    { cle: 'initiale', libelle: 'Sans dotation initiale', valeur: d.sans_dotation_initiale, ton: d.sans_dotation_initiale ? 'orange' : '', aller: 'mouvements', aide: 'Préparer les bons' },
    { cle: 'actifs', libelle: 'Militaires actifs', valeur: d.effectif.actifs, sous: `${d.effectif.mutes} muté(s), ${d.effectif.radies} radié(s)` }
  ]
})
const classeTon = { rouge: 'text-red-700', orange: 'text-amber-700', '': 'text-gray-900' }
const sorties = computed(() =>
  (donnees.value?.sorties_par_semaine || []).map((s) => ({ cle: s.semaine, label: dateCourte(s.semaine).slice(0, 5), valeur: s.quantite }))
)
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-3 justify-between items-end mb-5">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Tableau de bord</h1>
        <p class="text-sm text-gray-500">
          <template v-if="role === 'chef_unite'">Votre unité{{ user?.unite ? ' : ' + (user.unite.libelle || user.unite.nom) : '' }}.</template>
          <template v-else>Échéances de renouvellement, actions en attente et état du stock.</template>
        </p>
      </div>
      <select v-if="admin" v-model="uniteId" aria-label="Unité" class="w-full sm:w-auto sm:max-w-xs border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white">
        <option value="">Toutes les unités</option>
        <option v-for="u in referentiels.unites" :key="u.id" :value="u.id">{{ u.code }} — {{ u.libelle }}</option>
      </select>
    </div>

    <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
    <div v-else-if="chargement && !donnees" class="p-8 text-center text-sm text-gray-400">Chargement…</div>

    <template v-if="donnees">
      <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-5" :class="chargement ? 'opacity-60' : ''">
        <component
          :is="t.aller ? 'button' : 'div'"
          v-for="t in tuiles"
          :key="t.cle"
          :type="t.aller ? 'button' : undefined"
          @click="t.aller && emit('aller', t.aller)"
          class="text-left bg-white rounded-2xl border border-gray-100 shadow-sm p-4"
          :class="t.aller ? 'hover:border-brand-300 hover:shadow transition' : ''"
        >
          <p class="text-xs text-gray-500">{{ t.libelle }}</p>
          <p class="text-2xl font-semibold tabular-nums" :class="classeTon[t.ton || '']">{{ t.valeur }}</p>
          <p v-if="t.sous" class="text-[11px] text-gray-400">{{ t.sous }}</p>
          <p v-else-if="t.aller && t.valeur" class="text-[11px] font-medium text-brand-700">{{ t.aide }} →</p>
        </component>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-5 gap-4">
        <!-- Renouvellements -->
        <section class="xl:col-span-3 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <header class="px-4 sm:px-5 pt-4 pb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">Renouvellements échus et à 30 jours</h2>
            <button v-if="role !== 'chef_unite' && donnees.renouvellements.length" type="button" @click="emit('aller', 'mouvements')" class="text-xs font-semibold text-brand-700 hover:underline">Préparer les bons →</button>
          </header>
          <p v-if="!donnees.renouvellements.length" class="px-5 pb-5 text-sm text-gray-400">Aucun renouvellement dans les 30 prochains jours.</p>
          <ul v-else class="divide-y divide-gray-100 max-h-[28rem] overflow-y-auto">
            <li v-for="r in donnees.renouvellements" :key="r.ligne_id" class="px-4 sm:px-5 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1">
              <span :class="r.statut === 'echue' ? 'bg-red-50 text-red-700 border-red-200' : 'bg-amber-50 text-amber-800 border-amber-200'" class="text-[11px] px-2 py-0.5 rounded-full border tabular-nums whitespace-nowrap w-[5.5rem] text-center">{{ dateCourte(r.date_echeance) }}</span>
              <span class="flex-1 min-w-[10rem] text-sm text-gray-900">{{ r.article?.designation }}<span v-if="r.taille" class="text-gray-500"> · {{ r.taille.valeur }}</span><span class="text-gray-500"> × {{ r.quantite }}</span></span>
              <span class="text-xs text-gray-600">{{ r.militaire?.nom }} {{ r.militaire?.prenoms }} <span class="text-gray-400">· {{ r.militaire?.unite?.code }}</span></span>
            </li>
          </ul>
        </section>

        <!-- Stock -->
        <section v-if="donnees.stock" class="xl:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
          <header class="px-4 sm:px-5 pt-4 pb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">Stock à surveiller</h2>
            <p class="text-xs text-gray-500">{{ donnees.stock.ruptures }} rupture(s) · {{ donnees.stock.alertes }} sous le seuil</p>
          </header>
          <p v-if="!donnees.stock.lignes.length" class="px-5 pb-5 text-sm text-gray-400">Aucune alerte de stock.</p>
          <ul v-else class="divide-y divide-gray-100">
            <li v-for="(l, i) in donnees.stock.lignes" :key="i" class="px-4 sm:px-5 py-2 flex items-center gap-3">
              <span class="flex-1 min-w-0 text-sm text-gray-900 truncate">{{ l.article?.designation }}<span v-if="l.taille" class="text-gray-500"> · {{ l.taille.valeur }}</span><span class="block text-[11px] text-gray-400 truncate">{{ l.magasin?.libelle }}</span></span>
              <span class="text-sm font-semibold tabular-nums" :class="l.quantite === 0 ? 'text-red-700' : 'text-amber-700'">{{ l.quantite }}</span>
              <span class="text-[11px] text-gray-400 w-14 text-right">seuil {{ l.seuil_alerte }}</span>
            </li>
          </ul>
          <button type="button" @click="emit('aller', 'stocks')" class="w-full px-5 py-2.5 border-t border-gray-100 text-xs font-semibold text-brand-700 hover:bg-gray-50 text-left">Voir le stock →</button>
        </section>
      </div>

      <div v-if="sorties.length" class="mt-4">
        <ColumnChart titre="Pièces remises par semaine" sous-titre="Sorties de stock par bons de dotation, 8 dernières semaines" :points="sorties" unite="pièces" />
      </div>
    </template>
  </div>
</template>
