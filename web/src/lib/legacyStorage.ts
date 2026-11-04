// The prototype kept its whole state (votes, products, notifications, email choices) in these localStorage keys. The app no
// longer reads them; a browser that still has them keeps another person's data on a shared machine, so they are removed once at boot.
// Delete this file after a few releases, when no browser can still hold them.
const LEGACY_KEYS = ['launchpad-proto-v1', 'launchpad-proto-emails']

export function removeLegacyStorage() {
  for (const key of LEGACY_KEYS) {
    try {
      localStorage.removeItem(key)
    } catch {
      /* storage blocked: nothing to clean */
    }
  }
}
