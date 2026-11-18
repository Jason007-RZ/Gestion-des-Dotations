// Aides de sécurité côté interface. Le serveur reste l'autorité : ces fonctions ne font qu'aider l'utilisateur.

// Longueur minimale d'un mot de passe (NIST SP 800-63B-4 : la longueur prime sur la complexité).
// Doit rester alignée avec SECURITE_MDP_MIN côté Laravel.
export const MDP_MIN = 15

// Alphabet sans caractères ambigus (0/O, 1/l/I).
const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_.!?'

/** Mot de passe aléatoire généré avec le générateur cryptographique du navigateur (sans biais de modulo). */
export function genererMotDePasse(longueur = 20) {
  const limite = Math.floor(0x100000000 / ALPHABET.length) * ALPHABET.length
  let sortie = ''
  while (sortie.length < longueur) {
    const tirage = new Uint32Array(longueur)
    crypto.getRandomValues(tirage)
    for (const valeur of tirage) {
      if (valeur < limite && sortie.length < longueur) sortie += ALPHABET[valeur % ALPHABET.length]
    }
  }
  return sortie
}

/** Niveau de 0 à 4 pour la jauge : uniquement la longueur, comme le recommande le NIST. */
export function niveauMotDePasse(motDePasse) {
  const n = (motDePasse || '').length
  if (n === 0) return 0
  if (n < MDP_MIN) return 1
  if (n < 18) return 2
  if (n < 24) return 3
  return 4
}

export const LIBELLES_NIVEAU = ['', `Trop court (${MDP_MIN} caractères minimum)`, 'Acceptable', 'Bon', 'Excellent']

/** Découpe un secret en groupes de 4 caractères pour la saisie manuelle. */
export const grouper = (secret) => (secret || '').match(/.{1,4}/g)?.join(' ') || ''

/** QR code (image data: URL) d'un texte, ex. l'URI otpauth://. Chargé à la demande pour ne pas alourdir l'application. */
export async function qrCodeEnImage(texte) {
  const { default: qrcode } = await import('qrcode-generator')
  const qr = qrcode(0, 'M')
  qr.addData(texte)
  qr.make()
  return qr.createDataURL(6, 8)
}

/** Télécharge un texte brut (codes de secours) sans passer par le serveur. */
export function telechargerTexte(nomFichier, contenu) {
  const url = URL.createObjectURL(new Blob([contenu], { type: 'text/plain;charset=utf-8' }))
  const lien = document.createElement('a')
  lien.href = url
  lien.download = nomFichier
  document.body.appendChild(lien)
  lien.click()
  lien.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}
