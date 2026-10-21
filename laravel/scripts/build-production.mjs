import { createHash } from 'node:crypto'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, relative, resolve } from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

export const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')

export function frontendSourceHash() {
  const files = ['package.json', 'package-lock.json', 'vite.config.js', 'scripts/build-production.mjs']
  const collect = directory => {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
      const path = resolve(directory, entry.name)
      if (entry.isDirectory()) collect(path)
      else files.push(relative(root, path).replaceAll('\\', '/'))
    }
  }
  collect(resolve(root, 'resources/js'))
  collect(resolve(root, 'resources/css'))
  const hash = createHash('sha256')
  for (const file of files.sort()) hash.update(file).update('\0').update(readFileSync(resolve(root, file))).update('\0')
  return hash.digest('hex')
}

if (resolve(process.argv[1] || '') === fileURLToPath(import.meta.url)) {
  const basePath = '/sena_reflection'
  const result = spawnSync(process.execPath, [resolve(root, 'node_modules/vite/bin/vite.js'), 'build'], {
    cwd: root,
    env: { ...process.env, VITE_APP_BASE_PATH: basePath, ASSET_URL: `https://krumost.com${basePath}` },
    stdio: 'inherit',
  })
  if (result.error) throw result.error
  if (result.status !== 0) process.exit(result.status || 1)
  writeFileSync(resolve(root, 'public/build/source-version.json'), JSON.stringify({ basePath, sourceHash: frontendSourceHash() }, null, 2) + '\n')
}
