<script setup>
import { ref, computed, onMounted } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { ouvrirPdf } from '@/services/pdf'

// Espace du militaire : sa propre fiche, sa dotation selon son grade, son échéancier personnel.
// Le backend limite déjà l'accès à sa propre fiche (militaire_id).
const props = defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['aller']) // 'demandes' : ouvre l'écran des demandes (signalement d'une perte)

const fiche = ref(null)
const ecarts = ref([])
const mouvements = ref([])
const loading = ref(true)
const errorMessage = ref('')

const charger = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const liste = enListe((await api.get('/militaires')).data)
    const ma = liste[0]
    if (!ma) {
      errorMessage.value = "Aucune fiche militaire n'est rattachée à ce compte. Contactez l'administrateur."
      return
    }
    const [detail, ec, mv] = await Promise.all([
      api.get(`/militaires/${ma.id}`),
      api.get(`/militaires/${ma.id}/ecarts`),
      api.get('/mouvements')
    ])
    fiche.value = detail.data
    ecarts.value = ec.data.ecarts || []
    mouvements.value = enListe(mv.data)
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Impossible de charger votre fiche.')
  } finally {
    loading.value = false
  }
}

const maFichePdf = () => fiche.value && ouvrirPdf(`/militaires/${fiche.value.id}/fiche-individuelle`, `fiche-${fiche.value.matricule}.pdf`)

const aPercevoir = computed(() => ecarts.value.filter((e) => e.ecart > 0))
const percus = computed(() => mouvements.value.filter((m) => m.type === 'perception'))

onMounted(charger)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Mon espace</h1>
        <p class="text-sm text-gray-500">Votre fiche, votre dotation et l'historique de vos perceptions.</p>
      </div>
      <div v-if="fiche" class="flex flex-wrap items-center gap-2">
        <button type="button" @click="emit('aller', 'demandes')" class="text-sm font-semibold text-red-700 bg-red-50 border border-red-100 px-4 py-2.5 rounded-xl hover:bg-red-100 transition">Signaler une perte</button>
        <button type="button" @click="maFichePdf" class="text-sm font-semibold text-brand-700 bg-brand-50 border border-brand-100 px-4 py-2.5 rounded-xl hover:bg-brand-100 transition">Ma fiche (PDF)</button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-gray-400 text-sm py-12 animate-pulse">Chargement de votre fiche...</div>
    <div v-else-if="errorMessage" class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{{ errorMessage }}</div>

    <div v-else-if="fiche" class="space-y-6">
      <!-- Identité -->
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div><p class="text-[10px] font-semibold text-gray-400">Nom</p><p class="font-bold text-gray-900">{{ fiche.grade }} {{ fiche.nom }} {{ fiche.prenom }}</p></div>
        <div><p class="text-[10px] font-semibold text-gray-400">Matricule</p><p class="font-bold text-gray-900">{{ fiche.matricule }}</p></div>
        <div><p class="text-[10px] font-semibold text-gray-400">Unité</p><p class="font-bold text-gray-900">{{ fiche.unite?.nom || '—' }}</p></div>
        <div>
          <p class="text-[10px] font-semibold text-gray-400">Mensurations</p>
          <p v-if="fiche.mensuration" class="font-bold text-gray-900">T. {{ fiche.mensuration.taille_vetement }} · P. {{ fiche.mensuration.pointure }}</p>
          <p v-else class="text-amber-600 text-sm font-medium">Non renseignées (voir le magasin)</p>
        </div>
      </div>

      <!-- Dotation -->
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 pl-6 border-b border-gray-100 flex justify-between items-center">
          <span class="font-semibold text-gray-800 text-sm">Ma dotation (barème du grade {{ fiche.grade }})</span>
          <span v-if="aPercevoir.length" class="text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-100 px-3 py-1 rounded-full">{{ aPercevoir.length }} article(s) à percevoir</span>
        </div>
        <div v-if="ecarts.length === 0" class="p-8 text-center text-gray-400 text-sm">Aucun barème défini pour votre grade.</div>
        <table v-else class="w-full text-left text-sm">
          <thead class="bg-gray-50/70 text-gray-400 text-xs">
            <tr><th class="p-3 pl-6">Article</th><th class="p-3">Taille</th><th class="p-3">Dû</th><th class="p-3">Perçu</th><th class="p-3 pr-6">Situation</th></tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="e in ecarts" :key="e.declinaison_id">
              <td class="p-3 pl-6 font-medium text-gray-900">{{ e.article }}</td>
              <td class="p-3">{{ e.taille }}</td>
              <td class="p-3">{{ e.quantite_due }}</td>
              <td class="p-3">{{ e.quantite_percue }}</td>
              <td class="p-3 pr-6 font-semibold" :class="e.ecart > 0 ? 'text-amber-600' : e.ecart < 0 ? 'text-red-600' : 'text-brand-600'">
                {{ e.ecart > 0 ? e.ecart + ' à percevoir' : e.ecart < 0 ? 'Dépassement' : 'Complet' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Perceptions -->
      <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 pl-6 border-b border-gray-100 font-semibold text-gray-800 text-sm">Mes perceptions</div>
        <div v-if="percus.length === 0" class="p-8 text-center text-gray-400 text-sm">Aucune perception enregistrée.</div>
        <table v-else class="w-full text-left text-sm">
          <tbody class="divide-y divide-gray-100">
            <tr v-for="m in percus" :key="m.id">
              <td class="p-3 pl-6 text-xs text-gray-500">{{ new Date(m.date_mvt || m.created_at).toLocaleDateString('fr-FR') }}</td>
              <td class="p-3 font-medium text-gray-900">{{ m.declinaison?.article?.designation }} <span class="ml-1 bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded-full">{{ m.declinaison?.taille }}</span></td>
              <td class="p-3 pr-6 text-right font-semibold">× {{ m.quantite }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
