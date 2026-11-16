// Maps links of the rendered site to anchors inside the single PDF document.
// Kept apart from build-pdf.mjs so the mapping can be checked without starting a browser.

// The anchor id of a site page inside the PDF, for example /guides/ci -> p-guides-ci.
export function slug(link) {
  const path = link.replace(/^\//, '').replace(/\/$/, '')
  return path === '' ? 'home' : `p-${path.replaceAll('/', '-')}`
}

// Returns rewriteHref(href, currentLink) for the given page links (as in the sidebar).
// VitePress renders Markdown links as relative hrefs (./quickstart, ../guides/ci); they are resolved
// against the page they appear on. Links with a scheme (https:, mailto:) are kept as they are.
export function linkRewriter(pageLinks) {
  const known = new Set(pageLinks)
  return function rewriteHref(href, currentLink) {
    if (href.startsWith('#')) return `#${slug(currentLink)}--${href.slice(1)}`
    if (/^[a-z][a-z0-9+.-]*:/i.test(href)) return href
    const resolved = new URL(href, `https://site.invalid${currentLink}`)
    const clean = decodeURI(resolved.pathname).replace(/\.html$/, '')
    const fragment = resolved.hash.slice(1)
    const target = known.has(clean) ? clean : known.has(`${clean}/`) ? `${clean}/` : null
    if (target === null) throw new Error(`Link ${href} on ${currentLink} points to a page that is not in the PDF`)
    return fragment ? `#${slug(target)}--${decodeURIComponent(fragment)}` : `#${slug(target)}`
  }
}
