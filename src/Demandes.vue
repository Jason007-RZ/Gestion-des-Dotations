<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api, { messageErreur, enListe, toutesLesPages } from '@/services/api'
import { journalErreur } from '@/services/journal'
import { notify } from '@/services/notify'
import { confirmer } from '@/services/confirm'
import { ouvrirPdf } from '@/services/pdf'
import { utiliserEnCours } from '@/services/enCours'
import { ROLES, estAdmin } from '@/services/roles'
import Pagination from './components/Pagination.vue'
import AppIcon from './components/AppIcon.vue'

// Demandes soumises au chef d'unité : perte d'un article (RG9, avec procès-verbal) et dérogation au barème (RG1).
//
//   militaire / magasinier / administrateur dépose
//     → chef d'unité de l'unité du militaire (ou administrateur) valide ou refuse
//       → magasinier (ou administrateur) enregistre : le mouvement n'est créé qu'à ce moment-là.
//
// Ce que chacun peut faire sur une demande (peut_decider, peut_executer, peut_annuler) est calculé par le serveur :
// l'interface ne fait que l'afficher, le backend reste l'autorité.
const props = defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['maj']) // prévient App.vue pour rafraîchir la pastille du menu

const role = computed(() => props.user?.role)
const estMilitaire = computed(() => role.value === ROLES.MILITAIRE)
const peutDeposer = computed(() => role.value !== ROLES.CHEF) // le chef d'unité valide, il ne dépose pas

// ----- Liste -----
const vue = ref('actives') // 'actives' | 'historique'
const filtreType = ref('')
const demandes = ref([])
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const loading = ref(true)
const errorMessage = ref('')
let sequence = 0 // ignore la réponse d'une requête dépassée par une plus récente

const charger = async () => {
  const numero = ++sequence
  loading.value = true
  errorMessage.value = ''
  try {
    const params = { vue: vue.value, page: page.value }
    if (filtreType.value) params.type = filtreType.value
    const { data } = await api.get('/demandes', { params })
    if (numero !== sequence) return
    demandes.value = enListe(data)
    pages.value = data.last_page || 1
    total.value = data.total ?? demandes.value.length
  } catch (error) {
    if (numero !== sequence) return
    journalErreur('Erreur chargement des demandes :', error)
    errorMessage.value = messageErreur(error, 'Impossible de charger les demandes.')
  } finally {
    if (numero === sequence) loading.value = false
  }
}

const apresChangement = async () => {
  await charger()
  emit('maj')
}

watch([vue, filtreType], () => {
  if (page.value !== 1) page.value = 1 // le changement de page recharge la liste
  else charger()
})
watch(page, charger)
onMounted(charger)

// ----- Libellés -----
const GRIS = 'bg-gray-100 text-gray-600 border-gray-200'
const TYPES = {
  perte: { libelle: 'Perte', classe: 'bg-red-50 text-red-700 border-red-100' },
  derogation: { libelle: 'Dérogation au barème', classe: 'bg-amber-50 text-amber-700 border-amber-100' }
}
const STATUTS = {
  en_attente: { libelle: 'En attente de validation', classe: 'bg-amber-50 text-amber-700 border-amber-100' },
  validee: { libelle: 'Validée · à enregistrer', classe: 'bg-sky-50 text-sky-700 border-sky-100' },
  executee: { libelle: 'Enregistrée', classe: 'bg-emerald-50 text-emerald-700 border-emerald-100' },
  refusee: { libelle: 'Refusée', classe: 'bg-red-50 text-red-700 border-red-100' },
  annulee: { libelle: 'Annulée', classe: GRIS }
}
const typeDe = (d) => TYPES[d.type] || { libelle: d.type, classe: GRIS }
const statutDe = (d) =>
  d.statut === 'validee' && d.expiree ? { libelle: 'Validée · expirée', classe: GRIS } : STATUTS[d.statut] || { libelle: d.statut, classe: GRIS }

const nomMilitaire = (d) => (d.militaire ? `${d.militaire.grade || ''} ${d.militaire.nom} ${d.militaire.prenom}`.trim() : '—')
const dateHeure = (v) => (v ? new Date(v).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—')
const dateSeule = (v) => (v ? new Date(v).toLocaleDateString('fr-FR') : '—')

const sousTitre = computed(() => {
  switch (role.value) {
    case ROLES.CHEF:
      return "Pertes et dérogations concernant les militaires de votre unité : validez ou refusez avant leur enregistrement par le magasin."
    case ROLES.MILITAIRE:
      return "Signalez la perte d'un article : votre chef d'unité la valide, puis le magasin l'enregistre et édite le procès-verbal."
    case ROLES.MAGASINIER:
      return "Déposez une demande de perte ou de dérogation. Une fois validée par le chef d'unité, vous l'enregistrez ici, dans votre magasin."
    default:
      return "Toutes les demandes. Vous pouvez valider à la place d'un chef d'unité et enregistrer dans n'importe quel magasin."
  }
})

const messageVide = computed(() => {
  if (vue.value === 'historique') return "Aucune demande dans l'historique."
  if (role.value === ROLES.CHEF) return 'Aucune demande à valider.'
  if (role.value === ROLES.MAGASINIER) return 'Aucune demande en cours ni à enregistrer.'
  return 'Aucune demande en cours.'
})

// ----- Nouvelle demande -----
const formOuvert = ref(false)
const referentielsCharges = ref(false)
const militaires = ref([])
const articles = ref([])
const rechercheMilitaire = ref('')
const erreurForm = ref('')
const formVide = () => ({ type: 'perte', militaire_id: '', article_id: '', declinaison_id: '', quantite: 1, motif: '' })
const form = ref(formVide())
const { enCours: envoi, executer: executerEnvoi } = utiliserEnCours()

const articleChoisi = computed(() => articles.value.find((a) => a.id === Number(form.value.article_id)))
const declinaisons = computed(() => articleChoisi.value?.declinaisons || [])
const militairesFiltres = computed(() => {
  const q = rechercheMilitaire.value.trim().toLowerCase()
  const liste = q ? militaires.value.filter((m) => `${m.matricule} ${m.nom} ${m.prenom}`.toLowerCase().includes(q)) : militaires.value
  return liste.slice(0, 200)
})
const choisirArticle = () => {
  form.value.declinaison_id = declinaisons.value.length === 1 ? declinaisons.value[0].id : ''
}

const chargerReferentiels = async () => {
  if (referentielsCharges.value) return
  try {
    articles.value = enListe((await api.get('/articles')).data)
    if (estMilitaire.value) {
      // Un militaire ne signale que ses propres pertes : sa fiche est la seule que le serveur lui montre.
      const id = props.user?.militaire?.id || enListe((await api.get('/militaires')).data)[0]?.id
      form.value.militaire_id = id || ''
      if (!id) erreurForm.value = "Aucune fiche militaire n'est rattachée à ce compte. Contactez l'administrateur."
    } else {
      militaires.value = await toutesLesPages('/militaires')
    }
    referentielsCharges.value = true
  } catch (error) {
    journalErreur('Erreur chargement des listes de la demande :', error)
    erreurForm.value = messageErreur(error, 'Impossible de charger les listes (militaires, articles).')
  }
}

const ouvrirFormulaire = async () => {
  formOuvert.value = true
  erreurForm.value = ''
  await chargerReferentiels()
}

const fermerFormulaire = () => {
  formOuvert.value = false
  erreurForm.value = ''
}

const typeEffectif = computed(() => (estMilitaire.value ? 'perte' : form.value.type))

const deposer = () =>
  executerEnvoi(async () => {
    erreurForm.value = ''
    try {
      await api.post('/demandes', {
        type: typeEffectif.value,
        militaire_id: Number(form.value.militaire_id),
        declinaison_id: Number(form.value.declinaison_id),
        quantite: Number(form.value.quantite),
        motif: form.value.motif.trim()
      })
      notify.succes(
        typeEffectif.value === 'perte'
          ? "Demande de perte envoyée. Elle attend la validation du chef d'unité."
          : "Demande de dérogation envoyée. Elle attend la validation du chef d'unité."
      )
      const militaire = form.value.militaire_id
      form.value = formVide()
      if (estMilitaire.value) form.value.militaire_id = militaire
      formOuvert.value = false
      // On revient sur « En cours » sans filtre, pour voir la demande qu'on vient de déposer.
      vue.value = 'actives'
      filtreType.value = ''
      page.value = 1
      await apresChangement()
    } catch (error) {
      erreurForm.value = messageErreur(error, 'Impossible de déposer la demande.')
    }
  })

// ----- Actions sur une demande -----
const { enCours: occupe, executer: executerAction } = utiliserEnCours()

const resumeDemande = (d) => `${d.quantite} × ${d.article || 'article'}${d.taille ? ' (' + d.taille + ')' : ''} — ${nomMilitaire(d)}`

const decider = async (d, decision, commentaire = '') => {
  await api.post(`/demandes/${d.id}/decision`, { decision, commentaire: commentaire || undefined })
}

const valider = async (d) => {
  const ok = await confirmer({
    titre: 'Valider cette demande ?',
    message:
      d.type === 'perte'
        ? `Vous confirmez la perte de ${resumeDemande(d)}. Le magasin pourra alors l'enregistrer et éditer le procès-verbal.`
        : `Vous autorisez ce militaire à dépasser son barème : ${resumeDemande(d)}. Le magasin pourra alors enregistrer la perception.`,
    bouton: 'Valider',
    danger: false
  })
  if (!ok) return
  await executerAction(async () => {
    try {
      await decider(d, 'valider')
      notify.succes("Demande validée. Le magasin peut maintenant l'enregistrer.")
      await apresChangement()
    } catch (error) {
      notify.erreur(messageErreur(error, 'Impossible de valider cette demande.'))
      await charger() // l'état a pu changer entre-temps (retirée, déjà traitée…)
    }
  })
}

const refusEn = ref(null)
const motifRefus = ref('')
const erreurRefus = ref('')
const ouvrirRefus = (d) => {
  refusEn.value = d
  motifRefus.value = ''
  erreurRefus.value = ''
}
const confirmerRefus = () =>
  executerAction(async () => {
    const d = refusEn.value
    if (!d) return
    try {
      await decider(d, 'refuser', motifRefus.value.trim())
      notify.succes('Demande refusée.')
      refusEn.value = null
      await apresChangement()
    } catch (error) {
      erreurRefus.value = messageErreur(error, 'Impossible de refuser cette demande.')
    }
  })

const retirer = async (d) => {
  const ok = await confirmer({
    titre: 'Retirer cette demande ?',
    message: `${resumeDemande(d)}. Elle ne sera ni examinée ni enregistrée ; vous pourrez en déposer une nouvelle.`,
    bouton: 'Oui, la retirer'
  })
  if (!ok) return
  await executerAction(async () => {
    try {
      await api.post(`/demandes/${d.id}/annuler`)
      notify.succes('Demande retirée.')
      await apresChangement()
    } catch (error) {
      notify.erreur(messageErreur(error, 'Impossible de retirer cette demande.'))
      await charger()
    }
  })
}

// ----- Enregistrement par le magasin -----
const enregistrementEn = ref(null)
const magasins = ref([])
const magasinId = ref('')
const erreurEnregistrement = ref('')

const chargerMagasins = async () => {
  if (magasins.value.length) return
  try {
    // L'administrateur voit tous les magasins ; le magasinier, ceux auxquels il est rattaché (RG8).
    magasins.value = estAdmin(role.value) ? enListe((await api.get('/magasins')).data) : (await api.get('/me')).data.magasins || []
    if (magasins.value.length === 1) {
      magasinId.value = magasins.value[0].id
    } else {
      try {
        const dernier = Number(localStorage.getItem('dernier_magasin'))
        if (magasins.value.some((m) => m.id === dernier)) magasinId.value = dernier
      } catch {
        /* stockage indisponible : pas de magasin présélectionné */
      }
    }
  } catch (error) {
    journalErreur('Erreur chargement des magasins :', error)
    erreurEnregistrement.value = messageErreur(error, 'Impossible de charger la liste des magasins.')
  }
}

const ouvrirEnregistrement = async (d) => {
  enregistrementEn.value = d
  erreurEnregistrement.value = ''
  await chargerMagasins()
}

const confirmerEnregistrement = () =>
  executerAction(async () => {
    const d = enregistrementEn.value
    if (!d) return
    erreurEnregistrement.value = ''
    try {
      const { data } = await api.post(`/demandes/${d.id}/executer`, { magasin_id: Number(magasinId.value) })
      try {
        localStorage.setItem('dernier_magasin', String(magasinId.value))
      } catch {
        /* ignoré */
      }
      const numero = data?.mouvement?.id
      notify.succes(
        d.type === 'perte'
          ? `Perte enregistrée${numero ? ' (mouvement n°' + numero + ')' : ''}. Le procès-verbal est disponible dans l'historique.`
          : `Perception enregistrée avec dérogation${numero ? ' (mouvement n°' + numero + ')' : ''}.`
      )
      enregistrementEn.value = null
      await apresChangement()
    } catch (error) {
      erreurEnregistrement.value = messageErreur(error, "Impossible d'enregistrer cette demande.")
      charger() // la demande a pu expirer ou être retirée entre-temps
    }
  })

// ----- Procès-verbal -----
const telechargerPv = (d) => ouvrirPdf(`/mouvements/${d.mouvement_id}/pv-perte`, `pv-perte-${d.mouvement_id}.pdf`)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div class="min-w-0">
        <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Demandes</h1>
        <p class="text-sm text-gray-500 max-w-3xl">{{ sousTitre }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" @click="charger" class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-700 bg-brand-50 border border-brand-100 px-3.5 py-2 rounded-xl hover:bg-brand-100 transition">
          <AppIcon nom="actualiser" :taille="16" /> Actualiser
        </button>
        <button
          v-if="peutDeposer"
          type="button"
          @click="formOuvert ? fermerFormulaire() : ouvrirFormulaire()"
          :aria-expanded="formOuvert"
          class="inline-flex items-center gap-1.5 text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 px-3.5 py-2 rounded-xl transition shadow-sm shadow-brand-600/20"
        >
          <AppIcon nom="plus" :taille="16" /> {{ estMilitaire ? 'Signaler une perte' : 'Nouvelle demande' }}
        </button>
      </div>
    </div>

    <!-- Dépôt d'une demande -->
    <section v-if="peutDeposer && formOuvert" class="bg-white rounded-2xl shadow-sm border border-gray-100 mb-6 overflow-hidden" aria-label="Nouvelle demande">
      <div class="px-4 sm:px-6 pt-5 pb-4 border-b border-gray-100">
        <h2 class="text-base font-bold text-gray-900">{{ estMilitaire ? 'Signaler la perte ou la détérioration d\'un article' : 'Nouvelle demande' }}</h2>
        <div v-if="!estMilitaire" class="mt-3 grid grid-cols-2 gap-1.5 p-1 bg-gray-100 rounded-xl sm:max-w-md" role="tablist" aria-label="Type de demande">
          <button
            v-for="(t, k) in TYPES"
            :key="k"
            type="button"
            role="tab"
            :aria-selected="form.type === k"
            @click="form.type = k"
            :class="form.type === k ? 'bg-white text-brand-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
            class="px-2 py-2 rounded-lg text-sm font-semibold transition"
          >
            {{ k === 'perte' ? 'Perte ou détérioration' : 'Dérogation' }}
          </button>
        </div>
        <p class="mt-3 text-xs text-gray-500">
          <template v-if="typeEffectif === 'perte'">
            Le chef d'unité du militaire doit valider la perte. Une fois validée, le magasin l'enregistre et le procès-verbal est édité. Vous ne pouvez déclarer que ce que le militaire détient.
          </template>
          <template v-else>
            À utiliser quand la quantité à servir dépasse le barème du grade du militaire. Le chef d'unité doit l'autoriser ; l'autorisation n'est valable que pendant une durée limitée.
          </template>
        </p>
      </div>

      <form @submit.prevent="deposer" class="p-4 sm:p-6 grid grid-cols-1 lg:grid-cols-3 gap-x-6 gap-y-5">
        <fieldset v-if="!estMilitaire" class="space-y-2 min-w-0">
          <legend class="text-xs font-semibold text-brand-700 mb-1">Militaire</legend>
          <input name="recherche_militaire" v-model="rechercheMilitaire" type="search" aria-label="Rechercher un militaire" placeholder="Rechercher : nom ou matricule" class="w-full px-3.5 py-2.5 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 focus:outline-none" />
          <select name="demande_militaire_id" v-model="form.militaire_id" required aria-label="Militaire concerné" class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
            <option value="" disabled>{{ militairesFiltres.length }} résultat(s) : choisir</option>
            <option v-for="m in militairesFiltres" :key="m.id" :value="m.id">{{ m.matricule }} · {{ m.grade }} {{ m.nom }} {{ m.prenom }}</option>
          </select>
        </fieldset>

        <fieldset class="space-y-2 min-w-0">
          <legend class="text-xs font-semibold text-brand-700 mb-1">Article</legend>
          <select name="demande_article_id" v-model="form.article_id" @change="choisirArticle" required aria-label="Article" class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
            <option value="" disabled>Choisir un article</option>
            <option v-for="a in articles" :key="a.id" :value="a.id">{{ a.designation }}</option>
          </select>
          <select name="demande_declinaison_id" v-model="form.declinaison_id" required :disabled="!declinaisons.length" aria-label="Taille" class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none disabled:bg-gray-50 disabled:text-gray-400">
            <option value="" disabled>{{ declinaisons.length ? 'Choisir la taille' : 'Taille (après l’article)' }}</option>
            <option v-for="d in declinaisons" :key="d.id" :value="d.id">{{ d.taille }}</option>
          </select>
        </fieldset>

        <fieldset class="space-y-2 min-w-0">
          <legend class="text-xs font-semibold text-brand-700 mb-1">Quantité</legend>
          <div class="flex items-stretch gap-2">
            <button type="button" aria-label="Diminuer la quantité" @click="form.quantite = Math.max(1, Number(form.quantite) - 1)" class="w-11 rounded-xl border border-gray-200 bg-white text-lg font-semibold text-gray-700 hover:bg-gray-50">−</button>
            <input name="demande_quantite" type="number" inputmode="numeric" min="1" max="1000" v-model="form.quantite" required aria-label="Quantité" class="flex-1 min-w-0 text-center px-3 py-2.5 bg-gray-50/70 border border-gray-200 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-brand-500/20 focus:outline-none" />
            <button type="button" aria-label="Augmenter la quantité" @click="form.quantite = Number(form.quantite) + 1" class="w-11 rounded-xl border border-gray-200 bg-white text-lg font-semibold text-gray-700 hover:bg-gray-50">+</button>
          </div>
        </fieldset>

        <div class="lg:col-span-3">
          <label for="demande-motif" class="block text-xs font-semibold text-gray-600 mb-1.5">
            {{ typeEffectif === 'perte' ? 'Circonstances de la perte (obligatoire, 5 caractères minimum)' : 'Raison de la dérogation (obligatoire, 5 caractères minimum)' }}
          </label>
          <input
            id="demande-motif"
            name="demande_motif"
            v-model="form.motif"
            type="text"
            minlength="5"
            maxlength="500"
            required
            :placeholder="typeEffectif === 'perte' ? 'Ex : perdu en exercice le 12/10, vol déclaré…' : 'Ex : mission prolongée, remplacement avant échéance…'"
            class="w-full px-3.5 py-2.5 bg-gray-50/70 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none"
          />
        </div>

        <p v-if="erreurForm" class="lg:col-span-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm" role="alert">{{ erreurForm }}</p>

        <div class="lg:col-span-3 flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-1">
          <button type="button" @click="fermerFormulaire" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-3 rounded-xl text-sm font-semibold">Fermer</button>
          <button type="submit" :disabled="envoi" class="sm:min-w-48 bg-brand-600 hover:bg-brand-700 disabled:opacity-60 text-white font-semibold py-3 px-6 rounded-xl transition shadow-sm shadow-brand-600/20 text-sm">
            {{ envoi ? 'Envoi…' : 'Envoyer la demande' }}
          </button>
        </div>
      </form>
    </section>

    <!-- Filtres -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div class="inline-grid grid-cols-2 gap-1.5 p-1 bg-gray-100 rounded-xl" role="tablist" aria-label="Affichage des demandes">
        <button type="button" role="tab" :aria-selected="vue === 'actives'" @click="vue = 'actives'" :class="vue === 'actives' ? 'bg-white text-brand-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">En cours</button>
        <button type="button" role="tab" :aria-selected="vue === 'historique'" @click="vue = 'historique'" :class="vue === 'historique' ? 'bg-white text-brand-700 shadow-sm' : 'text-gray-600 hover:text-gray-900'" class="px-4 py-2 rounded-lg text-sm font-semibold transition">Historique</button>
      </div>
      <select name="filtre_type" v-model="filtreType" aria-label="Filtrer par type" class="px-3 py-2 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none">
        <option value="">Tous les types</option>
        <option value="perte">Pertes</option>
        <option value="derogation">Dérogations</option>
      </select>
    </div>

    <div v-if="errorMessage" class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm" role="alert">{{ errorMessage }}</div>

    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
      <div v-if="loading && !demandes.length" class="p-8 text-center text-sm text-gray-500">Chargement…</div>
      <div v-else-if="!demandes.length" class="p-8 text-center text-sm text-gray-500">{{ messageVide }}</div>
      <ul v-else class="divide-y divide-gray-100" :class="loading ? 'opacity-60' : ''">
        <li v-for="d in demandes" :key="d.id" class="p-4 sm:p-5">
          <div class="flex flex-wrap items-center gap-2 mb-2">
            <span :class="typeDe(d).classe" class="border text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap">{{ typeDe(d).libelle }}</span>
            <span :class="statutDe(d).classe" class="border text-xs px-2.5 py-1 rounded-full font-medium whitespace-nowrap">{{ statutDe(d).libelle }}</span>
            <span class="text-xs text-gray-400">n°{{ d.id }} · déposée le {{ dateHeure(d.cree_le) }}<span v-if="d.demandeur"> par {{ d.demandeur }}</span></span>
          </div>

          <p class="text-sm font-semibold text-gray-900">
            {{ d.article || '—' }}
            <span v-if="d.taille" class="ml-1 bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded-full font-medium">{{ d.taille }}</span>
            <span class="text-gray-500 font-normal"> × {{ d.quantite }}</span>
          </p>
          <p class="text-sm text-gray-600">
            {{ nomMilitaire(d) }}<span v-if="d.militaire?.matricule" class="text-gray-400"> · {{ d.militaire.matricule }}</span>
          </p>
          <p class="text-sm text-gray-700 mt-1.5 break-words"><span class="text-gray-400">Motif :</span> {{ d.motif }}</p>

          <p v-if="d.contexte" class="mt-2 text-xs text-amber-800 bg-amber-50 border border-amber-100 rounded-lg px-3 py-2">
            Barème du grade : quantité due {{ d.contexte.du }}, déjà détenue {{ d.contexte.detenu }}. La quantité demandée le dépasse.
          </p>
          <p v-if="d.decideur" class="mt-2 text-xs text-gray-500 break-words">
            {{ d.statut === 'refusee' ? 'Refusée' : 'Validée' }} par {{ d.decideur }} le {{ dateHeure(d.decide_le) }}<span v-if="d.decision_motif"> — « {{ d.decision_motif }} »</span>
          </p>
          <p v-if="d.statut === 'validee' && d.expire_le" class="mt-1 text-xs" :class="d.expiree ? 'text-red-600 font-medium' : 'text-gray-500'">
            {{ d.expiree ? 'Autorisation expirée le' : "Autorisation valable jusqu'au" }} {{ dateSeule(d.expire_le) }}{{ d.expiree ? ' : déposez une nouvelle demande.' : '.' }}
          </p>

          <div v-if="d.peut_decider || d.peut_executer || d.peut_annuler || (d.statut === 'executee' && d.type === 'perte' && d.mouvement_id)" class="mt-3 flex flex-wrap gap-2">
            <button v-if="d.peut_decider" type="button" :disabled="occupe" @click="valider(d)" class="bg-brand-600 hover:bg-brand-700 disabled:opacity-60 text-white px-4 py-2 rounded-xl text-xs font-semibold">Valider</button>
            <button v-if="d.peut_decider" type="button" :disabled="occupe" @click="ouvrirRefus(d)" class="bg-white border border-red-200 text-red-700 hover:bg-red-50 disabled:opacity-60 px-4 py-2 rounded-xl text-xs font-semibold">Refuser</button>
            <button v-if="d.peut_executer" type="button" :disabled="occupe" @click="ouvrirEnregistrement(d)" class="bg-brand-600 hover:bg-brand-700 disabled:opacity-60 text-white px-4 py-2 rounded-xl text-xs font-semibold">
              {{ d.type === 'perte' ? 'Enregistrer la perte' : 'Enregistrer la perception' }}
            </button>
            <button v-if="d.statut === 'executee' && d.type === 'perte' && d.mouvement_id" type="button" @click="telechargerPv(d)" class="bg-white border border-gray-200 text-brand-700 hover:bg-brand-50 px-4 py-2 rounded-xl text-xs font-semibold">PV de perte</button>
            <button v-if="d.peut_annuler" type="button" :disabled="occupe" @click="retirer(d)" class="bg-gray-100 hover:bg-gray-200 disabled:opacity-60 text-gray-700 px-4 py-2 rounded-xl text-xs font-semibold">Retirer la demande</button>
          </div>
        </li>
      </ul>
      <Pagination v-model:page="page" :pages="pages" :total="total" />
    </div>

    <!-- Refus : la raison est obligatoire -->
    <div v-if="refusEn" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="refus-titre">
        <h3 id="refus-titre" class="text-lg font-bold text-gray-900 mb-1">Refuser la demande n°{{ refusEn.id }}</h3>
        <p class="text-sm text-gray-500 mb-4">{{ resumeDemande(refusEn) }}</p>
        <form @submit.prevent="confirmerRefus" class="space-y-4">
          <div>
            <label for="refus-motif" class="block text-xs font-semibold text-gray-600 mb-1">Raison du refus (obligatoire, 5 caractères minimum)</label>
            <input id="refus-motif" name="refus_motif" v-model="motifRefus" type="text" minlength="5" maxlength="500" required class="w-full border border-gray-200 rounded-xl px-3.5 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-red-500/20" />
          </div>
          <p v-if="erreurRefus" class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm" role="alert">{{ erreurRefus }}</p>
          <div class="flex justify-end space-x-2">
            <button type="button" @click="refusEn = null" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Retour</button>
            <button type="submit" :disabled="occupe" class="bg-red-600 hover:bg-red-700 disabled:opacity-60 text-white px-5 py-2.5 rounded-xl text-xs font-semibold">Refuser la demande</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Enregistrement : le magasinier choisit le magasin concerné -->
    <div v-if="enregistrementEn" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 backdrop-blur-sm">
      <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="enreg-titre">
        <h3 id="enreg-titre" class="text-lg font-bold text-gray-900 mb-1">
          {{ enregistrementEn.type === 'perte' ? 'Enregistrer la perte' : 'Enregistrer la perception' }} (demande n°{{ enregistrementEn.id }})
        </h3>
        <p class="text-sm text-gray-500 mb-1">{{ resumeDemande(enregistrementEn) }}</p>
        <p class="text-xs text-gray-500 mb-4">
          {{ enregistrementEn.type === 'perte'
            ? "Le stock n'est pas modifié : l'article est déclaré perdu et sort de la détention du militaire. Le procès-verbal est édité."
            : 'Le stock du magasin choisi diminue de la quantité demandée, au-delà du barème, avec l\'autorisation du chef d\'unité.' }}
        </p>
        <form @submit.prevent="confirmerEnregistrement" class="space-y-4">
          <div>
            <label for="enreg-magasin" class="block text-xs font-semibold text-gray-600 mb-1">Magasin</label>
            <select id="enreg-magasin" name="enreg_magasin_id" v-model="magasinId" required class="w-full px-3.5 py-2.5 bg-white border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:outline-none">
              <option value="" disabled>Choisir un magasin</option>
              <option v-for="m in magasins" :key="m.id" :value="m.id">{{ m.nom }}</option>
            </select>
          </div>
          <p v-if="erreurEnregistrement" class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm" role="alert">{{ erreurEnregistrement }}</p>
          <div class="flex justify-end space-x-2">
            <button type="button" @click="enregistrementEn = null" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-xs font-semibold">Retour</button>
            <button type="submit" :disabled="occupe || !magasinId" class="bg-brand-600 hover:bg-brand-700 disabled:opacity-60 text-white px-5 py-2.5 rounded-xl text-xs font-semibold">Enregistrer</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
