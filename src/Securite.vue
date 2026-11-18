<script setup>
import { ref, computed, onMounted } from 'vue'
import api, { messageErreur } from '@/services/api'
import { notify } from '@/services/notify'
import { confirmer } from '@/services/confirm'
import { utiliserEnCours } from '@/services/enCours'
import { MDP_MIN, genererMotDePasse, niveauMotDePasse, LIBELLES_NIVEAU, grouper, qrCodeEnImage, telechargerTexte } from '@/services/securite'
import AppIcon from './components/AppIcon.vue'

// Sécurité du compte : mot de passe, double authentification (TOTP) et appareils connectés.
// Le serveur applique toutes les règles ; cet écran ne fait que les rendre faciles à suivre.
const props = defineProps({ user: { type: Object, default: null } })
const emit = defineEmits(['deux-facteurs'])

// ----- Mot de passe -----
const mdp = ref({ current_password: '', password: '', password_confirmation: '' })
const mdpVisible = ref(false)
const erreurMdp = ref('')
const { enCours: enCoursMdp, executer: executerMdp } = utiliserEnCours()
const niveau = computed(() => niveauMotDePasse(mdp.value.password))

const generer = () => {
  const m = genererMotDePasse(20)
  mdp.value.password = m
  mdp.value.password_confirmation = m
  mdpVisible.value = true
  notify.info('Mot de passe généré : enregistrez-le dans votre gestionnaire de mots de passe avant de valider.')
}

const changerMdp = () =>
  executerMdp(async () => {
    erreurMdp.value = ''
    if (mdp.value.password !== mdp.value.password_confirmation) {
      erreurMdp.value = 'Les deux saisies du nouveau mot de passe sont différentes.'
      return
    }
    try {
      const { data } = await api.put('/me/password', mdp.value)
      mdp.value = { current_password: '', password: '', password_confirmation: '' }
      mdpVisible.value = false
      notify.succes(data?.message || 'Mot de passe modifié.')
    } catch (error) {
      erreurMdp.value = messageErreur(error, 'Impossible de changer le mot de passe.')
    }
  })

// ----- Double authentification -----
const actif = ref(null) // null = chargement
const etape = ref('repos') // repos | mot-de-passe | scan | codes | desactiver | regenerer
const erreur2fa = ref('')
const motDePasse2fa = ref('')
const code = ref('')
const codeSecours = ref('')
const utiliserSecours = ref(false)
const secret = ref('')
const qr = ref('')
const codes = ref([])
const codesConserves = ref(false)
const { enCours: enCours2fa, executer: executer2fa } = utiliserEnCours()

const charger = async () => {
  try {
    const { data } = await api.get('/me')
    actif.value = !!data.deux_facteurs
  } catch {
    actif.value = false
  }
}

const reinitialiserFormulaire = () => {
  erreur2fa.value = ''
  motDePasse2fa.value = ''
  code.value = ''
  codeSecours.value = ''
  utiliserSecours.value = false
}

const annuler = () => {
  etape.value = 'repos'
  secret.value = ''
  qr.value = ''
  reinitialiserFormulaire()
}

const commencer = () => {
  reinitialiserFormulaire()
  etape.value = 'mot-de-passe'
}

const preparer = () =>
  executer2fa(async () => {
    erreur2fa.value = ''
    try {
      const { data } = await api.post('/2fa/preparer', { password: motDePasse2fa.value })
      secret.value = data.secret
      qr.value = await qrCodeEnImage(data.otpauth)
      motDePasse2fa.value = ''
      etape.value = 'scan'
    } catch (error) {
      erreur2fa.value = messageErreur(error, "Impossible de démarrer l'activation.")
    }
  })

const confirmerActivation = () =>
  executer2fa(async () => {
    erreur2fa.value = ''
    try {
      const { data } = await api.post('/2fa/confirmer', { code: code.value })
      codes.value = data.codes_secours || []
      codesConserves.value = false
      actif.value = true
      emit('deux-facteurs', true)
      secret.value = ''
      qr.value = ''
      code.value = ''
      etape.value = 'codes'
    } catch (error) {
      erreur2fa.value = messageErreur(error, 'Code incorrect.')
    }
  })

const ouvrirAction = (nom) => {
  reinitialiserFormulaire()
  etape.value = nom
}

const secondFacteur = () => (utiliserSecours.value ? { code_secours: codeSecours.value } : { code: code.value })

const desactiver = () =>
  executer2fa(async () => {
    erreur2fa.value = ''
    try {
      await api.post('/2fa/desactiver', { password: motDePasse2fa.value, ...secondFacteur() })
      actif.value = false
      emit('deux-facteurs', false)
      notify.succes('Double authentification désactivée.')
      annuler()
    } catch (error) {
      erreur2fa.value = messageErreur(error, 'Impossible de désactiver.')
    }
  })

const regenerer = () =>
  executer2fa(async () => {
    erreur2fa.value = ''
    try {
      const { data } = await api.post('/2fa/codes-secours', { password: motDePasse2fa.value, ...secondFacteur() })
      codes.value = data.codes_secours || []
      codesConserves.value = false
      reinitialiserFormulaire()
      etape.value = 'codes'
    } catch (error) {
      erreur2fa.value = messageErreur(error, 'Impossible de régénérer les codes.')
    }
  })

const copierCodes = async () => {
  try {
    await navigator.clipboard.writeText(codes.value.join('\n'))
    notify.succes('Codes copiés.')
  } catch {
    notify.erreur('Copie impossible : utilisez le téléchargement.')
  }
}
const telecharger = () =>
  telechargerTexte('codes-de-secours-dotations.txt', `Codes de secours - Gestion des dotations\nCompte : ${props.user?.email || ''}\nChaque code ne sert qu'une fois.\n\n${codes.value.join('\n')}\n`)

const terminerCodes = () => {
  codes.value = []
  etape.value = 'repos'
}

// ----- Appareils connectés -----
const { enCours: enCoursSessions, executer: executerSessions } = utiliserEnCours()
const deconnecterAutres = async () => {
  if (!(await confirmer({ titre: 'Déconnecter les autres appareils', message: 'Tous vos autres appareils devront se reconnecter. Cet appareil reste connecté.', bouton: 'Déconnecter' }))) return
  executerSessions(async () => {
    try {
      const { data } = await api.post('/me/sessions/revoquer')
      notify.succes(data?.message || 'Autres appareils déconnectés.')
    } catch (error) {
      notify.erreur(messageErreur(error, 'Opération impossible.'))
    }
  })
}

onMounted(charger)

const champ = 'w-full px-3.5 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none bg-white'
const bouton = 'bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white px-4 py-2.5 rounded-xl text-sm font-semibold shadow-sm transition'
const boutonSecondaire = 'bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-sm font-semibold transition'
</script>

<template>
  <div class="max-w-3xl">
    <div class="mb-6">
      <h1 class="text-xl sm:text-2xl font-semibold text-gray-900 tracking-tight">Sécurité du compte</h1>
      <p class="text-sm text-gray-500">{{ user?.name || user?.email }} · protégez l'accès à vos données.</p>
    </div>

    <!-- Mot de passe -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6 mb-5" aria-labelledby="titre-mdp">
      <h2 id="titre-mdp" class="text-base font-semibold text-gray-900 mb-1">Mot de passe</h2>
      <p class="text-xs text-gray-500 mb-4">
        {{ MDP_MIN }} caractères minimum. Une phrase de passe (plusieurs mots) est plus sûre et plus facile à retenir que des symboles.
        Évitez votre nom, votre email et les mots courants.
      </p>
      <div v-if="erreurMdp" class="mb-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs" role="alert">{{ erreurMdp }}</div>
      <form @submit.prevent="changerMdp" class="space-y-3">
        <div>
          <label for="securite-actuel" class="block text-xs font-semibold text-gray-500 mb-1">Mot de passe actuel</label>
          <input id="securite-actuel" name="current_password" v-model="mdp.current_password" type="password" required autocomplete="current-password" :class="champ" />
        </div>
        <div>
          <div class="flex items-center justify-between mb-1">
            <label for="securite-nouveau" class="block text-xs font-semibold text-gray-500">Nouveau mot de passe</label>
            <button type="button" @click="generer" class="text-xs font-semibold text-brand-700 hover:underline">Générer un mot de passe</button>
          </div>
          <input id="securite-nouveau" name="new_password" v-model="mdp.password" :type="mdpVisible ? 'text' : 'password'" required :minlength="MDP_MIN" autocomplete="new-password" :class="champ" />
          <div class="flex gap-1 mt-2" aria-hidden="true">
            <span v-for="i in 4" :key="i" class="h-1.5 flex-1 rounded-full" :class="i <= niveau ? (niveau <= 1 ? 'bg-red-400' : niveau === 2 ? 'bg-amber-400' : 'bg-brand-500') : 'bg-gray-200'"></span>
          </div>
          <p class="text-[11px] text-gray-500 mt-1" aria-live="polite">{{ LIBELLES_NIVEAU[niveau] }}</p>
        </div>
        <div>
          <label for="securite-confirmation" class="block text-xs font-semibold text-gray-500 mb-1">Confirmer le nouveau mot de passe</label>
          <input id="securite-confirmation" name="new_password_confirmation" v-model="mdp.password_confirmation" :type="mdpVisible ? 'text' : 'password'" required autocomplete="new-password" :class="champ" />
        </div>
        <div class="flex items-center justify-between pt-1">
          <label class="flex items-center gap-2 text-xs text-gray-500">
            <input name="afficher_mdp" v-model="mdpVisible" type="checkbox" class="rounded border-gray-300" /> Afficher
          </label>
          <button type="submit" :disabled="enCoursMdp" :class="bouton">Changer le mot de passe</button>
        </div>
      </form>
    </section>

    <!-- Double authentification -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6 mb-5" aria-labelledby="titre-2fa">
      <div class="flex items-start justify-between gap-3 mb-1">
        <h2 id="titre-2fa" class="text-base font-semibold text-gray-900">Double authentification</h2>
        <span v-if="actif !== null" class="text-[11px] font-semibold px-2.5 py-0.5 rounded-full shrink-0" :class="actif ? 'bg-brand-50 text-brand-700' : 'bg-amber-50 text-amber-700'">{{ actif ? 'Activée' : 'Désactivée' }}</span>
      </div>
      <p class="text-xs text-gray-500 mb-4">
        En plus du mot de passe, un code à 6 chiffres (qui change toutes les 30 secondes) est demandé à chaque connexion. Même si votre mot de passe est volé, le compte reste protégé.
        Recommandé pour tous les administrateurs et magasiniers.
      </p>

      <div v-if="erreur2fa" class="mb-3 p-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-xs" role="alert">{{ erreur2fa }}</div>

      <!-- Repos -->
      <div v-if="etape === 'repos'" class="flex flex-wrap gap-2">
        <button v-if="actif === false" @click="commencer" :class="bouton">Activer la double authentification</button>
        <template v-if="actif">
          <button @click="ouvrirAction('regenerer')" :class="boutonSecondaire">Nouveaux codes de secours</button>
          <button @click="ouvrirAction('desactiver')" class="bg-red-50 hover:bg-red-100 text-red-700 px-4 py-2.5 rounded-xl text-sm font-semibold transition">Désactiver</button>
        </template>
      </div>

      <!-- Activation 1 : mot de passe -->
      <form v-else-if="etape === 'mot-de-passe'" @submit.prevent="preparer" class="space-y-3">
        <p class="text-sm text-gray-700">Pour commencer, confirmez votre mot de passe.</p>
        <div>
          <label for="securite-2fa-mdp" class="block text-xs font-semibold text-gray-500 mb-1">Mot de passe</label>
          <input id="securite-2fa-mdp" name="password" v-model="motDePasse2fa" type="password" required autocomplete="current-password" :class="champ" />
        </div>
        <div class="flex gap-2">
          <button type="submit" :disabled="enCours2fa" :class="bouton">Continuer</button>
          <button type="button" @click="annuler" :class="boutonSecondaire">Annuler</button>
        </div>
      </form>

      <!-- Activation 2 : scanner et confirmer -->
      <form v-else-if="etape === 'scan'" @submit.prevent="confirmerActivation" class="space-y-4">
        <ol class="text-sm text-gray-700 space-y-1 list-decimal list-inside">
          <li>Installez une application d'authentification (Google Authenticator, Microsoft Authenticator, Aegis, FreeOTP…).</li>
          <li>Scannez ce QR code, ou saisissez la clé à la main.</li>
          <li>Entrez le code à 6 chiffres affiché pour confirmer.</li>
        </ol>
        <div class="flex flex-col sm:flex-row items-center gap-4">
          <img v-if="qr" :src="qr" alt="QR code à scanner avec votre application d'authentification" class="w-44 h-44 rounded-xl border border-gray-200 p-1 bg-white" />
          <div class="min-w-0 w-full">
            <p class="text-xs font-semibold text-gray-500 mb-1">Clé de configuration</p>
            <p class="font-mono text-sm bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 break-all select-all">{{ grouper(secret) }}</p>
            <p class="text-[11px] text-gray-400 mt-1">Type : basé sur l'heure (TOTP), 6 chiffres, 30 secondes.</p>
          </div>
        </div>
        <div>
          <label for="securite-2fa-code" class="block text-xs font-semibold text-gray-500 mb-1">Code à 6 chiffres</label>
          <input id="securite-2fa-code" name="one_time_code" v-model="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" required autocomplete="one-time-code" placeholder="123 456" :class="champ + ' font-mono tracking-widest'" />
        </div>
        <div class="flex gap-2">
          <button type="submit" :disabled="enCours2fa || code.replace(/\s/g, '').length !== 6" :class="bouton">Activer</button>
          <button type="button" @click="annuler" :class="boutonSecondaire">Annuler</button>
        </div>
      </form>

      <!-- Codes de secours -->
      <div v-else-if="etape === 'codes'" class="space-y-4">
        <div class="p-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl text-xs" role="alert">
          <strong>Conservez ces codes de secours en lieu sûr.</strong> Ils ne s'affichent qu'une seule fois. Si vous perdez votre téléphone,
          chacun d'eux permet une connexion (usage unique).
        </div>
        <ul class="grid grid-cols-2 gap-2 font-mono text-sm">
          <li v-for="c in codes" :key="c" class="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-center select-all">{{ c }}</li>
        </ul>
        <div class="flex flex-wrap gap-2">
          <button type="button" @click="copierCodes" :class="boutonSecondaire">Copier</button>
          <button type="button" @click="telecharger" :class="boutonSecondaire">Télécharger (.txt)</button>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input name="codes_conserves" v-model="codesConserves" type="checkbox" class="rounded border-gray-300" /> J'ai conservé ces codes en lieu sûr
        </label>
        <button type="button" @click="terminerCodes" :disabled="!codesConserves" :class="bouton">Terminer</button>
      </div>

      <!-- Désactiver / régénérer : mot de passe + code -->
      <form v-else @submit.prevent="etape === 'desactiver' ? desactiver() : regenerer()" class="space-y-3">
        <p class="text-sm text-gray-700">
          {{ etape === 'desactiver' ? 'Pour désactiver la double authentification' : 'Pour obtenir de nouveaux codes de secours (les anciens deviennent invalides)' }},
          confirmez avec votre mot de passe et un code.
        </p>
        <div>
          <label for="securite-2fa-mdp2" class="block text-xs font-semibold text-gray-500 mb-1">Mot de passe</label>
          <input id="securite-2fa-mdp2" name="password" v-model="motDePasse2fa" type="password" required autocomplete="current-password" :class="champ" />
        </div>
        <div>
          <label for="securite-2fa-code2" class="block text-xs font-semibold text-gray-500 mb-1">{{ utiliserSecours ? 'Code de secours' : 'Code à 6 chiffres' }}</label>
          <input v-if="!utiliserSecours" id="securite-2fa-code2" name="one_time_code" v-model="code" type="text" inputmode="numeric" maxlength="7" required autocomplete="one-time-code" :class="champ + ' font-mono tracking-widest'" />
          <input v-else id="securite-2fa-code2" name="recovery_code" v-model="codeSecours" type="text" maxlength="12" required autocomplete="off" placeholder="XXXXX-XXXXX" :class="champ + ' font-mono tracking-widest uppercase'" />
          <button type="button" @click="utiliserSecours = !utiliserSecours; code = ''; codeSecours = ''" class="text-xs text-brand-700 hover:underline mt-1">
            {{ utiliserSecours ? "Utiliser l'application d'authentification" : 'Utiliser un code de secours' }}
          </button>
        </div>
        <div class="flex gap-2">
          <button type="submit" :disabled="enCours2fa" :class="etape === 'desactiver' ? 'bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white px-4 py-2.5 rounded-xl text-sm font-semibold' : bouton">
            {{ etape === 'desactiver' ? 'Désactiver' : 'Générer de nouveaux codes' }}
          </button>
          <button type="button" @click="annuler" :class="boutonSecondaire">Annuler</button>
        </div>
      </form>
    </section>

    <!-- Appareils -->
    <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6" aria-labelledby="titre-appareils">
      <h2 id="titre-appareils" class="text-base font-semibold text-gray-900 mb-1">Appareils connectés</h2>
      <p class="text-xs text-gray-500 mb-4">
        La session se ferme automatiquement après 15 minutes d'inactivité. Si vous avez oublié de vous déconnecter sur un autre appareil, fermez toutes les autres sessions d'un clic.
      </p>
      <button @click="deconnecterAutres" :disabled="enCoursSessions" :class="boutonSecondaire">Déconnecter mes autres appareils</button>
    </section>
  </div>
</template>
