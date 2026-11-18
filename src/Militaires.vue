<script setup>
// Militaires et mensurations (refonte, cahier des charges : tables militaires et mensurations).
// Administrateur : tout. Magasinier : consultation et saisie des mensurations. Chef d'unité : son unité, en lecture.
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { ROLES } from '@/services/roles'
import { referentiels, chargerReferentiels, tousLesGrades } from '@/services/referentiels'
import { notify } from '@/services/notify'
import Pagination from './components/Pagination.vue'
import FicheMilitaire from './militaires/FicheMilitaire.vue'
import { STATUTS, dateCourte } from './militaires/format'

const props = defineProps({ user: { type: Object, default: null } })
const role = computed(() => props.user?.role)
const admin = computed(() => role.value === ROLES.ADMIN)
const chef = computed(() => role.value === ROLES.CHEF)

// ----- Filtres (la liste est paginée et filtrée par le serveur)
const recherche = ref('')
const uniteId = ref('')
const statut = ref('actif')
const categorieId = ref('')
const sansMensuration = ref(false)
const page = ref(1)

const militaires = ref([])
const pages = ref(1)
const total = ref(0)
const chargement = ref(true)
const erreur = ref('')

let jeton = 0
async function charger() {
  const moi = ++jeton // seule la dernière requête compte (frappe rapide dans la recherche)
  chargement.value = true
  erreur.value = ''
  try {
    const { data } = await api.get('/militaires', {
      params: {
        q: recherche.value.trim() || undefined,
        unite_id: uniteId.value || undefined,
        statut: statut.value || undefined,
        categorie_personnel_id: categorieId.value || undefined,
        sans_mensuration: sansMensuration.value ? 1 : undefined,
        page: page.value,
        par_page: 20
      }
    })
    if (moi !== jeton) return
    militaires.value = enListe(data)
    pages.value = data.last_page || 1
    total.value = data.total ?? militaires.value.length
  } catch (e) {
    if (moi === jeton) erreur.value = messageErreur(e, 'Impossible de charger les militaires.')
  } finally {
    if (moi === jeton) chargement.value = false
  }
}

let minuterie = null
watch(recherche, () => {
  clearTimeout(minuterie)
  minuterie = setTimeout(() => (page.value === 1 ? charger() : (page.value = 1)), 300)
})
watch([uniteId, statut, categorieId, sansMensuration], () => (page.value === 1 ? charger() : (page.value = 1)))
watch(page, charger)

const resumeMesures = (m) => {
  const x = m.mensuration_actuelle
  if (!x) return null
  return [x.taille_vetement?.valeur, x.pointure?.valeur, x.tour_tete?.valeur].map((v) => v ?? '—').join(' · ')
}

// ----- Fiche
const ficheId = ref(null)

// ----- Création / modification (administrateur)
const formulaire = ref(null)
const occupe = ref(false)
const aujourdhui = () => new Date().toISOString().slice(0, 10)
const ouvrirCreation = () =>
  (formulaire.value = {
    id: null, matricule: '', nom: '', prenoms: '',
    grade_id: '', unite_id: uniteId.value || referentiels.unites[0]?.id || '', date_incorporation: aujourdhui()
  })
const ouvrirModification = (m) =>
  (formulaire.value = { id: m.id, matricule: m.matricule, nom: m.nom, prenoms: m.prenoms, grade_id: m.grade_id, unite_id: m.unite_id, date_incorporation: m.date_incorporation })

async function enregistrer() {
  const f = formulaire.value
  const corps = { matricule: f.matricule.trim().toUpperCase(), nom: f.nom.trim(), prenoms: f.prenoms.trim(), grade_id: f.grade_id, unite_id: f.unite_id, date_incorporation: f.date_incorporation }
  occupe.value = true
  try {
    const { data } = f.id ? await api.put(`/militaires/${f.id}`, corps) : await api.post('/militaires', corps)
    notify.succes(f.id ? 'Fiche modifiée.' : `${data.nom} ${data.prenoms} ajouté(e).`)
    formulaire.value = null
    await charger()
    ficheId.value = data.id
    rechargerFiche.value++
  } catch (e) {
    notify.erreur(messageErreur(e, 'Enregistrement impossible.'))
  } finally {
    occupe.value = false
  }
}
const rechargerFiche = ref(0)

onMounted(async () => {
  try {
    await chargerReferentiels()
  } catch {
    /* les filtres resteront vides ; la liste se charge quand même */
  }
  charger()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-3 justify-between items-end mb-5">
      <div>
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Militaires et mensurations</h1>
        <p class="text-sm text-gray-500">
          <template v-if="chef">Personnel de votre unité{{ user?.unite ? ' : ' + (user.unite.libelle || user.unite.nom) : '' }}.</template>
          <template v-else>Personnel, grade, unité, statut et tailles mesurées.</template>
        </p>
      </div>
      <button v-if="admin" type="button" @click="ouvrirCreation" class="bg-brand-600 hover:bg-brand-700 text-white font-medium px-4 py-2 rounded-xl text-sm shadow-sm">+ Nouveau militaire</button>
    </div>

    <!-- Filtres -->
    <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100 mb-4 space-y-3">
      <div class="flex flex-wrap gap-2">
        <input v-model="recherche" type="search" name="recherche-militaire" aria-label="Rechercher un militaire" placeholder="Matricule, nom ou prénoms…" class="w-full sm:w-auto sm:flex-1 sm:min-w-[12rem] px-4 py-2 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500" />
        <select v-if="!chef" v-model="uniteId" aria-label="Unité" class="min-w-0 flex-1 sm:flex-none sm:w-56 border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white">
          <option value="">Toutes les unités</option>
          <option v-for="u in referentiels.unites" :key="u.id" :value="u.id">{{ u.code }} — {{ u.libelle }}</option>
        </select>
        <select v-model="categorieId" aria-label="Catégorie de personnel" class="min-w-0 flex-1 sm:flex-none sm:w-48 border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white">
          <option value="">Toutes catégories</option>
          <option v-for="c in referentiels.categories_personnel" :key="c.id" :value="c.id">{{ c.libelle }}</option>
        </select>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-for="s in [{ v: 'actif', l: 'Actifs' }, { v: 'mute', l: 'Mutés' }, { v: 'radie', l: 'Radiés' }, { v: '', l: 'Tous' }]"
          :key="s.v"
          type="button"
          :aria-pressed="statut === s.v"
          @click="statut = s.v"
          :class="statut === s.v ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200 hover:bg-gray-100'"
          class="px-3 py-1.5 rounded-lg text-xs font-semibold"
        >
          {{ s.l }}
        </button>
        <label class="flex items-center gap-1.5 text-xs text-gray-600 ml-auto">
          <input v-model="sansMensuration" type="checkbox" class="accent-brand-600" /> Seulement ceux à mesurer
        </label>
      </div>
    </div>

    <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden" :aria-busy="chargement">
      <div v-if="chargement && !militaires.length" class="p-8 text-center text-sm text-gray-400">Chargement…</div>
      <div v-else-if="!militaires.length" class="p-8 text-center text-sm text-gray-400">Aucun militaire ne correspond.</div>
      <template v-else>
        <ul class="md:hidden divide-y divide-gray-100" :class="chargement ? 'opacity-60' : ''">
          <li v-for="m in militaires" :key="'m' + m.id">
            <button type="button" @click="ficheId = m.id" class="w-full text-left px-4 py-3 active:bg-gray-50">
              <span class="flex items-start justify-between gap-2">
                <span class="block text-sm font-semibold text-gray-900">{{ m.nom }} {{ m.prenoms }}</span>
                <span :class="STATUTS[m.statut]?.classe" class="text-[11px] px-2 py-0.5 rounded-full border shrink-0">{{ STATUTS[m.statut]?.libelle }}</span>
              </span>
              <span class="block text-xs text-gray-500">{{ m.matricule }} · {{ m.grade?.libelle }} · {{ m.unite?.code }}</span>
              <span class="block text-xs mt-1" :class="resumeMesures(m) ? 'text-gray-600' : 'text-amber-700 font-medium'">{{ resumeMesures(m) ? 'Tailles : ' + resumeMesures(m) : 'À mesurer' }}</span>
            </button>
          </li>
        </ul>
        <div class="hidden md:block overflow-x-auto" :class="chargement ? 'opacity-60' : ''">
          <table class="w-full text-left text-sm">
            <thead>
              <tr class="bg-gray-50/70 text-gray-500 text-xs font-semibold border-b border-gray-100">
                <th class="p-3 pl-5">Matricule</th>
                <th class="p-3">Nom et prénoms</th>
                <th class="p-3">Grade</th>
                <th class="p-3">Unité</th>
                <th class="p-3" title="Vêtement · pointure · tour de tête">Tailles</th>
                <th class="p-3 pr-5">Statut</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-gray-700">
              <tr v-for="m in militaires" :key="m.id" @click="ficheId = m.id" class="hover:bg-gray-50/70 cursor-pointer">
                <td class="p-3 pl-5 font-mono text-xs text-gray-600 whitespace-nowrap">{{ m.matricule }}</td>
                <td class="p-3">
                  <button type="button" @click.stop="ficheId = m.id" class="font-medium text-gray-900 hover:text-brand-700 text-left">{{ m.nom }} {{ m.prenoms }}</button>
                </td>
                <td class="p-3 whitespace-nowrap">{{ m.grade?.libelle }}<span class="block text-[11px] text-gray-400">{{ m.grade?.categorie?.libelle }}</span></td>
                <td class="p-3 whitespace-nowrap" :title="m.unite?.libelle">{{ m.unite?.code }}</td>
                <td class="p-3 whitespace-nowrap tabular-nums">
                  <span v-if="resumeMesures(m)">{{ resumeMesures(m) }}<span class="block text-[11px] text-gray-400">le {{ dateCourte(m.mensuration_actuelle.date_mesure) }}</span></span>
                  <span v-else class="text-xs px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">À mesurer</span>
                </td>
                <td class="p-3 pr-5"><span :class="STATUTS[m.statut]?.classe" class="text-xs px-2.5 py-0.5 rounded-full border whitespace-nowrap">{{ STATUTS[m.statut]?.libelle }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pagination v-model:page="page" :pages="pages" :total="total" />
      </template>
    </div>

    <FicheMilitaire
      v-if="ficheId"
      :id="ficheId"
      :user="user"
      :recharger="rechargerFiche"
      @fermer="ficheId = null"
      @modifier="ouvrirModification"
      @maj="charger"
    />

    <!-- Création / modification -->
    <div v-if="formulaire" class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-[60] p-0 sm:p-4 backdrop-blur-sm">
      <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-lg w-full p-5 sm:p-6 shadow-2xl max-h-[92vh] overflow-y-auto" role="dialog" aria-modal="true" :aria-label="formulaire.id ? 'Modifier la fiche' : 'Nouveau militaire'">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ formulaire.id ? 'Modifier la fiche' : 'Nouveau militaire' }}</h3>
        <form @submit.prevent="enregistrer" class="space-y-4">
          <label class="block">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Matricule</span>
            <input v-model="formulaire.matricule" required maxlength="30" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm uppercase font-mono" />
          </label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">Nom</span>
              <input v-model="formulaire.nom" required maxlength="255" autocomplete="off" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm uppercase" />
            </label>
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">Prénoms</span>
              <input v-model="formulaire.prenoms" required maxlength="255" autocomplete="off" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
            </label>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">Grade</span>
              <select v-model="formulaire.grade_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white">
                <option value="" disabled>— Choisir —</option>
                <optgroup v-for="c in referentiels.categories_personnel" :key="c.id" :label="c.libelle">
                  <option v-for="g in c.grades" :key="g.id" :value="g.id">{{ g.libelle }}</option>
                </optgroup>
              </select>
            </label>
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">Unité</span>
              <select v-model="formulaire.unite_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white">
                <option value="" disabled>— Choisir —</option>
                <option v-for="u in referentiels.unites" :key="u.id" :value="u.id">{{ u.code }} — {{ u.libelle }}</option>
              </select>
            </label>
          </div>
          <p v-if="formulaire.grade_id" class="text-xs text-gray-500 -mt-2">
            Catégorie : <strong>{{ tousLesGrades().find((g) => g.id === formulaire.grade_id)?.categorie.libelle }}</strong> (détermine le tableau de dotation).
          </p>
          <label class="block">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Date d'incorporation</span>
            <input v-model="formulaire.date_incorporation" type="date" required :max="aujourdhui()" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="formulaire = null" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
            <button type="submit" :disabled="occupe" class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold disabled:opacity-50">{{ formulaire.id ? 'Enregistrer' : 'Ajouter' }}</button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
