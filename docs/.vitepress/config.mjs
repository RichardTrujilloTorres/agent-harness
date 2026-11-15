import { defineConfig } from 'vitepress'

const guides = [
  ['Writing scenarios', '/guides/writing-scenarios'],
  ['Connecting your agent', '/guides/connecting-agent'],
  ['Assertions', '/guides/assertions'],
  ['LLM judge', '/guides/llm-judge'],
  ['Reports', '/guides/reports'],
  ['Using it in CI', '/guides/ci'],
  ['Pest plugin', '/guides/pest-plugin'],
  ['Flaky runs', '/guides/flaky-runs'],
  ['Editor support', '/guides/editor-support'],
  ['Running as a PHAR', '/guides/phar'],
].map(([text, link]) => ({ text, link }))

const reference = [
  ['CLI', '/reference/cli'],
  ['Scenario file', '/reference/scenario'],
  ['harness.yaml', '/reference/config'],
  ['JSON output', '/reference/json-output'],
  ['Exit codes', '/reference/exit-codes'],
  ['Environment variables', '/reference/environment'],
].map(([text, link]) => ({ text, link }))

const concepts = [
  ['How it works', '/concepts/how-it-works'],
  ['Design decisions', '/concepts/design-decisions'],
  ['Glossary', '/concepts/glossary'],
].map(([text, link]) => ({ text, link }))

const project = [
  ['Roadmap', '/project/roadmap'],
  ['Contributing', '/project/contributing'],
  ['Commit conventions', '/project/commit-conventions'],
  ['Changelog', '/project/changelog'],
  ['License', '/project/license'],
].map(([text, link]) => ({ text, link }))

export default defineConfig({
  title: 'agent-harness',
  description: 'Scenario-based tests for conversational agents',
  base: process.env.DOCS_BASE || '/',
  cleanUrls: true,
  lastUpdated: false,
  ignoreDeadLinks: false,
  themeConfig: {
    editLink: false,
    search: { provider: 'local' },
    nav: [
      { text: 'Quickstart', link: '/quickstart' },
      { text: 'Guides', link: '/guides/writing-scenarios' },
      { text: 'Reference', link: '/reference/cli' },
      { text: 'Concepts', link: '/concepts/how-it-works' },
      { text: 'Security', link: '/security/' },
      { text: 'Troubleshooting', link: '/troubleshooting' },
      { text: 'Project', link: '/project/roadmap' },
    ],
    sidebar: [
      { text: 'Start', items: [{ text: 'Home', link: '/' }, { text: 'Quickstart', link: '/quickstart' }] },
      { text: 'Guides', items: guides },
      { text: 'Reference', items: reference },
      { text: 'Concepts', items: concepts },
      { text: 'Security', items: [{ text: 'Overview', link: '/security/' }, { text: 'Threat model', link: '/security/threat-model' }, { text: 'Security policy', link: '/security/policy' }] },
      { text: 'Troubleshooting', items: [{ text: 'Errors and FAQ', link: '/troubleshooting' }] },
      { text: 'Project', items: project },
    ],
  },
})
