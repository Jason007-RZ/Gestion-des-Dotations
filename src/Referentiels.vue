<script setup>
// Catalogue et dotation (refonte selon le cahier des charges officiel).
// Tous : catalogue des articles. Administrateur, magasinier, chef d'unité : tableau de dotation.
// Administrateur seulement : grades, grilles de taille, catégories d'articles, unités et magasins.
import { ref, computed, onMounted } from 'vue'
import api, { messageErreur } from '@/services/api'
import { confirmer } from '@/services/confirm'
import { ROLES } from '@/services/roles'
import { referentiels, chargerReferentiels, executer } from '@/services/referentiels'
import Articles from './referentiels/Articles.vue'
import TableauDotation from './referentiels/TableauDotation.vue'
import Grades from './referentiels/Grades.vue'
import Tailles from './referentiels/Tailles.vue'
import ListeEditable from './referentiels/ListeEditable.vue'

const props = defineProps({ user: { type: Object, default: null } })
const role = computed(() => props.user?.role)
const admin = computed(() => role.value === ROLES.ADMIN)

const ONGLETS = [
  { id: 'articles', libelle: 'Catalogue', roles: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF, ROLES.MILITAIRE] },
  { id: 'tableau', libelle: 'Tableau de dotation', roles: [ROLES.ADMIN, ROLES.MAGASINIER, ROLES.CHEF] },
  { id: 'grades', libelle: 'Grades', roles: [ROLES.ADMIN] },
  { id: 'tailles', libelle: 'Grilles de taille', roles: [ROLES.ADMIN] },
  { id: 'categories', libelle: "Catégories d'articles", roles: [ROLES.ADMIN] },
  { id: 'unites', libelle: 'Unités et magasins', roles: [ROLES.ADMIN] }
]
const onglets = computed(() => ONGLETS.filter((o) => o.roles.includes(role.value)))

const lireOnglet = () => {
  try {
    return sessionStorage.getItem('referentiels_onglet')
  } catch {
    return null
  }
}
const onglet = ref(lireOnglet() || 'articles')
const choisir = (id) => {
  onglet.value = id
  try {
    sessionStorage.setItem('referentiels_onglet', id)
  } catch {
    /* préférence non mémorisée */
  }
}
const ongletActif = computed(() => (onglets.value.some((o) => o.id === onglet.value) ? onglet.value : 'articles'))

const erreur = ref('')
async function charger(forcer = false) {
  erreur.value = ''
  try {
    await chargerReferentiels(forcer)
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger les référentiels.')
  }
}
onMounted(() => charger(true))

// ----- Listes simples (ListeEditable)
const supprimerAvecConfirmation = async (titre, message, url, succes) => {
  if (!(await confirmer({ titre, message, bouton: 'Supprimer' }))) return
  await executer(() => api.delete(url), succes)
}

const colonnesCategories = [
  { cle: 'code', libelle: 'Code', requis: true, max: 10, majuscules: true, placeholder: 'HAB' },
  { cle: 'libelle', libelle: 'Libellé', requis: true, max: 255, placeholder: 'Habillement' }
]
const categories = {
  creer: (f) => executer(() => api.post('/categories-article', f), 'Catégorie ajoutée.'),
  modifier: (el, f) => executer(() => api.put(`/categories-article/${el.id}`, f), 'Catégorie modifiée.'),
  supprimer: (el) => supprimerAvecConfirmation("Supprimer la catégorie d'articles", `« ${el.code} — ${el.libelle} » sera supprimée.`, `/categories-article/${el.id}`, 'Catégorie supprimée.')
}

const colonnesUnites = [
  { cle: 'code', libelle: 'Code', requis: true, max: 20, majuscules: true, placeholder: '1RFI' },
  { cle: 'libelle', libelle: 'Libellé', requis: true, max: 255, placeholder: "1er Régiment des Forces d'Intervention" }
]
const unites = {
  creer: (f) => executer(() => api.post('/unites', f), 'Unité ajoutée.'),
  modifier: (el, f) => executer(() => api.put(`/unites/${el.id}`, f), 'Unité modifiée.'),
  supprimer: (el) => supprimerAvecConfirmation("Supprimer l'unité", `« ${el.code} — ${el.libelle} » sera supprimée.`, `/unites/${el.id}`, 'Unité supprimée.')
}

const colonnesMagasins = computed(() => [
  { cle: 'libelle', libelle: 'Libellé', requis: true, max: 255, placeholder: "Magasin d'habillement 1RFI" },
  {
    cle: 'unite_id', libelle: 'Unité', type: 'select', aucun: 'Aucune (magasin central)',
    options: referentiels.unites.map((u) => ({ valeur: u.id, libelle: `${u.code} — ${u.libelle}` })),
    afficher: (m) => (m.unite ? `${m.unite.code} — ${m.unite.libelle}` : 'Magasin central')
  }
])
const magasins = {
  creer: (f) => executer(() => api.post('/magasins', f), 'Magasin ajouté.'),
  modifier: (el, f) => executer(() => api.put(`/magasins/${el.id}`, f), 'Magasin modifié.'),
  supprimer: (el) => supprimerAvecConfirmation('Supprimer le magasin', `« ${el.libelle} » sera supprimé.`, `/magasins/${el.id}`, 'Magasin supprimé.')
}
</script>

<template>
  <div>
    <div class="mb-5">
      <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Catalogue et dotation</h1>
      <p class="text-sm text-gray-500">Effets d'habillement et d'équipement individuel, et ce que chaque catégorie de personnel a droit de percevoir.</p>
    </div>

    <nav v-if="onglets.length > 1" class="flex gap-1 overflow-x-auto border-b border-gray-200 mb-5 -mx-3 px-3 sm:mx-0 sm:px-0" aria-label="Sections">
      <button
        v-for="o in onglets"
        :key="o.id"
        type="button"
        @click="choisir(o.id)"
        :aria-current="ongletActif === o.id ? 'page' : undefined"
        :class="ongletActif === o.id ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
        class="px-3 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
      >
        {{ o.libelle }}
      </button>
    </nav>

    <div v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg flex flex-wrap gap-3 justify-between items-center">
      <span>{{ erreur }}</span>
      <button type="button" @click="charger(true)" class="text-xs font-semibold underline">Réessayer</button>
    </div>
    <div v-else-if="!referentiels.charge" class="p-8 text-center text-sm text-gray-400">Chargement…</div>

    <template v-else>
      <Articles v-if="ongletActif === 'articles'" :admin="admin" />
      <TableauDotation v-else-if="ongletActif === 'tableau'" :admin="admin" />
      <Grades v-else-if="ongletActif === 'grades'" />
      <Tailles v-else-if="ongletActif === 'tailles'" />
      <ListeEditable
        v-else-if="ongletActif === 'categories'"
        titre="Catégories d'articles"
        description="Classement du catalogue (cahier des charges : HAB, COI, CHA, PEQ, CAMP, COU)."
        :colonnes="colonnesCategories"
        :elements="referentiels.categories_article"
        :infos="(c) => `${c.nb_articles ?? 0} article(s)`"
        v-bind="categories"
        libelle-ajout="Ajouter la catégorie"
      />
      <div v-else-if="ongletActif === 'unites'" class="space-y-4">
        <ListeEditable
          titre="Unités"
          description="Régiments et services."
          :colonnes="colonnesUnites"
          :elements="referentiels.unites"
          :infos="(u) => (u.nb_militaires ? `${u.nb_militaires} militaire(s)` : '')"
          v-bind="unites"
          libelle-ajout="Ajouter l'unité"
        />
        <ListeEditable
          titre="Magasins d'habillement"
          description="Les magasiniers sont rattachés aux magasins depuis l'écran « Gestion des comptes »."
          :colonnes="colonnesMagasins"
          :elements="referentiels.magasins"
          v-bind="magasins"
          libelle-ajout="Ajouter le magasin"
        />
      </div>
    </template>
  </div>
</template>
