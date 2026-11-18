<script setup>
// Espace du militaire (cahier des charges §4 : « consultation de son propre paquetage »).
import { ref, computed, onMounted } from 'vue'
import api, { messageErreur } from '@/services/api'
import { STATUTS, dateCourte, anciennete } from './militaires/format'
import { ECHEANCES } from './dotations/format'

defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['aller'])

const donnees = ref(null)
const erreur = ref('')
onMounted(async () => {
  try {
    donnees.value = (await api.get('/mon-espace')).data
  } catch (e) {
    erreur.value = e?.response?.status === 404 ? "Votre compte n'est relié à aucune fiche militaire. Contactez l'administrateur." : messageErreur(e, 'Impossible de charger votre espace.')
  }
})
const m = computed(() => donnees.value?.militaire)
const mesures = computed(() => m.value?.mensuration_actuelle)
</script>

<template>
  <div>
    <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight mb-1">Mon paquetage</h1>
    <p class="text-sm text-gray-500 mb-5">Les effets qui vous ont été remis et leurs dates de renouvellement.</p>

    <p v-if="erreur" class="p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
    <div v-else-if="!donnees" class="p-8 text-center text-sm text-gray-400">Chargement…</div>

    <template v-else>
      <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5 mb-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p class="text-lg font-semibold text-gray-900">{{ m.grade?.libelle }} {{ m.nom }} {{ m.prenoms }}</p>
            <p class="text-xs text-gray-500"><span class="font-mono">{{ m.matricule }}</span> · {{ m.unite?.code }} — {{ m.unite?.libelle }} · incorporé il y a {{ anciennete(m.date_incorporation) }}</p>
          </div>
          <span :class="STATUTS[m.statut]?.classe" class="text-xs px-2 py-0.5 rounded-full border">{{ STATUTS[m.statut]?.libelle }}</span>
        </div>
        <div class="grid grid-cols-3 gap-2 mt-4">
          <div v-for="x in [{ l: 'Vêtement', v: mesures?.taille_vetement?.valeur }, { l: 'Pointure', v: mesures?.pointure?.valeur }, { l: 'Tour de tête', v: mesures?.tour_tete?.valeur }]" :key="x.l" class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2 text-center">
            <p class="text-[11px] text-gray-500">{{ x.l }}</p>
            <p class="text-lg font-semibold tabular-nums" :class="x.v ? 'text-gray-900' : 'text-gray-300'">{{ x.v ?? '—' }}</p>
          </div>
        </div>
        <p class="text-[11px] text-gray-400 mt-2">{{ mesures ? 'Mesuré le ' + dateCourte(mesures.date_mesure) + '. Une taille a changé ? Signalez-le au magasin.' : 'Pas encore mesuré : présentez-vous au magasin.' }}</p>
      </section>

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 sm:gap-3 mb-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3"><p class="text-xs text-gray-500">Articles en service</p><p class="text-xl font-semibold tabular-nums">{{ donnees.resume.articles }}</p></div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3"><p class="text-xs text-gray-500">À renouveler</p><p class="text-xl font-semibold tabular-nums" :class="donnees.resume.echus ? 'text-red-700' : ''">{{ donnees.resume.echus }}</p></div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3"><p class="text-xs text-gray-500">Sous 30 jours</p><p class="text-xl font-semibold tabular-nums" :class="donnees.resume.a_30_jours ? 'text-amber-700' : ''">{{ donnees.resume.a_30_jours }}</p></div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3"><p class="text-xs text-gray-500">À rendre au départ</p><p class="text-xl font-semibold tabular-nums text-violet-700">{{ donnees.resume.a_reintegrer }}</p></div>
      </div>

      <section class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden mb-4">
        <h2 class="px-4 sm:px-5 pt-4 pb-2 text-base font-semibold text-gray-900">Effets en service</h2>
        <p v-if="!donnees.paquetage.length" class="px-5 pb-5 text-sm text-gray-400">Aucun effet en service pour le moment.</p>
        <ul v-else class="divide-y divide-gray-100">
          <li v-for="l in donnees.paquetage" :key="l.id" class="px-4 sm:px-5 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="flex-1 min-w-[10rem] text-sm text-gray-900">{{ l.article?.designation }}<span v-if="l.taille" class="text-gray-500"> · {{ l.taille.valeur }}</span><span class="text-gray-500"> × {{ l.quantite }}</span></span>
            <span :class="ECHEANCES[l.echeance]?.classe" class="text-[11px] px-2 py-0.5 rounded-full border whitespace-nowrap">{{ l.date_echeance ? (l.echeance === 'echue' ? 'À renouveler depuis le ' : 'Jusqu\'au ') + dateCourte(l.date_echeance) : 'À rendre au départ' }}</span>
          </li>
        </ul>
      </section>

      <div class="flex flex-wrap gap-2">
        <button type="button" @click="emit('aller', 'mouvements')" class="px-4 py-2.5 rounded-xl bg-white border border-gray-200 text-sm font-medium hover:bg-gray-50">Mes bons de dotation</button>
        <button type="button" @click="emit('aller', 'demandes')" class="px-4 py-2.5 rounded-xl bg-white border border-gray-200 text-sm font-medium hover:bg-gray-50">Mes incidents</button>
      </div>
      <p class="text-xs text-gray-500 mt-3">Un effet perdu ou abîmé ? Présentez-vous au magasin : un procès-verbal sera établi.</p>
    </template>
  </div>
</template>
