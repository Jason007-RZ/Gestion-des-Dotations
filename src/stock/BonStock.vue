<script setup>
// Bon d'entrée en stock (livraison) ou de réforme (effets usés retirés du stock).
// Une ligne par article ; pour un article à tailles, une case par taille : on saisit d'un coup « 40 S, 80 M, 60 L ».
import { ref, computed } from 'vue'
import api, { messageErreur } from '@/services/api'
import { notify } from '@/services/notify'

const props = defineProps({
  mode: { type: String, required: true }, // 'entree' | 'reforme'
  magasin: { type: Object, required: true },
  lignes: { type: Array, default: () => [] } // état du stock du magasin (articles, tailles, quantités)
})
const emit = defineEmits(['fermer', 'enregistre'])
const reforme = computed(() => props.mode === 'reforme')

const aujourdhui = () => new Date().toISOString().slice(0, 10)
const entete = ref({ reference: '', date: aujourdhui(), motif: '' })

// Articles disponibles (d'après l'état du stock) avec leurs tailles et la quantité en stock
const articles = computed(() => {
  const m = new Map()
  for (const l of props.lignes) {
    if (!m.has(l.article.id)) m.set(l.article.id, { ...l.article, tailles: [] })
    m.get(l.article.id).tailles.push({ id: l.taille?.id ?? null, valeur: l.taille?.valeur ?? 'Unique', stock: l.quantite })
  }
  return [...m.values()]
})

const choisis = ref([]) // [{ article, quantites: { [tailleId|'u']: n } }]
const nouvelArticle = ref('')
const disponibles = computed(() => articles.value.filter((a) => !choisis.value.some((c) => c.article.id === a.id) && (!reforme.value || a.tailles.some((t) => t.stock > 0))))
const cle = (t) => t.id ?? 'u'

function ajouterArticle() {
  const a = articles.value.find((x) => x.id === nouvelArticle.value)
  if (!a) return
  choisis.value.push({ article: a, quantites: Object.fromEntries(a.tailles.map((t) => [cle(t), ''])) })
  nouvelArticle.value = ''
}
const retirer = (i) => choisis.value.splice(i, 1)

const lignesBon = computed(() =>
  choisis.value.flatMap((c) =>
    c.article.tailles
      .map((t) => ({ article_id: c.article.id, taille_id: t.id, quantite: Number(c.quantites[cle(t)]) || 0, t, a: c.article }))
      .filter((l) => l.quantite > 0)
  )
)
const totalPieces = computed(() => lignesBon.value.reduce((s, l) => s + l.quantite, 0))
const depassements = computed(() => (reforme.value ? lignesBon.value.filter((l) => l.quantite > l.t.stock) : []))

const occupe = ref(false)
async function enregistrer() {
  if (!lignesBon.value.length) {
    notify.erreur('Saisissez au moins une quantité.')
    return
  }
  if (depassements.value.length) {
    const d = depassements.value[0]
    notify.erreur(`Stock insuffisant : ${d.t.stock} « ${d.a.designation} »${d.t.id ? ' taille ' + d.t.valeur : ''} en stock.`)
    return
  }
  occupe.value = true
  try {
    const corps = {
      reference: entete.value.reference.trim(),
      date: entete.value.date,
      lignes: lignesBon.value.map(({ article_id, taille_id, quantite }) => ({ article_id, taille_id, quantite }))
    }
    if (reforme.value) corps.motif = entete.value.motif.trim()
    const { data } = await api.post(`/magasins/${props.magasin.id}/${reforme.value ? 'reformes' : 'entrees'}`, corps)
    notify.succes(`${reforme.value ? 'Réforme' : 'Entrée'} ${data.reference} enregistrée : ${totalPieces.value} pièce(s), ${corps.lignes.length} ligne(s).`)
    emit('enregistre')
  } catch (e) {
    notify.erreur(messageErreur(e, 'Bon non enregistré.'))
  } finally {
    occupe.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-0 sm:p-4 backdrop-blur-sm">
    <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-2xl w-full shadow-2xl max-h-[94vh] flex flex-col" role="dialog" aria-modal="true" :aria-label="reforme ? 'Réforme' : 'Entrée en stock'">
      <header class="px-5 sm:px-6 pt-5 pb-3 border-b border-gray-100">
        <h3 class="text-lg font-semibold text-gray-900">{{ reforme ? 'Réforme d\'effets' : 'Entrée en stock' }}</h3>
        <p class="text-sm text-gray-500">{{ magasin.libelle }} — {{ reforme ? 'effets usés ou hors d\'usage retirés du stock, sur PV de réforme.' : 'livraison reçue, sur bon de livraison.' }}</p>
      </header>

      <form @submit.prevent="enregistrer" class="flex-1 overflow-y-auto px-5 sm:px-6 py-4 space-y-4" id="form-bon">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label>
            <span class="block text-xs font-semibold text-gray-600 mb-1">{{ reforme ? 'N° du PV de réforme' : 'N° du bon de livraison' }}</span>
            <input v-model="entete.reference" required maxlength="50" :placeholder="reforme ? 'PVR-2026-007' : 'BL-2026-118'" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm uppercase font-mono" />
          </label>
          <label>
            <span class="block text-xs font-semibold text-gray-600 mb-1">Date</span>
            <input v-model="entete.date" type="date" required :max="aujourdhui()" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
          </label>
          <label v-if="reforme" class="sm:col-span-2">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Motif</span>
            <input v-model="entete.motif" required maxlength="255" placeholder="Usure constatée lors de l'inventaire…" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
          </label>
        </div>

        <ul class="space-y-3">
          <li v-for="(c, i) in choisis" :key="c.article.id" class="rounded-xl border border-gray-200 p-3">
            <div class="flex items-start justify-between gap-2 mb-2">
              <p class="text-sm font-semibold text-gray-900">{{ c.article.designation }} <span class="text-xs font-mono font-normal text-gray-400">{{ c.article.code }}</span></p>
              <button type="button" @click="retirer(i)" :aria-label="'Retirer ' + c.article.designation" class="text-gray-400 hover:text-red-600 text-lg leading-none px-1">×</button>
            </div>
            <div class="grid grid-cols-3 sm:grid-cols-5 gap-2">
              <label v-for="t in c.article.tailles" :key="cle(t)" class="block">
                <span class="flex justify-between text-[11px] font-semibold text-gray-500 mb-0.5">
                  <span>{{ t.valeur }}</span>
                  <span v-if="reforme" :class="t.stock ? 'text-gray-400' : 'text-red-500'">/ {{ t.stock }}</span>
                </span>
                <input
                  v-model="c.quantites[cle(t)]"
                  type="number"
                  min="0"
                  :max="reforme ? t.stock : 10000"
                  :disabled="reforme && !t.stock"
                  :aria-label="`Quantité ${c.article.designation} ${t.valeur}`"
                  :class="reforme && Number(c.quantites[cle(t)]) > t.stock ? 'border-red-400 bg-red-50' : 'border-gray-200'"
                  class="w-full border rounded-lg px-2 py-1.5 text-sm tabular-nums disabled:bg-gray-50"
                />
              </label>
            </div>
          </li>
        </ul>

        <div class="flex flex-wrap gap-2">
          <select v-model="nouvelArticle" aria-label="Article à ajouter" class="flex-1 min-w-[12rem] border border-gray-200 rounded-xl px-3 py-2 text-sm bg-white">
            <option value="" disabled>{{ disponibles.length ? '— Ajouter un article —' : reforme ? 'Aucun autre article en stock' : 'Tous les articles sont dans le bon' }}</option>
            <option v-for="a in disponibles" :key="a.id" :value="a.id">{{ a.code }} — {{ a.designation }}</option>
          </select>
          <button type="button" @click="ajouterArticle" :disabled="!nouvelArticle" class="px-4 py-2 rounded-xl border border-brand-600 text-brand-700 hover:bg-brand-50 text-xs font-semibold disabled:opacity-40">+ Ajouter</button>
        </div>
      </form>

      <footer class="px-5 sm:px-6 py-3 border-t border-gray-100 flex flex-wrap items-center gap-2">
        <p class="w-full sm:w-auto text-xs text-gray-500 sm:mr-auto">{{ totalPieces }} pièce(s) · {{ lignesBon.length }} ligne(s)</p>
        <button type="button" @click="emit('fermer')" class="flex-1 sm:flex-none bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
        <button type="submit" form="form-bon" :disabled="occupe" :class="reforme ? 'bg-gray-900 hover:bg-black' : 'bg-brand-600 hover:bg-brand-700'" class="flex-[2] sm:flex-none text-white px-5 py-2.5 rounded-xl text-xs font-semibold disabled:opacity-50">
          {{ reforme ? 'Enregistrer la réforme' : 'Enregistrer l\'entrée' }}
        </button>
      </footer>
    </section>
  </div>
</template>
