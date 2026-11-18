<script setup>
import { journalErreur } from '@/services/journal'
import { ref } from 'vue'
import api from '@/services/api'

// Événement pour retourner vers l'écran de connexion
const emit = defineEmits(['retour-login'])

const email = ref('')
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const handleForgotPassword = async () => {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    // Appel vers l'API Laravel de réinitialisation de mot de passe
    const response = await api.post('/forgot-password', {
      email: email.value
    })

    successMessage.value = response.data.message || 'Un lien de réinitialisation a été envoyé à votre adresse email.'
  } catch (error) {
    journalErreur("Erreur mot de passe oublié :", error)
    if (error.response && error.response.status === 422) {
      errorMessage.value = "Adresse email invalide ou introuvable dans le système."
    } else {
      errorMessage.value = error.response?.data?.message || "Impossible de joindre le serveur. Réessayez plus tard."
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-slate-900 flex flex-col justify-center py-8 px-4 sm:px-6 lg:px-8 font-sans antialiased">
    <!-- Conteneur principal en 2 colonnes (Modèle ENI / Enrôlement / Login) -->
    <div class="sm:mx-auto sm:w-full sm:max-w-5xl">
      <div class="bg-white shadow-2xl rounded-3xl overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[550px] border border-slate-800">
        
        <!-- COLONNE GAUCHE : Formulaire de Mot de passe oublié (7 colonnes) -->
        <div class="lg:col-span-7 p-8 sm:p-12 flex flex-col justify-center">
          
          <!-- En-tête -->
          <div class="mb-6">
            <h2 class="text-2xl font-black text-slate-900 tracking-tight">Mot de passe oublié</h2>
            <p class="text-xs text-slate-500 mt-1">
              Entrez votre adresse email pour recevoir les instructions de réinitialisation.
            </p>

            <!-- Badge Sécurisé -->
            <div class="inline-flex items-center space-x-1.5 mt-3 px-3.5 py-1 rounded-full border border-brand-500/40 bg-brand-50 text-brand-700 text-[10px] font-bold tracking-wider uppercase">
              <span>🛡️</span>
              <span>Récupération sécurisée</span>
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
          <form @submit.prevent="handleForgotPassword" class="space-y-4">
            
            <!-- Adresse Email -->
            <div>
              <label for="email" class="block text-[11px] font-black text-slate-900 uppercase tracking-wider mb-1.5">Adresse email</label>
              <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400 text-sm">✉</span>
                <input 
                  id="email"
                  v-model="email" 
                  type="email" 
                  name="email"
                  autocomplete="email"
                  required 
                  placeholder="magasinier@armees.mg"
                  class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 bg-slate-50/50 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:bg-white transition"
                />
              </div>
            </div>

            <!-- Bouton d'envoi Orange -->
            <div class="pt-2">
              <button 
                type="submit" 
                :disabled="loading"
                class="w-full bg-[#2f5d3a] hover:bg-[#264c2f] active:bg-[#1f3d27] text-white font-bold py-3.5 rounded-xl text-sm transition shadow-lg shadow-brand-600/30 disabled:opacity-50 flex items-center justify-center space-x-2"
              >
                <svg v-if="loading" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ loading ? 'Envoi en cours...' : 'Envoyer le lien de réinitialisation' }}</span>
              </button>
            </div>
          </form>

          <!-- Séparateur -->
          <div class="relative my-6 text-center">
            <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200"></div></div>
            <span class="relative px-3 bg-white text-xs text-slate-400 font-medium">ou</span>
          </div>

          <!-- Retour à la connexion -->
          <div class="text-center">
            <button 
              @click="emit('retour-login')" 
              class="text-xs text-blue-600 hover:underline font-bold transition"
            >
              ← Retour à la page de connexion
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
              Assistance et réinitialisation sécurisée des accès au système de dotation.
            </p>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>