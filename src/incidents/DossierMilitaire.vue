<script setup>
// Dossier d'un militaire : déclarer une perte ou une détérioration, réintégrer des effets, clôturer le dossier.
import { ref, computed, watch } from 'vue'
import api, { messageErreur, enListe } from '@/services/api'
import { notify } from '@/services/notify'
import { ROLES } from '@/services/roles'
import { referentiels } from '@/services/referentiels'
import { STATUTS, dateCourte } from '@/militaires/format'
import { ECHEANCES } from '@/dotations/format'

const props = defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['incident'])
const admin = computed(() => props.user?.role === ROLES.ADMIN)
const magasins = computed(() => (admin.value ? referentiels.magasins : props.user?.magasins || []))
const aujourdhui = () => new Date().toISOString().slice(0, 10)

// ----- Recherche du militaire
const recherche = ref('')
const resultats = ref([])
const militaire = ref(null)
let minuterie = null
watch(recherche, (q) => {
  clearTimeout(minuterie)
  if (q.trim().length < 2) return (resultats.value = [])
  minuterie = setTimeout(async () => {
    try {
      resultats.value = enListe((await api.get('/militaires', { params: { q: q.trim(), par_page: 8 } })).data)
    } catch (e) {
      notify.erreur(messageErreur(e, 'Recherche impossible.'))
    }
  }, 250)
})

const paquetage = ref([])
async function choisir(m) {
  recherche.value = ''
  resultats.value = []
  militaire.value = m
  await chargerPaquetage()
}
async function chargerPaquetage() {
  try {
    const [p, f] = await Promise.all([api.get(`/militaires/${militaire.value.id}/paquetage`), api.get(`/militaires/${militaire.value.id}`)])
    paquetage.value = p.data || []
    militaire.value = f.data
    retour.value = Object.fromEntries(paquetage.value.map((l) => [l.id, !!l.article?.a_reintegrer]))
  } catch (e) {
    notify.erreur(messageErreur(e, 'Paquetage indisponible.'))
  }
}
const aRendre = computed(() => paquetage.value.filter((l) => l.article?.a_reintegrer))

// ----- Déclaration d'incident
const declaration = ref(null)
const ouvrirDeclaration = (l) => (declaration.value = { ligne: l, type: 'perte', quantite: l.quantite, date: aujourdhui(), pv_numero: '', imputable: null, observation: '' })
const occupe = ref(false)
async function declarer() {
  const d = declaration.value
  occupe.value = true
  try {
    const { data } = await api.post(`/dotation-lignes/${d.ligne.id}/incidents`, {
      type: d.type, quantite: Number(d.quantite), date: d.date, pv_numero: d.pv_numero.trim(), imputable: d.imputable, observation: d.observation.trim() || null
    })
    notify.succes(`${d.type === 'perte' ? 'Perte' : 'Détérioration'} enregistrée (PV ${data.pv_numero}).`)
    declaration.value = null
    await chargerPaquetage()
    emit('incident', data)
  } catch (e) {
    notify.erreur(messageErreur(e, 'Incident non enregistré.'))
  } finally {
    occupe.value = false
  }
}

// ----- Réintégration
const retour = ref({})
const magasinRetour = ref('')
watch(magasins, (l) => (magasinRetour.value ||= l[0]?.id ?? ''), { immediate: true })
const dateRetour = ref(aujourdhui())
const choisis = computed(() => paquetage.value.filter((l) => retour.value[l.id]))
async function reintegrer() {
  occupe.value = true
  try {
    const { data } = await api.post(`/militaires/${militaire.value.id}/reintegration`, { magasin_id: magasinRetour.value, date: dateRetour.value, lignes: choisis.value.map((l) => l.id) })
    notify.succes(`Réintégration ${data.reference} : ${data.lignes.length} article(s) rendu(s) au magasin.`)
    await chargerPaquetage()
  } catch (e) {
    notify.erreur(messageErreur(e, 'Réintégration non enregistrée.'))
  } finally {
    occupe.value = false
  }
}

// ----- Clôture (administrateur)
const cloture = ref({ statut: 'mute', motif: '' })
async function cloturer() {
  occupe.value = true
  try {
    await api.patch(`/militaires/${militaire.value.id}/statut`, { statut: cloture.value.statut, motif: cloture.value.motif.trim() })
    notify.succes(`Dossier clôturé : ${STATUTS[cloture.value.statut].libelle.toLowerCase()}.`)
    cloture.value.motif = ''
    await chargerPaquetage()
  } catch (e) {
    notify.erreur(messageErreur(e, 'Clôture refusée.'))
  } finally {
    occupe.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 relative">
      <label class="block">
        <span class="block text-xs font-semibold text-gray-600 mb-1">Militaire</span>
        <input v-model="recherche" type="search" aria-label="Rechercher le militaire" placeholder="Matricule ou nom (2 lettres au moins)…" autocomplete="off" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
      </label>
      <ul v-if="resultats.length" class="absolute z-10 left-4 right-4 mt-1 bg-white border border-gray-200 rounded-xl shadow-lg max-h-64 overflow-y-auto" role="listbox">
        <li v-for="m in resultats" :key="m.id">
          <button type="button" role="option" @click="choisir(m)" class="w-full text-left px-3 py-2 hover:bg-gray-50">
            <span class="block text-sm font-medium text-gray-900">{{ m.nom }} {{ m.prenoms }}</span>
            <span class="block text-xs text-gray-500">{{ m.matricule }} · {{ m.grade?.libelle }} · {{ m.unite?.code }} · {{ STATUTS[m.statut]?.libelle }}</span>
          </button>
        </li>
      </ul>
    </div>

    <template v-if="militaire">
      <section class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
        <header class="px-4 sm:px-5 py-3.5 border-b border-gray-100 flex flex-wrap items-center gap-2">
          <h2 class="flex-1 text-base font-semibold text-gray-900">{{ militaire.nom }} {{ militaire.prenoms }} <span class="text-xs font-normal text-gray-500">{{ militaire.matricule }} · {{ militaire.unite?.code }}</span></h2>
          <span :class="STATUTS[militaire.statut]?.classe" class="text-xs px-2 py-0.5 rounded-full border">{{ STATUTS[militaire.statut]?.libelle }}</span>
        </header>
        <p v-if="!paquetage.length" class="px-5 py-4 text-sm text-gray-400">Aucun article en service.</p>
        <ul v-else class="divide-y divide-gray-100">
          <li v-for="l in paquetage" :key="l.id" class="px-4 sm:px-5 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <input v-model="retour[l.id]" type="checkbox" :aria-label="'Réintégrer ' + l.article?.designation" class="accent-brand-600 h-4 w-4" />
            <span class="flex-1 min-w-[10rem] text-sm text-gray-900">
              {{ l.article?.designation }}<span v-if="l.taille" class="text-gray-500"> · {{ l.taille.valeur }}</span><span class="text-gray-500"> × {{ l.quantite }}</span>
              <span v-if="l.article?.a_reintegrer" class="ml-1 text-[11px] text-violet-700">à réintégrer</span>
            </span>
            <span :class="ECHEANCES[l.echeance]?.classe" class="text-[11px] px-2 py-0.5 rounded-full border whitespace-nowrap">{{ l.date_echeance ? dateCourte(l.date_echeance) : 'sans échéance' }}</span>
            <button type="button" @click="ouvrirDeclaration(l)" class="text-xs font-medium text-red-700 hover:text-red-900">Perte / détérioration</button>
          </li>
        </ul>
      </section>

      <!-- Réintégration -->
      <section v-if="paquetage.length" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5">
        <h3 class="text-sm font-semibold text-gray-900">Réintégration</h3>
        <p class="text-xs text-gray-500 mb-3">Les articles cochés ci-dessus retournent au magasin et le stock est augmenté. Les articles « à réintégrer » sont cochés d'office.</p>
        <form @submit.prevent="reintegrer" class="flex flex-wrap items-end gap-2">
          <label class="flex-1 min-w-[12rem]">
            <span class="block text-[11px] font-semibold text-gray-500 mb-1">Magasin</span>
            <select v-model="magasinRetour" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
              <option v-for="m in magasins" :key="m.id" :value="m.id">{{ m.libelle }}</option>
            </select>
          </label>
          <label>
            <span class="block text-[11px] font-semibold text-gray-500 mb-1">Date</span>
            <input v-model="dateRetour" type="date" required :max="aujourdhui()" class="border border-gray-200 rounded-lg px-3 py-2 text-sm" />
          </label>
          <button type="submit" :disabled="occupe || !choisis.length" class="px-4 py-2 rounded-lg bg-violet-700 hover:bg-violet-800 text-white text-xs font-semibold disabled:opacity-40">Réintégrer {{ choisis.length }} article(s)</button>
        </form>
      </section>

      <!-- Clôture -->
      <section v-if="admin && militaire.statut === 'actif'" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5">
        <h3 class="text-sm font-semibold text-gray-900">Clôture du dossier (mutation ou radiation)</h3>
        <p v-if="aRendre.length" class="mt-2 text-sm text-amber-900 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">
          Encore {{ aRendre.length }} article(s) à réintégrer : {{ aRendre.map((l) => l.article.designation).join(', ') }}. Réintégrez-les (ou déclarez leur perte) avant de clôturer.
        </p>
        <form v-else @submit.prevent="cloturer" class="mt-3 flex flex-wrap items-end gap-2">
          <fieldset class="flex gap-3 text-sm">
            <legend class="sr-only">Nouveau statut</legend>
            <label class="flex items-center gap-1.5"><input v-model="cloture.statut" type="radio" value="mute" class="accent-brand-600" /> Muté</label>
            <label class="flex items-center gap-1.5"><input v-model="cloture.statut" type="radio" value="radie" class="accent-brand-600" /> Radié</label>
          </fieldset>
          <input v-model="cloture.motif" required maxlength="255" aria-label="Motif de la clôture" placeholder="Motif (mutation au 1RFI, fin de contrat…)" class="flex-1 min-w-[12rem] border border-gray-200 rounded-lg px-3 py-2 text-sm" />
          <button type="submit" :disabled="occupe" class="px-4 py-2 rounded-lg bg-gray-900 hover:bg-black text-white text-xs font-semibold disabled:opacity-40">Clôturer</button>
        </form>
      </section>
    </template>

    <!-- Déclaration -->
    <div v-if="declaration" class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 p-0 sm:p-4 backdrop-blur-sm">
      <section class="bg-white rounded-t-2xl sm:rounded-2xl max-w-md w-full p-5 sm:p-6 shadow-2xl max-h-[92vh] overflow-y-auto" role="dialog" aria-modal="true" aria-label="Déclarer un incident">
        <h3 class="text-lg font-semibold text-gray-900">Perte ou détérioration</h3>
        <p class="text-sm text-gray-500 mb-4">{{ declaration.ligne.article?.designation }}<span v-if="declaration.ligne.taille"> · {{ declaration.ligne.taille.valeur }}</span> — bon {{ declaration.ligne.dotation?.numero_bon }}</p>
        <form @submit.prevent="declarer" class="space-y-4">
          <fieldset class="flex gap-2">
            <legend class="sr-only">Nature</legend>
            <label v-for="t in [{ v: 'perte', l: 'Perte' }, { v: 'deterioration', l: 'Détérioration' }]" :key="t.v" :class="declaration.type === t.v ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500' : 'border-gray-200'" class="flex-1 rounded-xl border px-3 py-2 text-sm font-medium cursor-pointer text-center">
              <input v-model="declaration.type" type="radio" :value="t.v" class="sr-only" />{{ t.l }}
            </label>
          </fieldset>
          <div class="grid grid-cols-2 gap-3">
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">N° du PV</span>
              <input v-model="declaration.pv_numero" required maxlength="30" placeholder="PV-2026-031" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm uppercase font-mono" />
            </label>
            <label>
              <span class="block text-xs font-semibold text-gray-600 mb-1">Date</span>
              <input v-model="declaration.date" type="date" required :max="aujourdhui()" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
            </label>
          </div>
          <label v-if="declaration.ligne.quantite > 1" class="block">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Quantité concernée (sur {{ declaration.ligne.quantite }})</span>
            <input v-model="declaration.quantite" type="number" min="1" :max="declaration.ligne.quantite" required class="w-28 border border-gray-200 rounded-xl px-3 py-2.5 text-sm" />
          </label>
          <fieldset>
            <legend class="text-xs font-semibold text-gray-600 mb-1.5">Imputable au militaire ?</legend>
            <div class="flex gap-4 text-sm">
              <label class="flex items-center gap-1.5"><input v-model="declaration.imputable" type="radio" :value="true" name="imputable" required class="accent-brand-600" /> Oui</label>
              <label class="flex items-center gap-1.5"><input v-model="declaration.imputable" type="radio" :value="false" name="imputable" class="accent-brand-600" /> Non</label>
            </div>
          </fieldset>
          <label class="block">
            <span class="block text-xs font-semibold text-gray-600 mb-1">Circonstances</span>
            <textarea v-model="declaration.observation" rows="2" maxlength="500" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm"></textarea>
          </label>
          <div class="flex justify-end gap-2">
            <button type="button" @click="declaration = null" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Annuler</button>
            <button type="submit" :disabled="occupe" class="bg-red-700 hover:bg-red-800 text-white px-5 py-2.5 rounded-xl text-xs font-semibold disabled:opacity-40">Enregistrer le PV</button>
          </div>
        </form>
      </section>
    </div>
  </div>
</template>
