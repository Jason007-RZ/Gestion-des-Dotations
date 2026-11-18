<script setup>
// Liste d'éléments simples (code, libellé, rattachement…) modifiables sur place par l'administrateur.
// Le parent fournit les actions (creer, modifier, supprimer) : elles lèvent une erreur en cas d'échec,
// ce composant garde alors la saisie pour que l'utilisateur la corrige.
import { ref, reactive } from 'vue'

const props = defineProps({
  titre: { type: String, required: true },
  description: { type: String, default: '' },
  colonnes: { type: Array, required: true }, // { cle, libelle, type: 'texte'|'select', options, requis, max, placeholder, majuscules, afficher }
  elements: { type: Array, default: () => [] },
  infos: { type: Function, default: null }, // (élément) => texte d'usage (« 3 articles »)
  lectureSeule: { type: Boolean, default: false },
  creer: { type: Function, default: null },
  modifier: { type: Function, default: null },
  supprimer: { type: Function, default: null },
  vide: { type: String, default: 'Aucun élément.' },
  libelleAjout: { type: String, default: 'Ajouter' }
})

const videForm = () => Object.fromEntries(props.colonnes.map((c) => [c.cle, c.type === 'select' ? (c.defaut ?? '') : '']))
const nouveau = reactive(videForm())
const enEdition = ref(null)
const edition = reactive({})
const occupe = ref(false)

const afficher = (el, col) => {
  if (col.afficher) return col.afficher(el)
  if (col.type === 'select') return col.options.find((o) => String(o.valeur) === String(el[col.cle]))?.libelle ?? '—'
  return el[col.cle] ?? '—'
}

const normaliser = (form) =>
  Object.fromEntries(
    props.colonnes.map((c) => {
      let v = form[c.cle]
      if (c.type === 'select') v = v === '' || v === null ? null : v
      else v = typeof v === 'string' ? (c.majuscules ? v.trim().toUpperCase() : v.trim()) : v
      return [c.cle, v]
    })
  )

async function ajouter() {
  if (occupe.value) return
  occupe.value = true
  try {
    await props.creer(normaliser(nouveau))
    Object.assign(nouveau, videForm())
  } catch {
    /* le parent a affiché l'erreur ; on garde la saisie */
  } finally {
    occupe.value = false
  }
}

function editer(el) {
  enEdition.value = el.id
  for (const c of props.colonnes) edition[c.cle] = el[c.cle] ?? ''
}

async function enregistrer(el) {
  if (occupe.value) return
  occupe.value = true
  try {
    await props.modifier(el, normaliser(edition))
    enEdition.value = null
  } catch {
    /* saisie conservée */
  } finally {
    occupe.value = false
  }
}

async function retirer(el) {
  if (occupe.value) return
  occupe.value = true
  try {
    await props.supprimer(el)
  } catch {
    /* erreur affichée par le parent */
  } finally {
    occupe.value = false
  }
}

const classeChamp = 'w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500'
</script>

<template>
  <section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <header class="px-4 sm:px-5 py-4 border-b border-gray-100">
      <h2 class="text-base font-semibold text-gray-900">{{ titre }}</h2>
      <p v-if="description" class="text-xs text-gray-500 mt-0.5">{{ description }}</p>
    </header>

    <ul class="divide-y divide-gray-100">
      <li v-if="elements.length === 0" class="px-5 py-6 text-center text-sm text-gray-400">{{ vide }}</li>
      <li v-for="el in elements" :key="el.id" class="px-4 sm:px-5 py-3">
        <!-- Lecture -->
        <div v-if="enEdition !== el.id" class="flex flex-wrap items-center gap-x-4 gap-y-1">
          <div class="flex-1 min-w-0 flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
            <span v-for="(c, i) in colonnes" :key="c.cle" :class="i === 0 ? 'font-semibold text-gray-900 text-sm' : 'text-sm text-gray-600'">{{ afficher(el, c) }}</span>
            <span v-if="infos && infos(el)" class="text-xs text-gray-400">{{ infos(el) }}</span>
          </div>
          <div v-if="!lectureSeule" class="flex gap-3 text-xs font-medium shrink-0">
            <button type="button" @click="editer(el)" class="text-amber-700 hover:text-amber-900">Modifier</button>
            <button type="button" @click="retirer(el)" :disabled="occupe" class="text-red-600 hover:text-red-800 disabled:opacity-40">Supprimer</button>
          </div>
        </div>
        <!-- Modification -->
        <form v-else @submit.prevent="enregistrer(el)" class="flex flex-wrap items-end gap-2">
          <label v-for="c in colonnes" :key="c.cle" class="flex-1 min-w-[9rem]">
            <span class="block text-[11px] font-semibold text-gray-500 mb-1">{{ c.libelle }}</span>
            <select v-if="c.type === 'select'" v-model="edition[c.cle]" :required="c.requis" :class="classeChamp">
              <option v-if="!c.requis" value="">{{ c.aucun || '—' }}</option>
              <option v-for="o in c.options" :key="o.valeur" :value="o.valeur">{{ o.libelle }}</option>
            </select>
            <input v-else v-model="edition[c.cle]" :required="c.requis" :maxlength="c.max" :class="[classeChamp, c.majuscules ? 'uppercase' : '']" />
          </label>
          <div class="flex gap-2">
            <button type="button" @click="enEdition = null" class="px-3 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-semibold">Annuler</button>
            <button type="submit" :disabled="occupe" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold disabled:opacity-50">Enregistrer</button>
          </div>
        </form>
      </li>
    </ul>

    <form v-if="!lectureSeule && creer" @submit.prevent="ajouter" class="px-4 sm:px-5 py-4 bg-gray-50/70 border-t border-gray-100 flex flex-wrap items-end gap-2">
      <label v-for="c in colonnes" :key="c.cle" class="flex-1 min-w-[9rem]">
        <span class="block text-[11px] font-semibold text-gray-500 mb-1">{{ c.libelle }}</span>
        <select v-if="c.type === 'select'" v-model="nouveau[c.cle]" :required="c.requis" :class="classeChamp">
          <option v-if="!c.requis" value="">{{ c.aucun || '—' }}</option>
          <option v-else value="" disabled>— Choisir —</option>
          <option v-for="o in c.options" :key="o.valeur" :value="o.valeur">{{ o.libelle }}</option>
        </select>
        <input v-else v-model="nouveau[c.cle]" :required="c.requis" :maxlength="c.max" :placeholder="c.placeholder" :class="[classeChamp, c.majuscules ? 'uppercase' : '']" />
      </label>
      <button type="submit" :disabled="occupe" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold disabled:opacity-50">+ {{ libelleAjout }}</button>
    </form>
  </section>
</template>
