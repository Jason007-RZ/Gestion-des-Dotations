<script setup>
import { ref, onMounted, watch } from 'vue'
import api, { messageErreur } from '@/services/api'
import Pagination from './components/Pagination.vue'
import AppIcon from './components/AppIcon.vue'

// Journal d'audit (cahier des charges §8) : lecture seule, réservé à l'administrateur.
const lignes = ref([])
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const loading = ref(true)
const errorMessage = ref('')

const action = ref('')
const q = ref('')
const du = ref('')
const au = ref('')

// [libellé, classes de la pastille]
const GRIS = 'bg-gray-100 text-gray-600 border-gray-200'
const ROUGE = 'bg-red-50 text-red-700 border-red-100'
const VERT = 'bg-emerald-50 text-emerald-700 border-emerald-100'
const AMBRE = 'bg-amber-50 text-amber-700 border-amber-100'
const VIOLET = 'bg-violet-50 text-violet-700 border-violet-100'
const ARDOISE = 'bg-slate-100 text-slate-700 border-slate-200'
const BRAND = 'bg-brand-50 text-brand-700 border-brand-100'
const CIEL = 'bg-sky-50 text-sky-700 border-sky-100'

// Regroupées par thème pour la liste déroulante.
const GROUPES = [
  ['Connexion et sécurité', {
    connexion: ['Connexion', ARDOISE],
    connexion_echec: ['Échec de connexion', ROUGE],
    connexion_bloquee: ['Connexion bloquée', ROUGE],
    acces_refuse: ['Accès refusé', ROUGE],
    enrolement: ['Enrôlement', VERT],
    enrolement_refuse: ['Enrôlement refusé', ROUGE],
    mot_de_passe_change: ['Mot de passe changé', AMBRE],
    mot_de_passe_reinitialise: ['Mot de passe réinitialisé', AMBRE],
    sessions_revoquees: ['Sessions révoquées', AMBRE],
    '2fa_active': ['2FA activée', VERT],
    '2fa_desactive': ['2FA désactivée', ROUGE],
    '2fa_reinitialise': ['2FA réinitialisée', ROUGE],
    '2fa_codes_regeneres': ['Codes de secours régénérés', AMBRE],
    code_secours_utilise: ['Code de secours utilisé', AMBRE]
  }],
  ['Dotations', {
    dotation_creee: ['Bon de dotation', BRAND],
    incident_cree: ['Perte / détérioration (PV)', ROUGE],
    remplacement: ['Bon de remplacement', AMBRE],
    reintegration: ['Réintégration', CIEL],
    statut_militaire: ['Mutation / radiation', GRIS],
    document: ['Document édité', ARDOISE]
  }],
  ['Stock', {
    entree_stock: ['Entrée en stock', ARDOISE],
    reforme: ['Réforme', GRIS],
    seuil: ["Seuil d'alerte", VIOLET]
  }],
  ['Référentiels', {
    militaire_cree: ['Militaire créé', VERT],
    militaire_modifie: ['Militaire modifié', AMBRE],
    militaire_supprime: ['Militaire supprimé', ROUGE],
    mensuration: ['Mensurations', CIEL],
    unite_creee: ['Unité créée', VERT],
    unite_modifiee: ['Unité modifiée', AMBRE],
    unite_supprimee: ['Unité supprimée', ROUGE],
    magasin_cree: ['Magasin créé', VERT],
    magasin_modifie: ['Magasin modifié', AMBRE],
    magasin_supprime: ['Magasin supprimé', ROUGE],
    magasin_rattachement: ['Magasinier rattaché', CIEL],
    magasin_detachement: ['Magasinier détaché', AMBRE],
    article_cree: ['Article créé', VERT],
    article_modifie: ['Article modifié', AMBRE],
    article_supprime: ['Article supprimé', ROUGE],
    declinaison_ajoutee: ['Taille ajoutée', VERT],
    bareme_cree: ['Barème créé', VERT],
    bareme_modifie: ['Barème modifié', AMBRE],
    bareme_supprime: ['Barème supprimé', ROUGE]
  }],
  ['Comptes', {
    utilisateur_cree: ['Compte créé', VERT],
    utilisateur_modifie: ['Compte modifié', AMBRE],
    utilisateur_supprime: ['Compte supprimé', ROUGE]
  }]
]
const ACTIONS = Object.assign({}, ...GROUPES.map(([, actions]) => actions))
const libelle = (a) => ACTIONS[a]?.[0] || a
const classe = (a) => ACTIONS[a]?.[1] || 'bg-gray-100 text-gray-600 border-gray-200'

const formatDate = (d) =>
  new Date(d).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })

const charger = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const params = { page: page.value }
    if (action.value) params.action = action.value
    if (q.value.trim()) params.q = q.value.trim()
    if (du.value) params.du = du.value
    if (au.value) params.au = au.value
    const { data } = await api.get('/audit', { params })
    lignes.value = data.data || []
    pages.value = data.last_page || 1
    total.value = data.total || 0
  } catch (error) {
    errorMessage.value = messageErreur(error, 'Impossible de charger le journal.')
  } finally {
    loading.value = false
  }
}

let minuteur
watch([action, du, au], () => {
  page.value = 1
  charger()
})
watch(q, () => {
  clearTimeout(minuteur)
  minuteur = setTimeout(() => {
    page.value = 1
    charger()
  }, 350)
})
watch(page, charger)
onMounted(charger)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Journal d'audit</h1>
        <p class="text-sm text-gray-500">Traçabilité des actions sensibles : qui a fait quoi, et quand. Les entrées ne peuvent être ni modifiées ni supprimées.</p>
      </div>
      <button @click="charger" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 bg-brand-50 border border-brand-100 px-3.5 py-2 rounded-xl hover:bg-brand-100 transition">
        <AppIcon nom="actualiser" :taille="16" /> Actualiser
      </button>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 p-3 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
      <input name="q" v-model="q" type="search" placeholder="Rechercher (nom, article, militaire…)" class="min-w-0 border border-gray-200 rounded-xl px-3 py-2 text-sm" />
      <select name="action" v-model="action" class="min-w-0 border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white">
        <option value="">Toutes les actions</option>
        <optgroup v-for="[titre, actions] in GROUPES" :key="titre" :label="titre">
          <option v-for="(v, k) in actions" :key="k" :value="k">{{ v[0] }}</option>
        </optgroup>
      </select>
      <input name="du" v-model="du" type="date" aria-label="Du" class="min-w-0 border border-gray-200 rounded-xl px-3 py-2 text-sm" />
      <input name="au" v-model="au" type="date" aria-label="Au" class="min-w-0 border border-gray-200 rounded-xl px-3 py-2 text-sm" />
    </div>

    <div v-if="errorMessage" class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">{{ errorMessage }}</div>

    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-sm text-gray-500">Chargement…</div>
      <div v-else-if="!lignes.length" class="p-8 text-center text-sm text-gray-500">Aucune entrée pour ces critères.</div>
      <ul v-else class="divide-y divide-gray-100">
        <li v-for="l in lignes" :key="l.id" class="px-4 py-3 flex flex-col sm:flex-row sm:items-start gap-2 sm:gap-4">
          <div class="sm:w-40 shrink-0 text-xs text-gray-500">{{ formatDate(l.created_at) }}</div>
          <div class="sm:w-44 shrink-0">
            <span class="inline-block text-xs font-semibold px-2 py-0.5 rounded-full border" :class="classe(l.action)">{{ libelle(l.action) }}</span>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm text-gray-800 break-words">{{ l.resume }}</p>
            <p class="text-xs text-gray-500 mt-0.5">par {{ l.user_nom || 'inconnu' }}<span v-if="l.ip"> · {{ l.ip }}</span></p>
          </div>
        </li>
      </ul>
      <Pagination v-model:page="page" :pages="pages" :total="total" />
    </div>
  </div>
</template>
