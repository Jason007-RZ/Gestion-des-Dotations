<script setup>
// Fiche d'un militaire : identité, mensuration actuelle et historique, actions selon le rôle.
// Cahier des charges : la mensuration la plus récente préremplit les tailles du bon de dotation.
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import api, { messageErreur } from '@/services/api'
import { ROLES } from '@/services/roles'
import { confirmer } from '@/services/confirm'
import { notify } from '@/services/notify'
import { referentiels } from '@/services/referentiels'
import { STATUTS, dateCourte, anciennete } from './format'
import { ECHEANCES } from '@/dotations/format'

const props = defineProps({
  id: { type: Number, required: true },
  user: { type: Object, default: null },
  recharger: { type: Number, default: 0 } // incrémenté par le parent après une modification de la fiche
})
const emit = defineEmits(['fermer', 'modifier', 'maj'])

const role = computed(() => props.user?.role)
const admin = computed(() => role.value === ROLES.ADMIN)
const peutMesurer = computed(() => [ROLES.ADMIN, ROLES.MAGASINIER].includes(role.value))

const fiche = ref(null)
const erreur = ref('')
const chargement = ref(true)

const paquetage = ref([])
async function charger() {
  chargement.value = true
  erreur.value = ''
  try {
    fiche.value = (await api.get(`/militaires/${props.id}`)).data
    // Le paquetage est secondaire : s'il n'est pas disponible, la fiche reste consultable.
    try {
      paquetage.value = (await api.get(`/militaires/${props.id}/paquetage`)).data || []
    } catch {
      paquetage.value = []
    }
  } catch (e) {
    erreur.value = messageErreur(e, 'Impossible de charger la fiche.')
  } finally {
    chargement.value = false
  }
}
watch(() => [props.id, props.recharger], charger)

// ----- Mensurations
const MESURES = [
  { champ: 'taille_vetement_id', cle: 'taille_vetement', libelle: 'Vêtement' },
  { champ: 'pointure_id', cle: 'pointure', libelle: 'Pointure' },
  { champ: 'tour_tete_id', cle: 'tour_tete', libelle: 'Tour de tête' }
]
const taillesPour = (cle) => {
  const grilleId = referentiels.grilles_mensuration?.[cle]
  return referentiels.grilles_taille.find((g) => g.id === grilleId)?.tailles || []
}
const actuelle = computed(() => fiche.value?.mensuration_actuelle || null)
const historique = computed(() => fiche.value?.mensurations || [])

const saisie = ref(null)
const aujourdhui = () => new Date().toISOString().slice(0, 10)
const ouvrirSaisie = () =>
  (saisie.value = {
    date_mesure: aujourdhui(),
    taille_vetement_id: actuelle.value?.taille_vetement?.id ?? '',
    pointure_id: actuelle.value?.pointure?.id ?? '',
    tour_tete_id: actuelle.value?.tour_tete?.id ?? ''
  })
const occupe = ref(false)

async function enregistrerMesure() {
  const s = saisie.value
  const corps = { date_mesure: s.date_mesure }
  for (const m of MESURES) corps[m.champ] = s[m.champ] === '' ? null : s[m.champ]
  occupe.value = true
  try {
    await api.post(`/militaires/${props.id}/mensurations`, corps)
    notify.succes('Mesures enregistrées.')
    saisie.value = null
    await charger()
    emit('maj')
  } catch (e) {
    notify.erreur(messageErreur(e, 'Mesures non enregistrées.'))
  } finally {
    occupe.value = false
  }
}

// Une valeur a-t-elle changé par rapport à la mesure précédente ? (mise en évidence dans l'historique)
const change = (i, cle) => {
  const suivante = historique.value[i + 1]
  return suivante && (historique.value[i][cle]?.id ?? null) !== (suivante[cle]?.id ?? null)
}

// ----- Statut (administrateur)
const changementStatut = ref(null)
const ouvrirStatut = () => (changementStatut.value = { statut: fiche.value.statut === 'actif' ? 'mute' : 'actif', motif: '' })
async function enregistrerStatut() {
  const c = changementStatut.value
  occupe.value = true
  try {
    await api.patch(`/militaires/${props.id}/statut`, { statut: c.statut, motif: c.motif.trim() || null })
    notify.succes(`Statut : ${STATUTS[c.statut].libelle.toLowerCase()}.`)
    changementStatut.value = null
    await charger()
    emit('maj')
  } catch (e) {
    notify.erreur(messageErreur(e, 'Changement de statut refusé.'))
  } finally {
    occupe.value = false
  }
}

async function supprimer() {
  const f = fiche.value
  const ok = await confirmer({
    titre: 'Supprimer la fiche',
    message: `La fiche de ${f.nom} ${f.prenoms} (${f.matricule}) et ses mensurations seront supprimées. Réservé aux erreurs de saisie : un militaire qui quitte l'armée se radie.`,
    bouton: 'Supprimer'
  })
  if (!ok) return
  try {
    await api.delete(`/militaires/${props.id}`)
    notify.succes('Fiche supprimée.')
    emit('maj')
    emit('fermer')
  } catch (e) {
    notify.erreur(messageErreur(e, 'Suppression refusée.'))
  }
}

// Échap ferme la fiche (sauf si un formulaire est ouvert : il se ferme d'abord)
const surTouche = (e) => {
  if (e.key !== 'Escape') return
  if (saisie.value) saisie.value = null
  else if (changementStatut.value) changementStatut.value = null
  else emit('fermer')
}
onMounted(() => {
  charger()
  window.addEventListener('keydown', surTouche)
})
onBeforeUnmount(() => window.removeEventListener('keydown', surTouche))
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end bg-black/40 backdrop-blur-[1px]" @click.self="emit('fermer')">
    <section class="bg-page w-full sm:max-w-xl h-full overflow-y-auto shadow-2xl" role="dialog" aria-modal="true" :aria-label="fiche ? `Fiche de ${fiche.nom} ${fiche.prenoms}` : 'Fiche militaire'">
      <header class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 sm:px-6 py-4 flex items-start gap-3">
        <div class="flex-1 min-w-0">
          <template v-if="fiche">
            <h2 class="text-lg font-semibold text-gray-900 leading-tight">{{ fiche.nom }} {{ fiche.prenoms }}</h2>
            <p class="text-xs text-gray-500 mt-0.5 flex flex-wrap items-center gap-2">
              <span class="font-mono">{{ fiche.matricule }}</span>
              <span :class="STATUTS[fiche.statut]?.classe" class="px-2 py-0.5 rounded-full border">{{ STATUTS[fiche.statut]?.libelle }}</span>
            </p>
          </template>
          <h2 v-else class="text-lg font-semibold text-gray-900">Fiche militaire</h2>
        </div>
        <button type="button" @click="emit('fermer')" aria-label="Fermer la fiche" class="p-2 -m-1 rounded-lg text-gray-500 hover:bg-gray-100 text-xl leading-none">×</button>
      </header>

      <div class="p-4 sm:p-6 space-y-4">
        <p v-if="erreur" class="p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-sm rounded-r-lg">{{ erreur }}</p>
        <p v-else-if="chargement && !fiche" class="text-center text-sm text-gray-400 py-8">Chargement…</p>

        <template v-if="fiche">
          <!-- Identité -->
          <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5">
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
              <div>
                <dt class="text-xs text-gray-500">Grade</dt>
                <dd class="font-medium text-gray-900">{{ fiche.grade?.libelle }}</dd>
              </div>
              <div>
                <dt class="text-xs text-gray-500">Catégorie</dt>
                <dd class="font-medium text-gray-900">{{ fiche.grade?.categorie?.libelle }}</dd>
              </div>
              <div class="col-span-2">
                <dt class="text-xs text-gray-500">Unité</dt>
                <dd class="font-medium text-gray-900">{{ fiche.unite?.code }} — {{ fiche.unite?.libelle }}</dd>
              </div>
              <div class="col-span-2">
                <dt class="text-xs text-gray-500">Incorporé le</dt>
                <dd class="font-medium text-gray-900">{{ dateCourte(fiche.date_incorporation) }} <span class="text-gray-500 font-normal">({{ anciennete(fiche.date_incorporation) }})</span></dd>
              </div>
            </dl>
            <div v-if="admin" class="mt-4 pt-3 border-t border-gray-100 flex flex-wrap gap-x-4 gap-y-2 text-xs font-medium">
              <button type="button" @click="emit('modifier', fiche)" class="text-amber-700 hover:text-amber-900">Modifier la fiche</button>
              <button type="button" @click="ouvrirStatut" class="text-sky-700 hover:text-sky-900">Changer le statut</button>
              <button type="button" @click="supprimer" class="text-red-600 hover:text-red-800">Supprimer</button>
            </div>

            <form v-if="changementStatut" @submit.prevent="enregistrerStatut" class="mt-3 p-3 rounded-xl bg-sky-50/60 border border-sky-100 space-y-3">
              <fieldset>
                <legend class="text-xs font-semibold text-gray-700 mb-1.5">Nouveau statut</legend>
                <div class="flex flex-wrap gap-3 text-sm">
                  <label v-for="(s, cle) in STATUTS" :key="cle" class="flex items-center gap-1.5">
                    <input v-model="changementStatut.statut" type="radio" :value="cle" :disabled="cle === fiche.statut" name="statut" class="accent-brand-600" />
                    <span :class="cle === fiche.statut ? 'text-gray-400' : ''">{{ s.libelle }}</span>
                  </label>
                </div>
              </fieldset>
              <label v-if="changementStatut.statut !== 'actif'" class="block">
                <span class="block text-xs font-semibold text-gray-700 mb-1">Motif ({{ changementStatut.statut === 'mute' ? 'mutation' : 'radiation' }})</span>
                <input v-model="changementStatut.motif" required maxlength="255" placeholder="Ex. : mutation au 1RFI, fin de contrat…" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" />
              </label>
              <p class="text-xs text-gray-500">Avant une mutation ou une radiation, les articles « à réintégrer » (sac à dos, couverture…) doivent avoir été rendus.</p>
              <div class="flex justify-end gap-2">
                <button type="button" @click="changementStatut = null" class="px-3 py-1.5 rounded-lg bg-white border border-gray-200 text-gray-700 text-xs font-semibold">Annuler</button>
                <button type="submit" :disabled="occupe" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold disabled:opacity-50">Confirmer</button>
              </div>
            </form>
          </section>

          <!-- Mensuration actuelle -->
          <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
              <h3 class="text-sm font-semibold text-gray-900">Tailles actuelles</h3>
              <p class="text-xs text-gray-500">{{ actuelle ? 'mesurées le ' + dateCourte(actuelle.date_mesure) : 'jamais mesuré' }}</p>
            </div>
            <div class="grid grid-cols-3 gap-2">
              <div v-for="m in MESURES" :key="m.cle" class="rounded-xl bg-gray-50 border border-gray-100 px-3 py-2.5 text-center">
                <p class="text-[11px] text-gray-500">{{ m.libelle }}</p>
                <p class="text-lg font-semibold tabular-nums" :class="actuelle?.[m.cle] ? 'text-gray-900' : 'text-gray-300'">{{ actuelle?.[m.cle]?.valeur ?? '—' }}</p>
              </div>
            </div>
            <p v-if="!actuelle" class="mt-3 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Sans mesures, les tailles du bon de dotation ne pourront pas être préremplies.</p>

            <template v-if="peutMesurer && fiche.statut !== 'radie'">
              <button v-if="!saisie" type="button" @click="ouvrirSaisie" class="mt-3 w-full sm:w-auto px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs font-semibold">Nouvelle prise de mesures</button>
              <form v-else @submit.prevent="enregistrerMesure" class="mt-3 p-3 rounded-xl bg-brand-50/50 border border-brand-100 space-y-3">
                <div class="grid grid-cols-3 gap-2">
                  <label v-for="m in MESURES" :key="m.champ">
                    <span class="block text-[11px] font-semibold text-gray-600 mb-1">{{ m.libelle }}</span>
                    <select v-model="saisie[m.champ]" class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm bg-white">
                      <option value="">—</option>
                      <option v-for="t in taillesPour(m.cle)" :key="t.id" :value="t.id">{{ t.valeur }}</option>
                    </select>
                  </label>
                </div>
                <label class="block">
                  <span class="block text-[11px] font-semibold text-gray-600 mb-1">Date de la mesure</span>
                  <input v-model="saisie.date_mesure" type="date" required :max="aujourdhui()" class="w-full sm:w-48 border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" />
                </label>
                <div class="flex justify-end gap-2">
                  <button type="button" @click="saisie = null" class="px-3 py-1.5 rounded-lg bg-white border border-gray-200 text-gray-700 text-xs font-semibold">Annuler</button>
                  <button type="submit" :disabled="occupe" class="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold disabled:opacity-50">Enregistrer les mesures</button>
                </div>
              </form>
            </template>
          </section>

          <!-- Historique -->
          <section class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <h3 class="px-4 sm:px-5 pt-4 pb-2 text-sm font-semibold text-gray-900">Historique des mesures <span class="font-normal text-gray-400">({{ historique.length }})</span></h3>
            <p v-if="!historique.length" class="px-5 pb-4 text-sm text-gray-400">Aucune mesure enregistrée.</p>
            <table v-else class="w-full text-sm">
              <thead>
                <tr class="text-[11px] text-gray-500 border-b border-gray-100">
                  <th class="text-left font-semibold px-4 sm:px-5 py-2">Date</th>
                  <th v-for="m in MESURES" :key="m.cle" class="text-center font-semibold px-1 py-2">{{ m.libelle }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(h, i) in historique" :key="h.id">
                  <td class="px-4 sm:px-5 py-2 whitespace-nowrap">{{ dateCourte(h.date_mesure) }}<span class="block text-[11px] text-gray-400">{{ h.saisie_par?.name }}</span></td>
                  <td v-for="m in MESURES" :key="m.cle" class="text-center tabular-nums px-1 py-2" :class="change(i, m.cle) ? 'font-semibold text-brand-700' : 'text-gray-700'">{{ h[m.cle]?.valeur ?? '—' }}</td>
                </tr>
              </tbody>
            </table>
          </section>

          <!-- Paquetage : articles en service et échéances -->
          <section class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <h3 class="px-4 sm:px-5 pt-4 pb-2 text-sm font-semibold text-gray-900">Paquetage <span class="font-normal text-gray-400">({{ paquetage.length }} article(s) en service)</span></h3>
            <p v-if="!paquetage.length" class="px-5 pb-4 text-sm text-gray-400">Aucun article en service.</p>
            <ul v-else class="divide-y divide-gray-100">
              <li v-for="l in paquetage" :key="l.id" class="px-4 sm:px-5 py-2.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="flex-1 min-w-[10rem] text-sm text-gray-900">{{ l.article?.designation }}<span v-if="l.taille" class="text-gray-500"> · {{ l.taille.valeur }}</span><span class="text-gray-500"> × {{ l.quantite }}</span></span>
                <span :class="ECHEANCES[l.echeance]?.classe" class="text-[11px] px-2 py-0.5 rounded-full border whitespace-nowrap">{{ l.date_echeance ? dateCourte(l.date_echeance) : ECHEANCES.sans.libelle }}</span>
              </li>
            </ul>
          </section>
        </template>
      </div>
    </section>
  </div>
</template>
