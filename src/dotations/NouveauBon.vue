<script setup>
// Nouveau bon de dotation (cahier des charges §3).
// 1. On choisit le militaire, le magasin et le type ; 2. le serveur propose les lignes (tableau de dotation de la
// catégorie, tailles d'après la dernière mensuration, stock disponible) ; 3. on ajuste et on valide.
import { ref, computed, watch } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { notify } from '@/services/notify'
import { ROLES } from '@/services/roles'
import { referentiels } from '@/services/referentiels'
import { dateCourte } from '@/militaires/format'

const props = defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['fermer', 'cree'])

const magasins = computed(() => (props.user?.role === ROLES.ADMIN ? referentiels.magasins : props.user?.magasins || []))
const magasinId = ref(magasins.value[0]?.id ?? '')
const type = ref('initiale')

// ----- Choix du militaire (recherche serveur)
const recherche = ref('')
const resultats = ref([])
const militaire = ref(null)
let minuterie = null
let jeton = 0
watch(recherche, (q) => {
  clearTimeout(minuterie)
  if (q.trim().length < 2) {
    resultats.value = []
    return
  }
  minuterie = setTimeout(async () => {
    const moi = ++jeton
    try {
      const { data } = await api.get('/militaires', { params: { q: q.trim(), statut: 'actif', par_page: 8 } })
      if (moi === jeton) resultats.value = enListe(data)
    } catch (e) {
      notify.erreur(messageErreur(e, 'Recherche impossible.'))
    }
  }, 250)
})
function choisirMilitaire(m) {
  militaire.value = m
  recherche.value = ''
  resultats.value = []
  proposition.value = null
}

// ----- Proposition
const proposition = ref(null)
const lignes = ref([])
const chargement = ref(false)

async function preparer() {
  chargement.value = true
  try {
    const { data } = await api.get('/dotations/proposition', { params: { militaire_id: militaire.value.id, magasin_id: magasinId.value, type: type.value } })
    proposition.value = data
    lignes.value = data.lignes.map((l) => ({
      ...l,
      tailleId: l.taille?.id ?? '',
      quantite: l.quantite,
      bloquee: !!l.probleme && l.probleme.startsWith('Déjà'), // déjà en service : interdit (pas de double dotation)
      inclure: !(l.probleme && l.probleme.startsWith('Déjà'))
    }))
  } catch (e) {
    notify.erreur(messageErreur(e, 'Proposition impossible.'))
  } finally {
    chargement.value = false
  }
}
watch([magasinId, type], () => {
  if (proposition.value) preparer()
})

// Problème d'une ligne, recalculé à chaque changement de taille ou de quantité
function probleme(l) {
  if (l.bloquee) return l.probleme
  if (l.article.grille_taille && !l.tailleId) return 'Choisissez une taille.'
  const stock = l.article.grille_taille ? l.tailles.find((t) => t.id === l.tailleId)?.stock ?? 0 : l.stock
  const q = Number(l.quantite)
  if (!Number.isInteger(q) || q < 1) return 'Quantité invalide.'
  if (q > l.quantite_reglementaire) return `${l.quantite_reglementaire} au plus pour cette catégorie.`
  if (stock < q) return `Stock insuffisant : ${stock} disponible(s).`
  return null
}
const retenues = computed(() => lignes.value.filter((l) => l.inclure))
const bloquantes = computed(() => retenues.value.filter((l) => probleme(l)))
const pieces = computed(() => retenues.value.reduce((s, l) => s + (Number(l.quantite) || 0), 0))
const bloque = computed(() => !proposition.value || proposition.value.militaire.statut !== 'actif' || (type.value === 'initiale' && proposition.value.deja_dote))

const observation = ref('')
const occupe = ref(false)
async function valider() {
  occupe.value = true
  try {
    const { data } = await api.post('/dotations', {
      militaire_id: militaire.value.id,
      magasin_id: magasinId.value,
      type: type.value,
      date: proposition.value.date,
      observation: observation.value.trim() || null,
      lignes: retenues.value.map((l) => ({ article_id: l.article.id, taille_id: l.tailleId === '' ? null : l.tailleId, quantite: Number(l.quantite), remplace_ligne_id: l.remplace_ligne_id }))
    })
    notify.succes(`Bon ${data.numero_bon} enregistré : ${data.nb_pieces} pièce(s) remises à ${data.militaire.nom} ${data.militaire.prenoms}.`)
    emit('cree', data.id)
  } catch (e) {
    notify.erreur(messageErreur(e, 'Bon non enregistré.'))
  } finally {
    occupe.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-0 sm:p-4 backdrop-blur-sm">
    <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-4xl w-full shadow-2xl h-[94vh] sm:h-auto sm:max-h-[94vh] flex flex-col" role="dialog" aria-modal="true" aria-label="Nouveau bon de dotation">
      <header class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-start gap-3">
        <div class="flex-1">
          <h3 class="text-lg font-semibold text-gray-900">Nouveau bon de dotation</h3>
          <p class="text-sm text-gray-500">Lignes proposées d'après le tableau de dotation et les mensurations ; le stock est vérifié.</p>
        </div>
        <button type="button" @click="emit('fermer')" aria-label="Fermer" class="p-2 -m-1 rounded-lg text-gray-500 hover:bg-gray-100 text-xl leading-none">×</button>
      </header>

      <div class="flex-1 overflow-y-auto px-5 sm:px-6 py-4 space-y-4">
        <!-- 1. Militaire, magasin, type -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
          <div class="lg:col-span-2 relative">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Militaire</span>
            <div v-if="militaire" class="flex items-center gap-3 rounded-xl border border-brand-200 bg-brand-50/50 px-3 py-2">
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-900 truncate">{{ militaire.nom }} {{ militaire.prenoms }}</p>
                <p class="text-xs text-gray-600">{{ militaire.matricule }} · {{ militaire.grade?.libelle }}<template v-if="militaire.grade?.categorie?.libelle !== militaire.grade?.libelle"> ({{ militaire.grade?.categorie?.libelle }})</template> · {{ militaire.unite?.code }}</p>
              </div>
              <button type="button" @click="militaire = null; proposition = null" class="text-xs font-semibold text-brand-700 hover:underline">Changer</button>
            </div>
            <template v-else>
              <input v-model="recherche" type="search" aria-label="Rechercher le militaire" placeholder="Matricule ou nom (2 lettres au moins)…" autocomplete="off" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
              <ul v-if="resultats.length" class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-lg max-h-64 overflow-y-auto" role="listbox">
                <li v-for="m in resultats" :key="m.id">
                  <button type="button" role="option" @click="choisirMilitaire(m)" class="w-full text-left px-3 py-2 hover:bg-gray-50">
                    <span class="block text-sm font-medium text-gray-900">{{ m.nom }} {{ m.prenoms }}</span>
                    <span class="block text-xs text-gray-500">{{ m.matricule }} · {{ m.grade?.libelle }} · {{ m.unite?.code }}<span v-if="!m.mensuration_actuelle" class="text-amber-700"> · pas mesuré</span></span>
                  </button>
                </li>
              </ul>
            </template>
          </div>
          <label>
            <span class="block text-xs font-semibold text-gray-600 mb-1">Magasin</span>
            <select v-model="magasinId" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm bg-white">
              <option v-for="m in magasins" :key="m.id" :value="m.id">{{ m.libelle }}</option>
            </select>
          </label>
        </div>

        <fieldset class="flex flex-wrap gap-2">
          <legend class="sr-only">Type de bon</legend>
          <label v-for="t in [{ v: 'initiale', l: 'Dotation initiale', d: 'à l\'incorporation' }, { v: 'renouvellement', l: 'Renouvellement', d: 'articles échus' }]" :key="t.v" :class="type === t.v ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-gray-200'" class="flex-1 min-w-[10rem] cursor-pointer rounded-xl border px-3 py-2">
            <input v-model="type" type="radio" :value="t.v" name="type-bon" class="sr-only" />
            <span class="block text-sm font-semibold text-gray-900">{{ t.l }}</span>
            <span class="block text-xs text-gray-500">{{ t.d }}</span>
          </label>
          <div class="flex-1 min-w-[10rem] rounded-xl border border-dashed border-gray-200 px-3 py-2 text-gray-400" title="Un remplacement se fait à partir d'un incident (étape suivante)">
            <span class="block text-sm font-semibold">Remplacement</span>
            <span class="block text-xs">depuis un incident (perte, détérioration)</span>
          </div>
        </fieldset>

        <button v-if="militaire && !proposition" type="button" @click="preparer" :disabled="chargement || !magasinId" class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold disabled:opacity-50">
          {{ chargement ? 'Préparation…' : 'Préparer le bon' }}
        </button>

        <!-- 2. Lignes proposées -->
        <template v-if="proposition">
          <ul v-if="proposition.avertissements.length" class="space-y-1.5">
            <li v-for="(a, i) in proposition.avertissements" :key="i" class="text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">{{ a }}</li>
          </ul>

          <div v-if="lignes.length" class="rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-3 py-2 bg-gray-50 text-xs text-gray-600 flex flex-wrap justify-between gap-2">
              <span>Catégorie <strong>{{ proposition.categorie?.libelle }}</strong> · date du bon {{ dateCourte(proposition.date) }}</span>
              <span>{{ retenues.length }} ligne(s) retenue(s) · {{ pieces }} pièce(s)</span>
            </div>
            <ul class="divide-y divide-gray-100">
              <li v-for="l in lignes" :key="l.article.id + '-' + (l.remplace_ligne_id ?? '')" class="px-3 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-2" :class="l.inclure ? '' : 'bg-gray-50/80 opacity-70'">
                <input v-model="l.inclure" type="checkbox" :disabled="l.bloquee" :aria-label="'Inclure ' + l.article.designation" class="accent-brand-600 h-4 w-4" />
                <div class="flex-1 min-w-[11rem]">
                  <p class="text-sm font-medium text-gray-900">{{ l.article.designation }} <span v-if="l.article.a_reintegrer" class="text-[11px] text-violet-700">(à réintégrer)</span></p>
                  <p class="text-xs text-gray-500">
                    {{ l.date_echeance ? 'échéance ' + dateCourte(l.date_echeance) : 'sans renouvellement' }}
                    <span v-if="l.remplace_echeance"> · remplace l'article échu le {{ dateCourte(l.remplace_echeance) }}</span>
                  </p>
                </div>
                <select v-if="l.article.grille_taille" v-model="l.tailleId" :disabled="!l.inclure" :aria-label="'Taille ' + l.article.designation" class="w-28 border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white">
                  <option value="" disabled>Taille</option>
                  <option v-for="t in l.tailles" :key="t.id" :value="t.id">{{ t.valeur }} ({{ t.stock }})</option>
                </select>
                <span v-else class="w-28 text-xs text-gray-500">Unique ({{ l.stock }})</span>
                <label class="flex items-center gap-1 text-xs text-gray-500">
                  Qté
                  <input v-model="l.quantite" type="number" min="1" :max="l.quantite_reglementaire" :disabled="!l.inclure" :aria-label="'Quantité ' + l.article.designation" class="w-14 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-900 tabular-nums" />
                  <span>/ {{ l.quantite_reglementaire }}</span>
                </label>
                <p v-if="probleme(l)" class="w-full sm:w-auto sm:basis-full text-xs pl-7" :class="l.inclure ? 'text-red-700' : 'text-gray-500'">{{ probleme(l) }}</p>
              </li>
            </ul>
          </div>

          <label class="block">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Observation (facultatif)</span>
            <input v-model="observation" maxlength="255" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm" />
          </label>
        </template>
      </div>

      <footer class="px-5 sm:px-6 py-3 border-t border-gray-100 flex flex-wrap items-center gap-2">
        <p class="w-full sm:w-auto sm:mr-auto text-xs" :class="bloquantes.length ? 'text-red-700' : 'text-gray-500'">
          <template v-if="bloquantes.length">{{ bloquantes.length }} ligne(s) à corriger ou à décocher.</template>
          <template v-else-if="proposition">Le stock sera diminué et les échéances calculées à la validation.</template>
        </p>
        <button type="button" @click="emit('fermer')" class="flex-1 sm:flex-none bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
        <button type="button" @click="valider" :disabled="occupe || bloque || !retenues.length || bloquantes.length > 0" class="flex-[2] sm:flex-none bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 rounded-xl text-xs font-semibold disabled:opacity-40">
          Valider le bon
        </button>
      </footer>
    </section>
  </div>
</template>
