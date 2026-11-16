// Checks every external link in the built site (docs/.vitepress/dist) and lists the result of each.
// Internal links are checked by the VitePress build itself, which fails on dead links.
// Exit code 1 when any external link fails, so a failure is never silent.
import { readdir, readFile } from 'node:fs/promises'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const dist = fileURLToPath(new URL('../.vitepress/dist', import.meta.url))
const timeoutMs = 15000

async function htmlFiles(dir) {
  const entries = await readdir(dir, { withFileTypes: true })
  const files = await Promise.all(entries.map((entry) => {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) return htmlFiles(path)
    return entry.name.endsWith('.html') ? [path] : []
  }))
  return files.flat()
}

async function check(url) {
  for (const method of ['HEAD', 'GET']) {
    try {
      const response = await fetch(url, { method, redirect: 'follow', signal: AbortSignal.timeout(timeoutMs) })
      if (response.ok) return { ok: true, detail: String(response.status) }
      if (method === 'GET') return { ok: false, detail: `HTTP ${response.status}` }
    } catch (error) {
      if (method === 'GET') return { ok: false, detail: error.cause?.code ?? error.message }
    }
  }
  return { ok: false, detail: 'no answer' }
}

const pages = new Map()
let files
try {
  files = await htmlFiles(dist)
} catch {
  console.error('No built site in docs/.vitepress/dist. Run "npm --prefix docs run build" first.')
  process.exit(2)
}
for (const file of files) {
  const html = await readFile(file, 'utf8')
  for (const [, url] of html.matchAll(/href="(https?:\/\/[^"#]+)[^"]*"/g)) {
    if (!pages.has(url)) pages.set(url, new Set())
    pages.get(url).add(relative(dist, file))
  }
}

let failed = 0
for (const [url, where] of [...pages].sort(([a], [b]) => a.localeCompare(b))) {
  const result = await check(url)
  if (!result.ok) failed++
  console.log(`${result.ok ? 'OK  ' : 'FAIL'} ${url} (${result.detail}) in ${[...where].sort().join(', ')}`)
}
console.log(`${pages.size} external link(s), ${failed} failed`)
process.exit(failed > 0 ? 1 : 0)
