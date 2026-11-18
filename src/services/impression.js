// Imprime la zone « .zone-impression » de l'écran courant (bon, procès-verbal) : voir assets/main.css.
export function imprimerDocument() {
  document.body.classList.add('impression-bon')
  const fin = () => {
    document.body.classList.remove('impression-bon')
    window.removeEventListener('afterprint', fin)
  }
  window.addEventListener('afterprint', fin)
  window.print()
}
