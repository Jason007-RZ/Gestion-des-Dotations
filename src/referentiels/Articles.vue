<script setup>
// Catalogue des effets dotables (cahier des charges : table articles).
// Tous les rôles consultent ; l'administrateur crée, modifie, désactive ou supprime.
import { ref, computed, onMounted } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { confirmer } from '@/services/confirm'
import { usePagination } from '@/services/pagination'
import { referentiels, executer, resumeGrille } from '@/services/referentiels'
import Pagination from '@/components/Pagination.vue'

const props = defineProps({ admin: { type: Boolean, default: false } })

const articles = ref([])
const chargement = ref(true)
const erreur = ref('')

const recherche = ref('')
const categorie = ref('toutes')
const voirInactifs = ref(false)

async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    articles.value = enListe((await api.get('/articles')).data)
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger le catalogue.')
  } finally {
    chargement.value = false
  }
}

const filtres = computed(() => {
  const q = recherche.value.trim().toLowerCase()
  return articles.value.filter(
    (a) =>
      (voirInactifs.value || a.actif) &&
      (categorie.value === 'toutes' || a.categorie_id === categorie.value) &&
      (!q || a.designation.toLowerCase().includes(q) || a.code.toLowerCase().includes(q))
  )
})
const nbInactifs = computed(() => articles.value.filter((a) => !a.actif).length)
const { page, pages, elements, total } = usePagination(filtres, 15)

// ----- Formulaire (création et modification)
const formulaire = ref(null) // null = fermé
const videFormulaire = () => ({
  id: null,
  code: '',
  designation: '',
  categorie_id: referentiels.categories_article[0]?.id ?? '',
  grille_taille_id: '',
  renouvelable: true,
  duree_vie_mois: 12,
  a_reintegrer: false,
  actif: true
})
const ouvrirCreation = () => (formulaire.value = videFormulaire())
const ouvrirModification = (a) =>
  (formulaire.value = {
    id: a.id,
    code: a.code,
    designation: a.designation,
    categorie_id: a.categorie_id,
    grille_taille_id: a.grille_taille_id ?? '',
    renouvelable: a.duree_vie_mois !== null,
    duree_vie_mois: a.duree_vie_mois ?? 12,
    a_reintegrer: a.a_reintegrer,
    actif: a.actif
  })
const grilleChoisie = computed(() => referentiels.grilles_taille.find((g) => g.id === formulaire.value?.grille_taille_id) || null)
const occupe = ref(false)

async function enregistrer() {
  const f = formulaire.value
  const corps = {
    code: f.code.trim().toUpperCase(),
    designation: f.designation.trim(),
    categorie_id: f.categorie_id,
    grille_taille_id: f.grille_taille_id === '' ? null : f.grille_taille_id,
    duree_vie_mois: f.renouvelable ? Number(f.duree_vie_mois) : null,
    a_reintegrer: f.a_reintegrer,
    actif: f.actif
  }
  occupe.value = true
  try {
    await executer(() => (f.id ? api.put(`/articles/${f.id}`, corps) : api.post('/articles', corps)), f.id ? 'Article modifié.' : 'Article ajouté au catalogue.')
    formulaire.value = null
    await charger()
  } catch {
    /* message affiché ; le formulaire reste ouvert */
  } finally {
    occupe.value = false
  }
}

async function basculerActif(a) {
  try {
    const corps = {
      code: a.code, designation: a.designation, categorie_id: a.categorie_id, grille_taille_id: a.grille_taille_id,
      duree_vie_mois: a.duree_vie_mois, a_reintegrer: a.a_reintegrer, actif: !a.actif
    }
    await executer(() => api.put(`/articles/${a.id}`, corps), a.actif ? `« ${a.designation} » désactivé : il n'est plus proposé.` : `« ${a.designation} » réactivé.`)
    await charger()
  } catch {
    /* message affiché */
  }
}

async function supprimer(a) {
  const ok = await confirmer({
    titre: "Supprimer l'article",
    message: `« ${a.designation} » (${a.code}) sera retiré définitivement du catalogue. S'il a déjà servi, préférez « Désactiver ».`,
    bouton: 'Supprimer'
  })
  if (!ok) return
  try {
    await executer(() => api.delete(`/articles/${a.id}`), 'Article supprimé.')
    await charger()
  } catch {
    /* message affiché */
  }
}

const renouvellement = (a) => (a.duree_vie_mois ? `${a.duree_vie_mois} mois` : 'Pas de renouvellement')

onMounted(charger)
defineExpose({ charger })
</script>

<template>
  <div>
    <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100 mb-4 flex flex-wrap gap-3 items-center">
      <input v-model="recherche" type="search" name="recherche-article" aria-label="Rechercher un article" placeholder="Rechercher par code ou désignation…" class="flex-1 min-w-[200px] px-4 py-2 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500" />
      <button v-if="admin" type="button" @click="ouvrirCreation" class="bg-brand-600 hover:bg-brand-700 text-white font-medium px-4 py-2 rounded-xl text-sm shadow-sm">+ Nouvel article</button>
      <div class="w-full flex gap-2 overflow-x-auto pb-1 -mb-1">
        <button type="button" @click="categorie = 'toutes'" :class="categorie === 'toutes' ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200 hover:bg-gray-100'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">Toutes</button>
        <button v-for="c in referentiels.categories_article" :key="c.id" type="button" @click="categorie = c.id" :class="categorie === c.id ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200 hover:bg-gray-100'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">
          {{ c.libelle }}
        </button>
        <label v-if="admin && nbInactifs" class="ml-auto flex items-center gap-1.5 text-xs text-gray-600 whitespace-nowrap pl-2">
          <input v-model="voirInactifs" type="checkbox" class="accent-brand-600" /> Afficher les désactivés ({{ nbInactifs }})
        </label>
      </div>
    </div>

    <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <div v-if="chargement" class="p-8 text-center text-sm text-gray-400">Chargement du catalogue…</div>
      <div v-else-if="filtres.length === 0" class="p-8 text-center text-sm text-gray-400">Aucun article ne correspond.</div>
      <template v-else>
        <!-- Téléphone : cartes -->
        <ul class="md:hidden divide-y divide-gray-100">
          <li v-for="a in elements" :key="'m' + a.id" class="px-4 py-3" :class="a.actif ? '' : 'opacity-60'">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900">{{ a.designation }}</p>
                <p class="text-xs text-gray-500 font-mono">{{ a.code }}</p>
              </div>
              <span class="text-[11px] px-2 py-0.5 rounded-full bg-brand-50 text-brand-700 border border-brand-200/60 shrink-0">{{ a.categorie?.libelle }}</span>
            </div>
            <p class="text-xs text-gray-600 mt-1">{{ resumeGrille(a.grille_taille) }} · {{ renouvellement(a) }}</p>
            <div class="flex flex-wrap gap-1.5 mt-1.5">
              <span v-if="a.a_reintegrer" class="text-[11px] px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">À réintégrer</span>
              <span v-if="!a.actif" class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 border border-gray-200">Désactivé</span>
            </div>
            <div v-if="admin" class="mt-2 flex gap-4 text-xs font-medium">
              <button type="button" @click="ouvrirModification(a)" class="text-amber-700">Modifier</button>
              <button type="button" @click="basculerActif(a)" class="text-gray-600">{{ a.actif ? 'Désactiver' : 'Réactiver' }}</button>
              <button type="button" @click="supprimer(a)" class="text-red-600">Supprimer</button>
            </div>
          </li>
        </ul>
        <!-- Ordinateur : tableau -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead>
              <tr class="bg-gray-50/70 text-gray-500 text-xs font-semibold border-b border-gray-100">
                <th class="p-3 pl-5">Code</th>
                <th class="p-3">Désignation</th>
                <th class="p-3">Catégorie</th>
                <th class="p-3">Tailles</th>
                <th class="p-3">Durée de vie</th>
                <th class="p-3"></th>
                <th v-if="admin" class="p-3 pr-5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-gray-700">
              <tr v-for="a in elements" :key="a.id" :class="a.actif ? 'hover:bg-gray-50/50' : 'opacity-60'">
                <td class="p-3 pl-5 font-mono text-xs text-gray-600 whitespace-nowrap">{{ a.code }}</td>
                <td class="p-3 font-medium text-gray-900">{{ a.designation }}</td>
                <td class="p-3"><span class="text-xs px-2.5 py-0.5 rounded-full bg-brand-50 text-brand-700 border border-brand-200/60 whitespace-nowrap">{{ a.categorie?.libelle }}</span></td>
                <td class="p-3 text-xs text-gray-600 whitespace-nowrap">{{ resumeGrille(a.grille_taille) }}</td>
                <td class="p-3 text-xs whitespace-nowrap">{{ renouvellement(a) }}</td>
                <td class="p-3 whitespace-nowrap">
                  <span v-if="a.a_reintegrer" class="text-[11px] px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 border border-amber-200">À réintégrer</span>
                  <span v-if="!a.actif" class="text-[11px] px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 border border-gray-200 ml-1">Désactivé</span>
                </td>
                <td v-if="admin" class="p-3 pr-5 text-right space-x-3 whitespace-nowrap text-xs font-medium">
                  <button type="button" @click="ouvrirModification(a)" class="text-amber-700 hover:text-amber-900">Modifier</button>
                  <button type="button" @click="basculerActif(a)" class="text-gray-600 hover:text-gray-900">{{ a.actif ? 'Désactiver' : 'Réactiver' }}</button>
                  <button type="button" @click="supprimer(a)" class="text-red-600 hover:text-red-800">Supprimer</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <Pagination v-model:page="page" :pages="pages" :total="total" />
      </template>
    </div>

    <!-- Création / modification -->
    <div v-if="formulaire" class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-0 sm:p-4 backdrop-blur-sm">
      <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-lg w-full p-5 sm:p-6 shadow-2xl max-h-[92vh] overflow-y-auto" role="dialog" aria-modal="true" :aria-label="formulaire.id ? 'Modifier l\'article' : 'Nouvel article'">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ formulaire.id ? "Modifier l'article" : 'Nouvel article' }}</h3>
        <form @submit.prevent="enregistrer" class="space-y-4">
          <div class="grid grid-cols-3 gap-3">
            <label class="col-span-1">
              <span class="block text-xs font-semibold text-gray-600 mb-1">Code</span>
              <input v-model="formulaire.code" required maxlength="20" placeholder="HAB-TRE" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm uppercase font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/20" />
            </label>
            <label class="col-span-2">
              <span class="block text-xs font-semibold text-gray-600 mb-1">Désignation</span>
              <input v-model="formulaire.designation" required maxlength="255" placeholder="Treillis (veste + pantalon)" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20" />
            </label>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">Catégorie</span>
              <select v-model="formulaire.categorie_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white">
                <option v-for="c in referentiels.categories_article" :key="c.id" :value="c.id">{{ c.code }} — {{ c.libelle }}</option>
              </select>
            </label>
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">Grille de taille</span>
              <select v-model="formulaire.grille_taille_id" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white">
                <option value="">Taille unique</option>
                <option v-for="g in referentiels.grilles_taille" :key="g.id" :value="g.id">{{ g.libelle }}</option>
              </select>
            </label>
          </div>
          <p v-if="grilleChoisie" class="text-xs text-gray-500 -mt-2">Tailles : {{ grilleChoisie.tailles.map((t) => t.valeur).join(', ') || 'aucune pour l\'instant' }}</p>

          <fieldset class="rounded-xl border border-gray-200 p-3 space-y-2">
            <legend class="px-1 text-xs font-semibold text-gray-600">Durée de vie</legend>
            <label class="flex items-center gap-2 text-sm"><input v-model="formulaire.renouvelable" type="radio" :value="true" name="renouvelable" class="accent-brand-600" /> Renouvelable tous les <input v-model="formulaire.duree_vie_mois" type="number" min="1" max="240" :disabled="!formulaire.renouvelable" :required="formulaire.renouvelable" class="w-20 border border-gray-200 rounded-lg px-2 py-1 text-sm disabled:bg-gray-50" aria-label="Durée de vie en mois" /> mois</label>
            <label class="flex items-center gap-2 text-sm"><input v-model="formulaire.renouvelable" type="radio" :value="false" name="renouvelable" class="accent-brand-600" /> Pas de renouvellement périodique</label>
          </fieldset>

          <label class="flex items-start gap-2 text-sm">
            <input v-model="formulaire.a_reintegrer" type="checkbox" class="mt-0.5 accent-brand-600" />
            <span>À réintégrer<span class="block text-xs text-gray-500">Doit être rendu à la mutation ou à la radiation (sac à dos, couverture…).</span></span>
          </label>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="formulaire.actif" type="checkbox" class="mt-0.5 accent-brand-600" />
            <span>Actif<span class="block text-xs text-gray-500">Un article désactivé n'est plus proposé, mais son historique reste.</span></span>
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
