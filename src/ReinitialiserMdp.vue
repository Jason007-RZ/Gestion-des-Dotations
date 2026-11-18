<script setup>
import { ref, computed } from 'vue'
import api, { messageErreur } from '@/services/api'
import { journalErreur } from '@/services/journal'
import { utiliserEnCours } from '@/services/enCours'
import { MDP_MIN, genererMotDePasse, niveauMotDePasse, LIBELLES_NIVEAU } from '@/services/securite'

// Écran ouvert par le lien reçu par e-mail (« Mot de passe oublié »).
// Le lien porte un jeton à usage unique, valable 60 minutes ; le serveur le vérifie et révoque ensuite
// toutes les sessions ouvertes du compte.
const props = defineProps({ lien: { type: Object, required: true } }) // { token, email }
const emit = defineEmits(['retour-login'])

const password = ref('')
const confirmation = ref('')
const visible = ref(false)
const erreur = ref('')
const reussi = ref(false)
const { enCours, executer } = utiliserEnCours()
const niveau = computed(() => niveauMotDePasse(password.value))

const proposer = () => {
  const m = genererMotDePasse(20)
  password.value = m
  confirmation.value = m
  visible.value = true
}

const soumettre = () =>
  executer(async () => {
    erreur.value = ''
    if (password.value !== confirmation.value) {
      erreur.value = 'Les deux saisies du mot de passe sont différentes.'
      return
    }
    try {
      await api.post('/reset-password', {
        token: props.lien.token,
        email: props.lien.email,
        password: password.value,
        password_confirmation: confirmation.value
      })
      password.value = ''
      confirmation.value = ''
      visible.value = false
      reussi.value = true
    } catch (e) {
      journalErreur('Erreur réinitialisation du mot de passe :', e)
      erreur.value = messageErreur(e, 'Impossible de réinitialiser le mot de passe.')
    }
  })

const champ =
  'w-full px-4 py-3 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:bg-white transition'
</script>

<template>
  <div class="min-h-screen bg-slate-900 flex flex-col justify-center py-8 px-4 sm:px-6 font-sans antialiased">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
      <div class="bg-white shadow-2xl rounded-3xl border border-slate-800 p-8 sm:p-10">
        <h2 class="text-2xl font-black text-slate-900 tracking-tight">Nouveau mot de passe</h2>

        <!-- Réussite -->
        <div v-if="reussi" class="mt-5 space-y-4">
          <div class="p-3 bg-brand-50 border-l-4 border-brand-500 text-brand-700 text-xs rounded-lg" role="status">
            Mot de passe modifié. Tous vos appareils ont été déconnectés : connectez-vous avec le nouveau mot de passe.
          </div>
          <button
            type="button"
            class="w-full bg-[#2f5d3a] hover:bg-[#264c2f] active:bg-[#1f3d27] text-white font-bold py-3.5 rounded-xl text-sm transition shadow-lg shadow-brand-600/30"
            @click="emit('retour-login')"
          >
            Se connecter
          </button>
        </div>

        <!-- Formulaire -->
        <form v-else class="mt-4 space-y-4" @submit.prevent="soumettre">
          <p class="text-xs text-slate-500">
            Choisissez un nouveau mot de passe pour ce compte. Une phrase de passe de {{ MDP_MIN }} caractères ou plus convient très bien.
          </p>

          <div v-if="erreur" class="p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-xs rounded-lg" role="alert">
            {{ erreur }}
            <button type="button" class="block mt-1 font-bold underline" @click="emit('retour-login')">
              Refaire une demande de lien
            </button>
          </div>

          <div>
            <label for="reinit-email" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1.5">Compte</label>
            <input id="reinit-email" name="username" type="email" autocomplete="username" :value="lien.email" readonly :class="[champ, 'text-slate-500']" />
          </div>

          <div>
            <label for="reinit-mdp" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1.5">Nouveau mot de passe</label>
            <input id="reinit-mdp" name="new_password" v-model="password" :type="visible ? 'text' : 'password'" required autocomplete="new-password" :class="champ" />
            <div class="mt-1.5 flex gap-1" aria-hidden="true">
              <span v-for="i in 4" :key="i" class="h-1.5 flex-1 rounded-full" :class="i <= niveau ? (niveau <= 1 ? 'bg-red-400' : niveau === 2 ? 'bg-amber-400' : 'bg-brand-500') : 'bg-slate-200'"></span>
            </div>
            <p class="text-[11px] text-slate-500 mt-1" aria-live="polite">{{ LIBELLES_NIVEAU[niveau] }}</p>
          </div>

          <div>
            <label for="reinit-confirmation" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1.5">Confirmer le mot de passe</label>
            <input id="reinit-confirmation" name="new_password_confirmation" v-model="confirmation" :type="visible ? 'text' : 'password'" required autocomplete="new-password" :class="champ" />
          </div>

          <div class="flex items-center justify-between text-xs">
            <label for="reinit-afficher" class="inline-flex items-center gap-2 text-slate-600">
              <input id="reinit-afficher" name="afficher_mdp" v-model="visible" type="checkbox" class="rounded border-slate-300" /> Afficher
            </label>
            <button type="button" class="font-bold text-blue-600 hover:underline" @click="proposer">Proposer un mot de passe fort</button>
          </div>

          <button
            type="submit"
            :disabled="enCours"
            class="w-full bg-[#2f5d3a] hover:bg-[#264c2f] active:bg-[#1f3d27] text-white font-bold py-3.5 rounded-xl text-sm transition shadow-lg shadow-brand-600/30 disabled:opacity-50"
          >
            {{ enCours ? 'Enregistrement...' : 'Enregistrer le nouveau mot de passe' }}
          </button>

          <div class="text-center">
            <button type="button" class="text-xs text-blue-600 hover:underline font-bold" @click="emit('retour-login')">
              ← Retour à la page de connexion
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
