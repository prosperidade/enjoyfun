import { spawn, spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { URL, fileURLToPath } from 'node:url'

const DEFAULT_DEV_SERVER_PORT = 3003
const DEFAULT_BACKEND_TARGET = 'http://localhost:8080'
const DEV_ENV_FILES = ['.env', '.env.local', '.env.development', '.env.development.local']
const LOCAL_BACKEND_HOSTS = new Set(['localhost', '127.0.0.1', '::1'])

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)
const frontendRoot = path.resolve(__dirname, '..')
const backendRoot = path.resolve(frontendRoot, '..', 'backend')
const viteBin = path.join(frontendRoot, 'node_modules', 'vite', 'bin', 'vite.js')
const backendRouter = path.join(backendRoot, 'router_dev.php')
const localEnv = loadDevEnv()

let backendChild = null
let cleaningUp = false

function loadDevEnv() {
  const env = {}

  for (const relativeFile of DEV_ENV_FILES) {
    const filePath = path.join(frontendRoot, relativeFile)
    if (!fs.existsSync(filePath)) {
      continue
    }

    const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/)
    for (const rawLine of lines) {
      const line = rawLine.trim()
      if (!line || line.startsWith('#')) {
        continue
      }

      const separatorIndex = line.indexOf('=')
      if (separatorIndex <= 0) {
        continue
      }

      const key = line.slice(0, separatorIndex).trim()
      let value = line.slice(separatorIndex + 1).trim()
      if (
        (value.startsWith('"') && value.endsWith('"')) ||
        (value.startsWith("'") && value.endsWith("'"))
      ) {
        value = value.slice(1, -1)
      }

      env[key] = value
    }
  }

  return env
}

function readEnvValue(name) {
  const runtimeValue = process.env[name]
  if (runtimeValue !== undefined && runtimeValue !== '') {
    return runtimeValue
  }

  return localEnv[name]
}

function resolveDevServerPort() {
  const rawPort = readEnvValue('FRONTEND_PORT') || readEnvValue('PORT') || readEnvValue('VITE_PORT')
  const parsedPort = Number.parseInt(String(rawPort ?? ''), 10)
  return Number.isInteger(parsedPort) && parsedPort > 0 ? parsedPort : DEFAULT_DEV_SERVER_PORT
}

function resolveBackendTarget() {
  const target =
    readEnvValue('VITE_BACKEND_URL') ||
    readEnvValue('BACKEND_URL') ||
    readEnvValue('API_PROXY_TARGET') ||
    DEFAULT_BACKEND_TARGET

  return String(target).trim() || DEFAULT_BACKEND_TARGET
}

function runCommand(command, args) {
  const result = spawnSync(command, args, {
    cwd: frontendRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    windowsHide: true,
  })

  return {
    status: result.status ?? 1,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
    error: result.error,
  }
}

function getListeningPids(port) {
  if (process.platform === 'win32') {
    const script = [
      `$connections = Get-NetTCPConnection -LocalPort ${port} -State Listen -ErrorAction SilentlyContinue`,
      `if ($connections) {`,
      `  $connections | Select-Object -ExpandProperty OwningProcess -Unique | ConvertTo-Json -Compress`,
      `}`,
    ].join('; ')
    const result = runCommand('powershell.exe', ['-NoProfile', '-Command', script])

    if (result.error || result.status !== 0 || !result.stdout.trim()) {
      return []
    }

    const payload = JSON.parse(result.stdout.trim())
    return Array.isArray(payload) ? payload : [payload]
  }

  const result = runCommand('lsof', ['-ti', `tcp:${port}`, '-sTCP:LISTEN'])
  if (result.error || result.status !== 0 || !result.stdout.trim()) {
    return []
  }

  return result.stdout
    .split(/\r?\n/)
    .map((value) => Number.parseInt(value.trim(), 10))
    .filter((value) => Number.isInteger(value) && value > 0)
}

function getProcessCommandLine(pid) {
  if (process.platform === 'win32') {
    const script = `(Get-CimInstance Win32_Process -Filter "ProcessId = ${pid}" -ErrorAction SilentlyContinue).CommandLine`
    const result = runCommand('powershell.exe', ['-NoProfile', '-Command', script])
    return result.stdout.trim()
  }

  const result = runCommand('ps', ['-p', String(pid), '-o', 'command='])
  return result.stdout.trim()
}

function isFrontendAutoReclaimCandidate(commandLine) {
  if (!commandLine) {
    // Unknown process — still reclaim on our dedicated dev port
    return true
  }

  const normalized = commandLine.toLowerCase()

  // Reclaim any node/vite/npm process on our dev port
  return (
    normalized.includes('node') ||
    normalized.includes('vite') ||
    normalized.includes('npm') ||
    normalized.includes('pnpm') ||
    normalized.includes('yarn')
  )
}

function isBackendAutoReclaimCandidate(commandLine) {
  if (!commandLine) {
    return false
  }

  const normalized = commandLine.toLowerCase()
  const normalizedRoot = backendRoot.toLowerCase()

  return normalized.includes(normalizedRoot) && normalized.includes('php') && normalized.includes('router_dev.php')
}

function stopProcessTree(pid) {
  if (!Number.isInteger(pid) || pid <= 0) {
    return
  }

  if (process.platform === 'win32') {
    const result = runCommand('taskkill', ['/PID', String(pid), '/T', '/F'])
    if (result.status !== 0) {
      throw new Error(result.stderr.trim() || `taskkill failed for PID ${pid}`)
    }
    return
  }

  process.kill(pid, 'SIGTERM')
}

function waitForPortRelease(port, timeoutMs = 8000) {
  const startedAt = Date.now()

  while (Date.now() - startedAt < timeoutMs) {
    if (getListeningPids(port).length === 0) {
      return true
    }

    Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 250)
  }

  return getListeningPids(port).length === 0
}

function ensureFrontendPortAvailable(port) {
  const pids = getListeningPids(port)
  if (pids.length === 0) {
    return
  }

  for (const pid of pids) {
    if (pid === process.pid) {
      continue
    }

    const commandLine = getProcessCommandLine(pid)

    if (!isFrontendAutoReclaimCandidate(commandLine)) {
      throw new Error(
        `Port ${port} is already in use by PID ${pid}. Command: ${commandLine || 'unknown process'}`
      )
    }

    console.log(`Reclaiming port ${port} from PID ${pid}.`)
    stopProcessTree(pid)
  }

  if (!waitForPortRelease(port)) {
    throw new Error(`Port ${port} did not become available after terminating the previous process.`)
  }
}

function shouldAutoStartBackend() {
  const raw = String(readEnvValue('ENJOYFUN_AUTO_START_BACKEND') ?? '1').trim().toLowerCase()
  return !['0', 'false', 'off', 'no'].includes(raw)
}

function isLocalBackendTarget(rawTarget) {
  try {
    const target = new URL(rawTarget)
    return LOCAL_BACKEND_HOSTS.has(target.hostname.toLowerCase())
  } catch {
    return false
  }
}

function getUrlPort(target) {
  if (target.port) {
    return Number.parseInt(target.port, 10)
  }

  return target.protocol === 'https:' ? 443 : 80
}

function formatListenHost(hostname) {
  return hostname.includes(':') ? `[${hostname}]` : hostname
}

function trimForLog(value, maxLength = 220) {
  const normalized = String(value ?? '').replace(/\s+/g, ' ').trim()
  return normalized.length > maxLength ? `${normalized.slice(0, maxLength - 3)}...` : normalized
}

function buildBackendHealthUrl(rawTarget) {
  const target = new URL(rawTarget)
  const basePath = target.pathname.replace(/\/+$/, '')
  target.pathname = basePath.endsWith('/api') ? `${basePath}/ping` : `${basePath}/api/ping`
  target.search = ''
  target.hash = ''
  return target.toString()
}

async function checkBackendHealth(rawTarget, timeoutMs = 2000) {
  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), timeoutMs)
  const healthUrl = buildBackendHealthUrl(rawTarget)

  try {
    const response = await fetch(healthUrl, {
      method: 'GET',
      signal: controller.signal,
      headers: { Accept: 'application/json' },
    })
    const body = await response.text()

    return {
      healthy: response.ok,
      status: response.status,
      body,
      healthUrl,
    }
  } catch (error) {
    return {
      healthy: false,
      error,
      healthUrl,
    }
  } finally {
    clearTimeout(timeout)
  }
}

async function waitForBackendHealth(rawTarget, timeoutMs = 12000) {
  const startedAt = Date.now()
  let lastResult = null

  while (Date.now() - startedAt < timeoutMs) {
    lastResult = await checkBackendHealth(rawTarget)
    if (lastResult.healthy) {
      return lastResult
    }

    await new Promise((resolve) => setTimeout(resolve, 300))
  }

  return lastResult
}

function resolvePhpBin() {
  return process.env.PHP_BIN || 'php'
}

function resolvePhpExtensionDir() {
  const fromEnv = String(process.env.PHP_EXTENSION_DIR || '').trim()
  if (fromEnv) {
    return fromEnv
  }

  if (process.platform === 'win32') {
    const defaultDir = 'C:\\php\\ext'
    if (fs.existsSync(defaultDir)) {
      return defaultDir
    }
  }

  return ''
}

function getPhpLoadedModules(phpBin) {
  const result = runCommand(phpBin, ['-m'])
  if (result.error || result.status !== 0) {
    return new Set()
  }

  return new Set(
    result.stdout
      .split(/\r?\n/)
      .map((value) => value.trim().toLowerCase())
      .filter(Boolean)
  )
}

function buildBackendPhpArgs(hostname, port) {
  const phpBin = resolvePhpBin()
  const loadedModules = getPhpLoadedModules(phpBin)
  const args = ['-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0']
  const extensionDir = resolvePhpExtensionDir()
  let extensionDirInjected = false

  const ensureExtension = (extensionName) => {
    if (loadedModules.has(extensionName.toLowerCase())) {
      return
    }

    if (!extensionDirInjected && extensionDir) {
      args.push('-d', `extension_dir=${extensionDir}`)
      extensionDirInjected = true
    }

    args.push('-d', `extension=${extensionName}`)
  }

  ensureExtension('pdo_pgsql')
  ensureExtension('pgsql')
  args.push('-S', `${formatListenHost(hostname)}:${port}`, '-t', 'public', 'router_dev.php')

  return { phpBin, args }
}

function stopManagedBackend() {
  if (!backendChild?.pid) {
    backendChild = null
    return
  }

  try {
    stopProcessTree(backendChild.pid)
  } catch {}

  backendChild = null
}

function registerCleanupHandlers() {
  const cleanup = () => {
    if (cleaningUp) {
      return
    }

    cleaningUp = true
    stopManagedBackend()
  }

  process.once('exit', cleanup)
  process.once('SIGINT', () => {
    cleanup()
    process.exit(130)
  })
  process.once('SIGTERM', () => {
    cleanup()
    process.exit(143)
  })
}

async function ensureBackendAvailable(rawTarget) {
  if (!shouldAutoStartBackend() || !isLocalBackendTarget(rawTarget)) {
    return
  }

  if (!fs.existsSync(backendRouter)) {
    throw new Error(`Backend router not found at ${backendRouter}.`)
  }

  const initialHealth = await checkBackendHealth(rawTarget)
  if (initialHealth.healthy) {
    return
  }

  const target = new URL(rawTarget)
  const port = getUrlPort(target)
  const pids = getListeningPids(port)

  if (pids.length > 0) {
    for (const pid of pids) {
      const commandLine = getProcessCommandLine(pid)

      if (!isBackendAutoReclaimCandidate(commandLine)) {
        throw new Error(
          `Backend target ${rawTarget} is unhealthy, and port ${port} is already in use by PID ${pid}. Command: ${commandLine || 'unknown process'}`
        )
      }

      console.log(`Reclaiming backend port ${port} from PID ${pid}.`)
      stopProcessTree(pid)
    }

    if (!waitForPortRelease(port)) {
      throw new Error(`Backend port ${port} did not become available after terminating the previous process.`)
    }
  }

  const { phpBin, args } = buildBackendPhpArgs(target.hostname, port)
  console.log(`Starting backend dev server on ${rawTarget}.`)

  backendChild = spawn(phpBin, args, {
    cwd: backendRoot,
    env: process.env,
    stdio: 'inherit',
    windowsHide: true,
  })

  backendChild.once('error', (error) => {
    console.error(`Failed to start backend dev server: ${error.message}`)
  })

  backendChild.once('exit', (code, signal) => {
    if (!cleaningUp && signal === null && code !== 0) {
      console.error(`Backend dev server exited with code ${code}.`)
    }
  })

  const finalHealth = await waitForBackendHealth(rawTarget)
  if (finalHealth?.healthy) {
    return
  }

  stopManagedBackend()

  const detail = finalHealth?.error
    ? finalHealth.error.message
    : `status ${finalHealth?.status ?? 'unknown'}${finalHealth?.body ? `, body: ${trimForLog(finalHealth.body)}` : ''}`

  throw new Error(
    `Backend dev server did not become healthy at ${finalHealth?.healthUrl || buildBackendHealthUrl(rawTarget)} (${detail}).`
  )
}

async function startVite() {
  if (!fs.existsSync(viteBin)) {
    throw new Error(`Vite binary not found at ${viteBin}. Run npm install in frontend first.`)
  }

  registerCleanupHandlers()

  const backendTarget = resolveBackendTarget()
  await ensureBackendAvailable(backendTarget)

  const port = resolveDevServerPort()
  ensureFrontendPortAvailable(port)

  const child = spawn(process.execPath, [viteBin, ...process.argv.slice(2)], {
    cwd: frontendRoot,
    env: process.env,
    stdio: 'inherit',
  })

  child.on('exit', (code, signal) => {
    if (signal) {
      process.kill(process.pid, signal)
      return
    }

    process.exit(code ?? 0)
  })

  child.on('error', (error) => {
    console.error(error.message)
    process.exit(1)
  })
}

try {
  await startVite()
} catch (error) {
  console.error(error instanceof Error ? error.message : String(error))
  process.exit(1)
}
