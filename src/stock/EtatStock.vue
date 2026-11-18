<script setup>
// État du stock d'un magasin : une ligne par article, une case par taille (quantité, alerte).
import { ref, computed, watch } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { notify } from '@/services/notify'
import { referentiels } from '@/services/referentiels'

const props = defineProps({ magasinId: { type: Number, default: null } })
const emit = defineEmits(['lignes'])

const lignes = ref([])
const chargement = ref(false)
const erreur = ref('')

async function charger() {
  if (!props.magasinId) return
  chargement.value = true
  erreur.value = ''
  try {
    lignes.value = enListe((await api.get(`/magasins/${props.magasinId}/stock`)).data)
    emit('lignes', lignes.value)
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger le stock.')
  } finally {
    chargement.value = false
  }
}
watch(() => props.magasinId, charger, { immediate: true })
defineExpose({ charger })

// ----- Regroupement par article, filtres
const recherche = ref('')
const categorie = ref('toutes')
const alertesSeulement = ref(false)

const articles = computed(() => {
  const parArticle = new Map()
  for (const l of lignes.value) {
    if (!parArticle.has(l.article.id)) parArticle.set(l.article.id, { article: l.article, tailles: [] })
    parArticle.get(l.article.id).tailles.push(l)
  }
  return [...parArticle.values()].map((g) => ({
    ...g,
    total: g.tailles.reduce((s, l) => s + l.quantite, 0),
    alertes: g.tailles.filter((l) => l.en_alerte).length,
    ruptures: g.tailles.filter((l) => l.quantite === 0).length
  }))
})
const filtres = computed(() => {
  const q = recherche.value.trim().toLowerCase()
  return articles.value.filter(
    (g) =>
      (categorie.value === 'toutes' || g.article.categorie_id === categorie.value) &&
      (!alertesSeulement.value || g.alertes > 0) &&
      (!q || g.article.designation.toLowerCase().includes(q) || g.article.code.toLowerCase().includes(q))
  )
})
const totaux = computed(() => ({
  pieces: lignes.value.reduce((s, l) => s + l.quantite, 0),
  alertes: lignes.value.filter((l) => l.en_alerte).length,
  ruptures: lignes.value.filter((l) => l.quantite === 0).length
}))

const classeCase = (l) =>
  l.quantite === 0 ? 'bg-red-50 border-red-200 text-red-700' : l.en_alerte ? 'bg-amber-50 border-amber-300 text-amber-900' : 'bg-gray-50 border-gray-200 text-gray-800'

// ----- Seuils d'alerte d'un article (toutes ses tailles)
const seuils = ref(null)
const ouvrirSeuils = (g) => (seuils.value = { groupe: g, commun: '', valeurs: Object.fromEntries(g.tailles.map((l) => [l.taille?.id ?? 'u', l.seuil_alerte])) })
const appliquerCommun = () => {
  if (seuils.value.commun === '') return
  for (const k of Object.keys(seuils.value.valeurs)) seuils.value.valeurs[k] = Number(seuils.value.commun)
}
const occupe = ref(false)
async function enregistrerSeuils() {
  const { groupe, valeurs } = seuils.value
  occupe.value = true
  try {
    await api.patch(`/magasins/${props.magasinId}/seuils`, {
      lignes: groupe.tailles.map((l) => ({ article_id: groupe.article.id, taille_id: l.taille?.id ?? null, seuil_alerte: Number(valeurs[l.taille?.id ?? 'u']) }))
    })
    notify.succes(`Seuils de « ${groupe.article.designation} » enregistrés.`)
    seuils.value = null
    await charger()
  } catch (e) {
    notify.erreur(messageErreur(e, 'Seuils non enregistrés.'))
  } finally {
    occupe.value = false
  }
}
</script>

<template>
  <div>
    <div class="grid grid-cols-3 gap-2 sm:gap-3 mb-4">
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 sm:p-4">
        <p class="text-xs text-gray-500">Pièces en stock</p>
        <p class="text-xl sm:text-2xl font-semibold text-gray-900 tabular-nums">{{ totaux.pieces.toLocaleString('fr-FR') }}</p>
      </div>
      <button type="button" @click="alertesSeulement = !alertesSeulement" :aria-pressed="alertesSeulement" class="text-left bg-white rounded-2xl border shadow-sm p-3 sm:p-4" :class="alertesSeulement ? 'border-amber-400 ring-2 ring-amber-200' : 'border-gray-100'">
        <p class="text-xs text-gray-500">Sous le seuil</p>
        <p class="text-xl sm:text-2xl font-semibold tabular-nums" :class="totaux.alertes ? 'text-amber-700' : 'text-gray-900'">{{ totaux.alertes }}</p>
      </button>
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-3 sm:p-4">
        <p class="text-xs text-gray-500">En rupture</p>
        <p class="text-xl sm:text-2xl font-semibold tabular-nums" :class="totaux.ruptures ? 'text-red-700' : 'text-gray-900'">{{ totaux.ruptures }}</p>
      </div>
    </div>

    <div class="bg-white p-3 sm:p-4 rounded-2xl shadow-sm border border-gray-100 mb-4 space-y-3">
      <input v-model="recherche" type="search" aria-label="Rechercher un article" placeholder="Rechercher par code ou désignation…" class="w-full px-4 py-2 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500" />
      <div class="flex gap-2 overflow-x-auto pb-1 -mb-1">
        <button type="button" @click="categorie = 'toutes'" :class="categorie === 'toutes' ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">Toutes</button>
        <button v-for="c in referentiels.categories_article" :key="c.id" type="button" @click="categorie = c.id" :class="categorie === c.id ? 'bg-brand-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'" class="px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap">{{ c.libelle }}</button>
        <label class="ml-auto flex items-center gap-1.5 text-xs text-gray-600 whitespace-nowrap pl-2"><input v-model="alertesSeulement" type="checkbox" class="accent-brand-600" /> Sous le seuil seulement</label>
      </div>
    </div>

    <p v-if="erreur" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
    <div v-else-if="chargement && !lignes.length" class="p-8 text-center text-sm text-gray-400">Chargement du stock…</div>
    <div v-else-if="!filtres.length" class="bg-white rounded-2xl border border-gray-100 p-8 text-center text-sm text-gray-400">Aucun article ne correspond.</div>

    <ul v-else class="space-y-3" :class="chargement ? 'opacity-60' : ''">
      <li v-for="g in filtres" :key="g.article.id" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
        <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
          <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-900">{{ g.article.designation }} <span class="text-xs font-mono font-normal text-gray-400">{{ g.article.code }}</span></p>
            <p class="text-xs text-gray-500">
              {{ g.total }} en stock
              <span v-if="g.ruptures" class="text-red-700"> · {{ g.ruptures }} taille(s) en rupture</span>
              <span v-else-if="g.alertes" class="text-amber-700"> · {{ g.alertes }} sous le seuil</span>
            </p>
          </div>
          <button type="button" @click="ouvrirSeuils(g)" class="text-xs font-medium text-gray-600 hover:text-gray-900 underline-offset-2 hover:underline">Seuils d'alerte</button>
        </div>
        <ul class="flex flex-wrap gap-2" :aria-label="'Stock par taille : ' + g.article.designation">
          <li v-for="l in g.tailles" :key="l.taille?.id ?? 'u'" :class="classeCase(l)" class="min-w-[3.75rem] rounded-xl border px-2.5 py-1.5 text-center" :title="'Seuil d\'alerte : ' + l.seuil_alerte">
            <span class="block text-[11px] opacity-75">{{ l.taille ? l.taille.valeur : 'Unique' }}</span>
            <span class="block text-base font-semibold tabular-nums leading-tight">{{ l.quantite }}</span>
          </li>
        </ul>
      </li>
    </ul>

    <!-- Seuils -->
    <div v-if="seuils" class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-0 sm:p-4 backdrop-blur-sm">
      <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-md w-full p-5 sm:p-6 shadow-2xl max-h-[92vh] overflow-y-auto" role="dialog" aria-modal="true" aria-label="Seuils d'alerte">
        <h3 class="text-lg font-semibold text-gray-900">Seuils d'alerte</h3>
        <p class="text-sm text-gray-500 mb-4">{{ seuils.groupe.article.designation }} — une alerte apparaît quand le stock tombe à ce niveau ou en dessous (0 = pas d'alerte).</p>
        <form @submit.prevent="enregistrerSeuils" class="space-y-4">
          <div v-if="seuils.groupe.tailles.length > 1" class="flex items-end gap-2">
            <label class="flex-1">
              <span class="block text-xs font-semibold text-gray-600 mb-1">Même seuil pour toutes les tailles</span>
              <input v-model="seuils.commun" type="number" min="0" max="10000" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" />
            </label>
            <button type="button" @click="appliquerCommun" class="px-3 py-2 rounded-lg border border-gray-300 text-xs font-semibold">Appliquer</button>
          </div>
          <div class="grid grid-cols-3 sm:grid-cols-4 gap-2">
            <label v-for="l in seuils.groupe.tailles" :key="l.taille?.id ?? 'u'">
              <span class="block text-[11px] font-semibold text-gray-500 mb-1">{{ l.taille ? l.taille.valeur : 'Taille unique' }}</span>
              <input v-model="seuils.valeurs[l.taille?.id ?? 'u']" type="number" min="0" max="10000" required class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm tabular-nums" />
            </label>
          </div>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" @click="seuils = null" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
            <button type="submit" :disabled="occupe" class="bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold disabled:opacity-50">Enregistrer</button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
