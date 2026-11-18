<script setup>
// Tableau de dotation (cahier des charges : « le cœur du projet ») : ce que chaque catégorie de personnel
// a droit de percevoir, en quelle quantité et à quel rythme. Lecture : administrateur, magasinier, chef d'unité.
// Modification : administrateur.
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { confirmer } from '@/services/confirm'
import { notify } from '@/services/notify'
import { referentiels, executer, resumeGrille, libelleRenouvellement } from '@/services/referentiels'

const props = defineProps({ admin: { type: Boolean, default: false } })

const categorieId = ref(null)
const lignes = ref([])
const articles = ref([])
const chargement = ref(false)
const erreur = ref('')

const categorie = computed(() => referentiels.categories_personnel.find((c) => c.id === categorieId.value) || null)

async function charger() {
  if (!categorieId.value) return
  chargement.value = true
  erreur.value = ''
  try {
    lignes.value = enListe((await api.get('/tableau-dotation', { params: { categorie_personnel_id: categorieId.value } })).data)
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger le tableau de dotation.')
  } finally {
    chargement.value = false
  }
}

async function chargerArticles() {
  if (!props.admin) return
  try {
    articles.value = enListe((await api.get('/articles')).data)
  } catch {
    /* la liste d'ajout restera vide ; le tableau reste lisible */
  }
}

watch(categorieId, charger)
watch(
  () => referentiels.categories_personnel,
  (cats) => {
    if (!cats.some((c) => c.id === categorieId.value)) categorieId.value = cats[0]?.id ?? null
  },
  { immediate: true }
)

const nbReintegrables = computed(() => lignes.value.filter((l) => l.periodicite_mois === null).length)
const articlesDisponibles = computed(() => articles.value.filter((a) => a.actif && !lignes.value.some((l) => l.article_id === a.id)))

// ----- Ajout
const nouveau = ref({ article_id: '', quantite_reglementaire: 1, renouvelable: true, periodicite_mois: 12 })
watch(
  () => nouveau.value.article_id,
  (id) => {
    const a = articles.value.find((x) => x.id === id)
    if (a) Object.assign(nouveau.value, { renouvelable: a.duree_vie_mois !== null, periodicite_mois: a.duree_vie_mois ?? 12 })
  }
)
async function ajouter() {
  const n = nouveau.value
  try {
    await executer(
      () => api.post('/tableau-dotation', {
        categorie_personnel_id: categorieId.value,
        article_id: n.article_id,
        quantite_reglementaire: Number(n.quantite_reglementaire),
        periodicite_mois: n.renouvelable ? Number(n.periodicite_mois) : null
      }),
      'Article ajouté au tableau.'
    )
    nouveau.value = { article_id: '', quantite_reglementaire: 1, renouvelable: true, periodicite_mois: 12 }
    await charger()
  } catch {
    /* message affiché */
  }
}

// ----- Modification sur place
const edition = ref(null)
const editer = (l) => (edition.value = { id: l.id, quantite_reglementaire: l.quantite_reglementaire, renouvelable: l.periodicite_mois !== null, periodicite_mois: l.periodicite_mois ?? 12 })
async function enregistrer() {
  const e = edition.value
  try {
    await executer(() => api.put(`/tableau-dotation/${e.id}`, {
      quantite_reglementaire: Number(e.quantite_reglementaire),
      periodicite_mois: e.renouvelable ? Number(e.periodicite_mois) : null
    }), 'Ligne modifiée.')
    edition.value = null
    await charger()
  } catch {
    /* saisie conservée */
  }
}

async function retirer(l) {
  if (!(await confirmer({ titre: 'Retirer du tableau', message: `« ${l.article?.designation} » ne sera plus dû à la catégorie ${categorie.value?.libelle}.`, bouton: 'Retirer' }))) return
  try {
    await executer(() => api.delete(`/tableau-dotation/${l.id}`), 'Ligne retirée.')
    await charger()
  } catch {
    /* message affiché */
  }
}

// ----- Copie depuis une autre catégorie (pour démarrer rapidement un tableau vide)
const sourceCopie = ref('')
async function copier() {
  const source = referentiels.categories_personnel.find((c) => c.id === sourceCopie.value)
  if (!source) return
  if (!(await confirmer({ titre: 'Copier un tableau', message: `Les lignes de « ${source.libelle} » seront ajoutées à « ${categorie.value?.libelle} ». Les articles déjà présents ne sont pas modifiés.`, bouton: 'Copier', danger: false }))) return
  try {
    const { data } = await executer(() => api.post('/tableau-dotation/copier', { source_id: source.id, cible_id: categorieId.value }))
    notify.succes(`${data.copiees} ligne(s) copiée(s)${data.ignorees ? `, ${data.ignorees} déjà présente(s)` : ''}.`)
    sourceCopie.value = ''
    await charger()
  } catch {
    /* message affiché */
  }
}

onMounted(() => {
  charger()
  chargerArticles()
})
</script>

<template>
  <div class="space-y-4">
    <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100">
      <p class="text-xs font-semibold text-gray-500 mb-2">Catégorie de personnel</p>
      <div class="flex gap-2 overflow-x-auto pb-1 -mb-1" role="tablist" aria-label="Catégorie de personnel">
        <button
          v-for="c in referentiels.categories_personnel"
          :key="c.id"
          type="button"
          role="tab"
          :aria-selected="categorieId === c.id"
          @click="categorieId = c.id"
          :class="categorieId === c.id ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-700 border border-gray-200 hover:bg-gray-100'"
          class="px-3.5 py-2 rounded-xl text-sm font-medium whitespace-nowrap"
        >
          {{ c.libelle }}
        </button>
      </div>
      <p v-if="categorie" class="text-xs text-gray-500 mt-2">
        Grades : {{ (categorie.grades || []).map((g) => g.libelle).join(', ') || 'aucun' }}
      </p>
    </div>

    <p v-if="erreur" class="p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>

    <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
      <header class="px-4 sm:px-5 py-4 border-b border-gray-100 flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-base font-semibold text-gray-900">Droits de la catégorie « {{ categorie?.libelle }} »</h2>
        <p class="text-xs text-gray-500">{{ lignes.length }} article(s)<span v-if="nbReintegrables">, dont {{ nbReintegrables }} réintégrable(s)</span></p>
      </header>

      <div v-if="chargement" class="p-8 text-center text-sm text-gray-400">Chargement…</div>
      <div v-else-if="lignes.length === 0" class="p-6 text-center text-sm text-gray-500">
        Aucun article n'est encore prévu pour cette catégorie.
        <span v-if="admin" class="block text-xs text-gray-400 mt-1">Ajoutez-les ci-dessous, ou copiez le tableau d'une autre catégorie puis ajustez-le.</span>
      </div>
      <ul v-else class="divide-y divide-gray-100">
        <li v-for="l in lignes" :key="l.id" class="px-4 sm:px-5 py-3">
          <form v-if="edition?.id === l.id" @submit.prevent="enregistrer" class="flex flex-wrap items-center gap-3">
            <span class="flex-1 min-w-[12rem] text-sm font-medium text-gray-900">{{ l.article?.designation }}</span>
            <label class="flex items-center gap-1.5 text-sm">Qté <input v-model="edition.quantite_reglementaire" type="number" min="1" max="99" required class="w-16 border border-gray-200 rounded-lg px-2 py-1" aria-label="Quantité réglementaire" /></label>
            <label class="flex items-center gap-1.5 text-sm"><input v-model="edition.renouvelable" type="checkbox" class="accent-brand-600" /> tous les</label>
            <input v-model="edition.periodicite_mois" type="number" min="1" max="240" :disabled="!edition.renouvelable" :required="edition.renouvelable" class="w-16 border border-gray-200 rounded-lg px-2 py-1 text-sm disabled:bg-gray-50" aria-label="Périodicité en mois" />
            <span class="text-sm text-gray-500 -ml-1">mois</span>
            <div class="flex gap-2 ml-auto">
              <button type="button" @click="edition = null" class="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold">Annuler</button>
              <button type="submit" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold">Enregistrer</button>
            </div>
          </form>
          <div v-else class="flex flex-wrap items-center gap-x-4 gap-y-1">
            <div class="flex-1 min-w-[12rem]">
              <p class="text-sm font-medium text-gray-900">{{ l.article?.designation }} <span class="text-xs font-mono text-gray-400">{{ l.article?.code }}</span></p>
              <p class="text-xs text-gray-500">{{ resumeGrille(l.article?.grille_taille) }}<span v-if="l.article && !l.article.actif" class="text-red-600"> · article désactivé</span></p>
            </div>
            <span class="text-sm text-gray-900 font-semibold tabular-nums w-14">× {{ l.quantite_reglementaire }}</span>
            <span :class="l.periodicite_mois ? 'bg-brand-50 text-brand-700 border-brand-200/60' : 'bg-amber-50 text-amber-800 border-amber-200'" class="text-xs px-2.5 py-0.5 rounded-full border whitespace-nowrap">{{ libelleRenouvellement(l.periodicite_mois) }}</span>
            <div v-if="admin" class="flex gap-3 text-xs font-medium">
              <button type="button" @click="editer(l)" class="text-amber-700 hover:text-amber-900">Modifier</button>
              <button type="button" @click="retirer(l)" class="text-red-600 hover:text-red-800">Retirer</button>
            </div>
          </div>
        </li>
      </ul>

      <form v-if="admin" @submit.prevent="ajouter" class="px-4 sm:px-5 py-4 bg-gray-50/70 border-t border-gray-100 flex flex-wrap items-end gap-3">
        <label class="flex-1 min-w-[14rem]">
          <span class="block text-[11px] font-semibold text-gray-500 mb-1">Article</span>
          <select v-model="nouveau.article_id" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="" disabled>{{ articlesDisponibles.length ? '— Choisir un article —' : 'Tous les articles actifs y sont déjà' }}</option>
            <option v-for="a in articlesDisponibles" :key="a.id" :value="a.id">{{ a.code }} — {{ a.designation }}</option>
          </select>
        </label>
        <label>
          <span class="block text-[11px] font-semibold text-gray-500 mb-1">Quantité</span>
          <input v-model="nouveau.quantite_reglementaire" type="number" min="1" max="99" required class="w-20 border border-gray-200 rounded-lg px-3 py-2 text-sm" />
        </label>
        <div>
          <span class="block text-[11px] font-semibold text-gray-500 mb-1">Renouvellement</span>
          <div class="flex items-center gap-2 text-sm">
            <label class="flex items-center gap-1"><input v-model="nouveau.renouvelable" type="checkbox" class="accent-brand-600" /> tous les</label>
            <input v-model="nouveau.periodicite_mois" type="number" min="1" max="240" :disabled="!nouveau.renouvelable" :required="nouveau.renouvelable" class="w-16 border border-gray-200 rounded-lg px-2 py-2 text-sm disabled:bg-gray-100" aria-label="Périodicité en mois" />
            <span class="text-gray-500">mois</span>
          </div>
        </div>
        <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold">+ Ajouter</button>
      </form>
    </section>

    <section v-if="admin" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5">
      <h3 class="text-sm font-semibold text-gray-900">Copier le tableau d'une autre catégorie</h3>
      <p class="text-xs text-gray-500 mb-3">Pratique pour démarrer : copiez, puis ajustez les quantités. Les articles déjà présents ne changent pas.</p>
      <form @submit.prevent="copier" class="flex flex-wrap gap-2">
        <select v-model="sourceCopie" required aria-label="Catégorie à copier" class="flex-1 min-w-[12rem] border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
          <option value="" disabled>— Copier depuis —</option>
          <option v-for="c in referentiels.categories_personnel.filter((x) => x.id !== categorieId)" :key="c.id" :value="c.id">{{ c.libelle }} ({{ c.nb_lignes_tableau ?? '?' }} ligne(s))</option>
        </select>
        <button type="submit" class="px-4 py-2 rounded-lg border border-brand-600 text-brand-700 hover:bg-brand-50 text-xs font-semibold">Copier</button>
      </form>
    </section>
  </div>
</template>
