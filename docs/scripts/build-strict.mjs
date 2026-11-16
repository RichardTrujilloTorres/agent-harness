// Builds the site and fails when VitePress prints a warning or an error.
// Dead links already fail the build (ignoreDeadLinks is false in the config).
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('..', import.meta.url))
const result = spawnSync('npx', ['vitepress', 'build'], { cwd: root, encoding: 'utf8', env: { ...process.env, NO_COLOR: '1' } })
const output = `${result.stdout}${result.stderr}`
process.stdout.write(output)

const warnings = output.split('\n').filter((line) => /\b(warn(ing)?|error)\b/i.test(line))
if (result.status !== 0 || warnings.length > 0) {
  console.error(`\nDocs build failed: exit ${result.status}, ${warnings.length} warning or error line(s).`)
  process.exit(1)
}
console.log('Docs build: 0 warnings, 0 dead links.')
