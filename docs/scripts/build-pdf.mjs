// Builds build/agent-harness-docs.pdf from the built site (docs/.vitepress/dist), so the PDF and the
// site come from the same Markdown and the same Markdown renderer.
//
// Steps: read the page order from the sidebar, take the rendered content of each page from dist,
// make every id and internal link unique inside one document, add a cover and a table of contents,
// print with headless Chromium, read the page of every section back from the PDF, fill the page
// numbers into the table of contents and print again.
//
// The browser is started and closed inside this script (try/finally); nothing is left running.
// Run it through `npm run pdf`, which builds the site first.
import { execFileSync } from 'node:child_process'
import { mkdir, stat, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { chromium } from 'playwright'
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs'
import config from '../.vitepress/config.mjs'
import { linkRewriter, slug } from './pdf-links.mjs'

const docs = fileURLToPath(new URL('..', import.meta.url))
const repo = join(docs, '..')
const dist = join(docs, '.vitepress', 'dist')
const output = join(repo, 'build', 'agent-harness-docs.pdf')

const version = execFileSync('php', ['-r', 'require "vendor/autoload.php"; echo Desemax\\AgentHarness\\Application::VERSION;'], { cwd: repo, encoding: 'utf8' }).trim()
if (!/^\d+\.\d+\.\d+/.test(version)) throw new Error(`Unexpected Application::VERSION: ${version}`)
const date = new Date().toISOString().slice(0, 10)

// Page order and section titles come from the sidebar, so the PDF follows the site navigation.
const sections = config.themeConfig.sidebar.map((group) => ({
  title: group.text,
  pages: group.items.map((item) => ({ title: item.text, link: item.link })),
}))
const pages = sections.flatMap((section) => section.pages)

function distFile(link) {
  if (link === '/') return join(dist, 'index.html')
  if (link.endsWith('/')) return join(dist, link, 'index.html')
  return join(dist, `${link}.html`)
}

const rewriteHref = linkRewriter(pages.map((page) => page.link))

function escape(text) {
  return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
}

const css = `
@page { size: A4; margin: 18mm 16mm 20mm 16mm; }
* { box-sizing: border-box; }
html { font-family: -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; font-size: 10.5pt; line-height: 1.5; color: #1f2328; }
body { margin: 0; }
a { color: #0b57d0; text-decoration: none; }
h1, h2, h3, h4 { line-height: 1.25; break-after: avoid; page-break-after: avoid; }
h1 { font-size: 20pt; margin: 0 0 10pt; }
h2 { font-size: 14pt; margin: 18pt 0 6pt; padding-bottom: 3pt; border-bottom: 1px solid #d0d7de; }
h3 { font-size: 12pt; margin: 14pt 0 4pt; }
h4 { font-size: 10.5pt; margin: 12pt 0 4pt; }
p, ul, ol { margin: 0 0 7pt; }
code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 8.8pt; background: #f3f4f6; padding: 0 2pt; border-radius: 3px; }
pre { margin: 0 0 9pt; padding: 7pt 9pt; background: #f6f8fa; border: 1px solid #d0d7de; border-radius: 4px;
      white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; }
pre code { background: none; padding: 0; font-size: 8.3pt; line-height: 1.45; }
pre .line { display: inline; }
.shiki span { color: var(--shiki-light); }
.vp-code-group .tabs { display: none; }
table { border-collapse: collapse; width: 100%; margin: 0 0 9pt; font-size: 9pt; break-inside: auto; }
tr { break-inside: avoid; }
th, td { border: 1px solid #d0d7de; padding: 3pt 5pt; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
th { background: #f6f8fa; }
blockquote, .custom-block { margin: 0 0 9pt; padding: 5pt 9pt; border-left: 3px solid #8c959f; background: #f6f8fa; }
.custom-block-title { font-weight: 600; margin-bottom: 3pt; }
figure { margin: 0 0 9pt; break-inside: avoid; }
svg { max-width: 100%; height: auto; }
img { max-width: 100%; }
.header-anchor, button.copy, span.lang { display: none; }
.cover { height: 250mm; display: flex; flex-direction: column; justify-content: center; break-after: page; }
.cover h1 { font-size: 34pt; margin-bottom: 8pt; border: none; }
.cover .subtitle { font-size: 14pt; color: #57606a; margin-bottom: 30pt; }
.cover dl { font-size: 11pt; display: grid; grid-template-columns: max-content auto; gap: 4pt 14pt; }
.cover dt { color: #57606a; }
.cover dd { margin: 0; }
.toc { break-after: page; }
.toc h1 { margin-bottom: 14pt; }
.toc .group { font-weight: 600; margin: 10pt 0 3pt; }
.toc a { display: flex; color: #1f2328; padding: 1.5pt 0 1.5pt 12pt; }
.toc a .title { flex: none; }
.toc a .dots { flex: 1; border-bottom: 1px dotted #8c959f; margin: 0 4pt 3pt; }
.toc a .page { flex: none; min-width: 18pt; text-align: right; font-variant-numeric: tabular-nums; }
.page-break { break-before: page; }
.section-title { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.06em; color: #57606a; margin: 0 0 4pt; }
`

async function contentOf(browserPage, link) {
  const file = distFile(link)
  await stat(file)
  await browserPage.goto(pathToFileURL(file).href, { waitUntil: 'domcontentloaded' })
  return browserPage.evaluate(({ pageId }) => {
    const root = document.querySelector('.vp-doc > div')
    if (!root) throw new Error(`No .vp-doc content in ${location.href}`)
    const clone = root.cloneNode(true)
    clone.querySelectorAll('.header-anchor, button.copy, span.lang, script').forEach((node) => node.remove())
    clone.querySelectorAll('[id]').forEach((node) => { node.id = `${pageId}--${node.id}` })
    return clone.innerHTML
  }, { pageId: slug(link) })
}

function printable(bodies, pageNumbers) {
  const toc = sections.map((section) => `
    <div class="group">${escape(section.title)}</div>
    ${section.pages.map((page) => `<a href="#${slug(page.link)}"><span class="title">${escape(page.title)}</span><span class="dots"></span><span class="page">${pageNumbers.get(slug(page.link)) ?? '000'}</span></a>`).join('')}`).join('')

  let previousSection = null
  const body = pages.map((page) => {
    const section = sections.find((candidate) => candidate.pages.includes(page))
    const label = section.title !== previousSection ? `<p class="section-title">${escape(section.title)}</p>` : ''
    previousSection = section.title
    return `<section class="page-break" id="${slug(page.link)}">${label}${bodies.get(page.link)}</section>`
  }).join('\n')

  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>agent-harness ${escape(version)} documentation</title><style>${css}</style></head><body>
    <div class="cover">
      <h1>agent-harness</h1>
      <div class="subtitle">${escape(config.description)}</div>
      <dl><dt>Version</dt><dd>${escape(version)}</dd><dt>Date</dt><dd>${date}</dd></dl>
    </div>
    <nav class="toc"><h1>Contents</h1>${toc}</nav>
    ${body}
  </body></html>`
}

const footer = '<div style="font-size:8pt;width:100%;text-align:center;color:#57606a;font-family:sans-serif;"><span class="pageNumber"></span> / <span class="totalPages"></span></div>'

async function print(browserPage, html) {
  await browserPage.setContent(html, { waitUntil: 'load' })
  return browserPage.pdf({
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate: footer,
    outline: true,
    tagged: true,
  })
}

// Reads the 1-based page of every page section from the PDF's named destinations.
async function sectionPages(pdf) {
  const task = getDocument({ data: new Uint8Array(pdf), useSystemFonts: true })
  const doc = await task.promise
  try {
    // Chromium writes the named destinations as a /Dests dictionary; pdf.js resolves those one name
    // at a time (getDestinations() returns an empty object for them).
    const numbers = new Map()
    for (const page of pages) {
      const id = slug(page.link)
      const destination = await doc.getDestination(id)
      if (!destination) throw new Error(`No PDF destination for ${id}`)
      numbers.set(id, (await doc.getPageIndex(destination[0])) + 1)
    }
    return { numbers, pageCount: doc.numPages }
  } finally {
    await task.destroy()
  }
}

let browser
try {
  browser = await chromium.launch()
  const browserPage = await browser.newPage()

  const bodies = new Map()
  for (const page of pages) {
    const html = await contentOf(browserPage, page.link)
    bodies.set(page.link, html.replace(/href="([^"]*)"/g, (match, href) => `href="${rewriteHref(href.replaceAll('&amp;', '&'), page.link)}"`))
  }

  let numbers = new Map()
  let pdf
  for (let pass = 1; pass <= 3; pass++) {
    pdf = await print(browserPage, printable(bodies, numbers))
    let result
    try {
      result = await sectionPages(pdf)
    } catch (error) {
      await mkdir(join(repo, 'build'), { recursive: true })
      await writeFile(join(repo, 'build', 'agent-harness-docs.failed.pdf'), pdf)
      console.error('Could not read the page numbers back; the unfinished PDF is in build/agent-harness-docs.failed.pdf')
      throw error
    }
    const stable = pages.every((page) => numbers.get(slug(page.link)) === result.numbers.get(slug(page.link)))
    numbers = result.numbers
    if (stable) break
    if (pass === 3) throw new Error('Table of contents page numbers did not settle after 3 passes')
  }

  await mkdir(join(repo, 'build'), { recursive: true })
  await writeFile(output, pdf)
  const { pageCount } = await sectionPages(pdf)
  console.log(`Wrote build/agent-harness-docs.pdf: version ${version}, ${pageCount} pages, ${(pdf.length / 1024).toFixed(0)} KiB`)
} finally {
  await browser?.close()
}
