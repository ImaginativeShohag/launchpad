/** Full-page navigation to another origin (the Google sign-in redirect). A function of its own so tests can stub it. */
export function leaveSite(url: string) {
  window.location.assign(url)
}
