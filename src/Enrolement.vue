<script setup>
import { journalErreur } from '@/services/journal'
import { ref, computed } from 'vue'
import api, { messageErreur } from '@/services/api'
import { MDP_MIN, niveauMotDePasse } from '@/services/securite'

const emit = defineEmits(['enrolement-success', 'retour-login'])

const matricule = ref('')
const dateIncorporation = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const passwordVisible = ref(false)

const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

// Jauge à 4 segments : la longueur compte plus que les symboles (NIST SP 800-63B-4).
// Le serveur applique la vraie règle (longueur minimale, mots courants, nom et email interdits).
const passwordStrength = computed(() => niveauMotDePasse(password.value))

const strengthText = computed(() => {
  const s = passwordStrength.value
  if (s <= 1) return `trop court (${MDP_MIN} caractères minimum)`
  if (s === 2) return 'acceptable'
  if (s === 3) return 'bonne'
  return 'excellente'
})

const handleEnrolement = async () => {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const response = await api.post('/register', {
      matricule: matricule.value,
      date_incorporation: dateIncorporation.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    const token = response.data.token
    const user = response.data.user

    if (token) {
      localStorage.setItem('auth_token', token)
      localStorage.setItem('user_info', JSON.stringify(user))
      api.defaults.headers.common['Authorization'] = `Bearer ${token}`
      successMessage.value = 'Compte créé avec succès ! Connexion en cours...'
      setTimeout(() => emit('enrolement-success', user), 800)
    }
  } catch (error) {
    journalErreur("Erreur d'enrôlement :", error)
    if (error.response && error.response.status === 422) {
      const erreurs = error.response.data.errors
      const premiereErreur = erreurs ? Object.values(erreurs)[0]?.[0] : null
      errorMessage.value = premiereErreur || error.response.data.message || "Données invalides."
    } else if (error.response && error.response.status === 429) {
      errorMessage.value = messageErreur(error)
    } else {
      errorMessage.value = "Impossible de joindre le serveur. Réessayez plus tard."
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-900 flex flex-col justify-center py-8 px-4 sm:px-6 lg:px-8 font-sans antialiased">
    <!-- Conteneur principal en 2 colonnes (Modèle ENI) -->
    <div class="sm:mx-auto sm:w-full sm:max-w-5xl">
      <div class="bg-white shadow-2xl rounded-3xl overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[600px] border border-slate-800">
        
        <!-- COLONNE GAUCHE : Formulaire d'enrôlement (7 colonnes) -->
        <div class="lg:col-span-7 p-8 sm:p-10 flex flex-col justify-center">
          
          <!-- En-tête du formulaire -->
          <div class="mb-6">
            <h2 class="text-2xl font-black text-slate-900 tracking-tight">Enrôlement Militaire</h2>
            <p class="text-xs text-slate-500 mt-1">
              Réservé aux effectifs déjà enregistrés dans le référentiel
            </p>

            <!-- Badge Sécurisé -->
            <div class="inline-flex items-center space-x-1.5 mt-3 px-3 py-1 rounded-full border border-brand-500/40 bg-brand-50 text-brand-700 text-[10px] font-bold tracking-wider uppercase">
              <span>🔒</span>
              <span>Accès sécurisé · Données chiffrées</span>
            </div>
          </div>

          <!-- Messages d'erreur / succès -->
          <div v-if="errorMessage" class="mb-4 p-3 bg-red-50 border-l-4 border-red-500 text-red-700 text-xs rounded-lg">
            {{ errorMessage }}
          </div>
          <div v-if="successMessage" class="mb-4 p-3 bg-brand-50 border-l-4 border-brand-500 text-brand-700 text-xs rounded-lg">
            {{ successMessage }}
          </div>

          <!-- Formulaire -->
          <form @submit.prevent="handleEnrolement" class="space-y-3.5">
            
            <!-- Matricule -->
            <div>
              <label for="matricule" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1">Matricule</label>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">🪖</span>
                <input
                  id="matricule"
                  v-model="matricule"
                  type="text"
                  required
                  placeholder="Ex: MAT-00000"
                  class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
              </div>
            </div>

            <!-- Date d'incorporation -->
            <div>
              <label for="date_incorporation" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1">
                Date d'incorporation
              </label>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">📅</span>
                <input
                  id="date_incorporation"
                  v-model="dateIncorporation"
                  type="date"
                  required
                  class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
              </div>
              <p class="text-[10px] text-blue-600 mt-0.5">
                Nécessaire pour l'authentification croisée avec les données d'incorporation.
              </p>
            </div>

            <!-- Adresse email -->
            <div>
              <label for="email" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1">Adresse email</label>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">✉</span>
                <input
                  id="email"
                  v-model="email"
                  type="email"
                  required
                  placeholder="votre.email@armees.mg"
                  class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
              </div>
            </div>

            <!-- Mot de passe -->
            <div>
              <label for="password" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1">Mot de passe</label>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">🔒</span>
                <input
                  id="password"
                  v-model="password"
                  :type="passwordVisible ? 'text' : 'password'"
                  required
                  minlength="15"
                  placeholder="15 caractères minimum (une phrase de passe convient)"
                  class="w-full pl-10 pr-11 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
                <button
                  type="button"
                  @click="passwordVisible = !passwordVisible"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition"
                >
                  <svg v-if="passwordVisible" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" /><circle cx="12" cy="12" r="3" /></svg>
                  <svg v-else xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" /><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" /></svg>
                </button>
              </div>
            </div>

            <!-- Confirmer le mot de passe -->
            <div>
              <label for="password_confirmation" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1">
                Confirmer le mot de passe
              </label>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">🔒</span>
                <input
                  id="password_confirmation"
                  v-model="passwordConfirmation"
                  :type="passwordVisible ? 'text' : 'password'"
                  required
                  placeholder="Répéter le mot de passe"
                  class="w-full pl-10 pr-10 py-2.5 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
                <span class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-brand-600 text-sm font-bold">✓</span>
              </div>
            </div>

            <!-- Barre de jauge de sécurité dynamique à 4 segments -->
            <div class="space-y-1 pt-0.5">
              <div class="grid grid-cols-4 gap-1.5 h-1.5">
                <div :class="passwordStrength >= 1 ? 'bg-brand-500' : 'bg-slate-200'" class="rounded-full transition-all"></div>
                <div :class="passwordStrength >= 2 ? 'bg-brand-500' : 'bg-slate-200'" class="rounded-full transition-all"></div>
                <div :class="passwordStrength >= 3 ? 'bg-brand-500' : 'bg-slate-200'" class="rounded-full transition-all"></div>
                <div :class="passwordStrength >= 4 ? 'bg-brand-500' : 'bg-slate-200'" class="rounded-full transition-all"></div>
              </div>
              <p class="text-[11px] text-brand-600 font-medium">
                Sécurité du mot de passe : <span class="font-bold">{{ strengthText }}</span>
              </p>
            </div>

            <!-- Bouton de soumission Orange -->
            <div class="pt-2">
              <button
                type="submit"
                :disabled="loading"
                class="w-full bg-[#2f5d3a] hover:bg-[#264c2f] active:bg-[#1f3d27] text-white font-bold py-3 rounded-xl text-sm transition shadow-lg shadow-brand-600/30 disabled:opacity-50 flex items-center justify-center space-x-2"
              >
                <svg v-if="loading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ loading ? 'Création en cours...' : 'Créer mon compte' }}</span>
              </button>
            </div>
          </form>

          <!-- Lien de retour Connexion -->
          <div class="mt-4 text-center">
            <button 
              @click="emit('retour-login')" 
              class="text-xs text-blue-600 hover:underline font-semibold transition"
            >
              Déjà enrôlé ? Se connecter
            </button>
          </div>

        </div>

        <!-- COLONNE DROITE : Bloc institutionnel bleu avec Logo (5 colonnes) -->
        <div class="lg:col-span-5 bg-gradient-to-br from-blue-700 via-blue-800 to-blue-950 text-white p-10 flex flex-col items-center justify-center text-center relative overflow-hidden">
          <!-- Motif de lueur décoratif -->
          <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.1),transparent)] pointer-events-none"></div>

          <div class="relative z-10 space-y-4 flex flex-col items-center">
            <!-- Logo circulaire institutionnel -->
            <div class="w-24 h-24 bg-amber-400 rounded-full p-1 border-4 border-white/20 shadow-2xl flex items-center justify-center overflow-hidden">
              <img src="@/assets/logo.jpeg" alt="Logo Forces Armées" class="w-full h-full object-cover rounded-full" />
            </div>

            <div class="space-y-1">
              <h3 class="text-xl font-black tracking-tight">Ministère des Forces Armées</h3>
              <p class="text-xs text-blue-200 font-medium uppercase tracking-wider">République de Madagascar</p>
            </div>

            <div class="w-12 h-1 bg-amber-500 rounded-full my-1"></div>

            <p class="text-xs text-blue-100 max-w-xs leading-relaxed">
              Système intégré de gestion des effectifs et des dotations militaires.
            </p>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>