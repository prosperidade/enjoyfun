import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const repoRoot = process.cwd();
const defaultManifestRelativePath = path.join('database', 'drift_replay_manifest.json');
const manifestPath = path.resolve(repoRoot, process.env.DRIFT_REPLAY_MANIFEST_PATH || defaultManifestRelativePath);
const fingerprintSqlPath = path.join(repoRoot, 'scripts', 'ci', 'schema_fingerprint.sql');

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function normalizePath(relativePath) {
  return path.join(repoRoot, relativePath);
}

function resolveBinary(envKey, defaultBinary, windowsCandidates = []) {
  if (process.env[envKey]) {
    return process.env[envKey];
  }

  if (process.platform === 'win32') {
    for (const candidate of windowsCandidates) {
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    }
  }

  return defaultBinary;
}

const psqlBinary = resolveBinary('PSQL_BIN', 'psql', [
  'C:\\Program Files\\PostgreSQL\\18\\bin\\psql.exe',
  'C:\\Program Files\\PostgreSQL\\17\\bin\\psql.exe',
  'C:\\Program Files\\PostgreSQL\\16\\bin\\psql.exe',
]);

const connectionEnv = {
  ...process.env,
  PGHOST: process.env.PGHOST || '127.0.0.1',
  PGPORT: process.env.PGPORT || '5432',
  PGUSER: process.env.PGUSER || 'postgres',
  PGPASSWORD: process.env.PGPASSWORD || 'postgres',
};

const adminDb = process.env.PGADMIN_DB || 'postgres';
const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'enjoyfun-drift-'));
const keepArtifacts = process.env.KEEP_DRIFT_ARTIFACTS === 'true';
const suffix = crypto.randomBytes(4).toString('hex');
const baselineDb = `drift_baseline_${suffix}`;
const replayDb = `drift_replay_${suffix}`;

function cleanupTempDir() {
  if (!keepArtifacts) {
    fs.rmSync(tempDir, { recursive: true, force: true });
  }
}

function ensureFile(filePath) {
  if (!fs.existsSync(filePath)) {
    throw new Error(`Arquivo obrigatorio ausente: ${path.relative(repoRoot, filePath)}`);
  }
}

function sanitizeSqlForLoad(sqlText) {
  const removableSessionPrefixes = [
    'SET statement_timeout',
    'SET lock_timeout',
    'SET idle_in_transaction_session_timeout',
    'SET transaction_timeout',
    'SET client_encoding',
    'SET standard_conforming_strings',
    'SET check_function_bodies',
    'SET xmloption',
    'SET client_min_messages',
    'SET row_security',
    'SET default_table_access_method',
  ];

  return sqlText
    .replace(/\r\n/g, '\n')
    .split('\n')
    .filter((line) => {
      const trimmed = line.trimStart();
      if (trimmed.startsWith('\\restrict')) {
        return false;
      }
      if (trimmed.startsWith('\\unrestrict')) {
        return false;
      }
      if (removableSessionPrefixes.some((prefix) => trimmed.startsWith(prefix))) {
        return false;
      }
      if (trimmed.startsWith('SELECT pg_catalog.set_config(')) {
        return false;
      }
      return true;
    })
    .join('\n');
}

function runProcess(binary, args, label) {
  const result = spawnSync(binary, args, {
    cwd: repoRoot,
    env: connectionEnv,
    encoding: 'utf8',
  });

  if (result.error) {
    throw new Error(`${label}: falha ao iniciar processo: ${result.error.message}`);
  }

  if (result.status !== 0) {
    throw new Error(
      `${label}: comando falhou com exit code ${result.status}\nSTDOUT:\n${result.stdout || ''}\nSTDERR:\n${result.stderr || ''}`,
    );
  }

  return result.stdout || '';
}

function runSqlCommand(databaseName, sql, label) {
  return runProcess(
    psqlBinary,
    ['-X', '-v', 'ON_ERROR_STOP=1', '-d', databaseName, '-c', sql],
    label,
  );
}

function runSqlFile(databaseName, filePath, label) {
  const sqlText = sanitizeSqlForLoad(fs.readFileSync(filePath, 'utf8'));
  const tempSqlPath = path.join(
    tempDir,
    `${path.basename(filePath, path.extname(filePath))}-${crypto.randomBytes(4).toString('hex')}.sql`,
  );

  fs.writeFileSync(tempSqlPath, sqlText, 'utf8');
  try {
    return runProcess(
      psqlBinary,
      ['-X', '-v', 'ON_ERROR_STOP=1', '-d', databaseName, '-f', tempSqlPath],
      label,
    );
  } finally {
    if (!keepArtifacts) {
      fs.rmSync(tempSqlPath, { force: true });
    }
  }
}

function quoteIdentifier(identifier) {
  return `"${identifier.replace(/"/g, '""')}"`;
}

function recreateDatabase(databaseName) {
  runSqlCommand(adminDb, `DROP DATABASE IF EXISTS ${quoteIdentifier(databaseName)} WITH (FORCE);`, `dropdb ${databaseName}`);
  runSqlCommand(adminDb, `CREATE DATABASE ${quoteIdentifier(databaseName)};`, `createdb ${databaseName}`);
}

function dropDatabase(databaseName) {
  try {
    runSqlCommand(adminDb, `DROP DATABASE IF EXISTS ${quoteIdentifier(databaseName)} WITH (FORCE);`, `cleanup ${databaseName}`);
  } catch (error) {
    console.warn(`WARN cleanup ${databaseName}: ${error.message}`);
  }
}

function collectFingerprint(databaseName, outputFileName) {
  const result = runProcess(
    psqlBinary,
    ['-X', '-v', 'ON_ERROR_STOP=1', '-At', '-d', databaseName, '-f', fingerprintSqlPath],
    `fingerprint ${databaseName}`,
  );
  const normalized = result.replace(/\r\n/g, '\n').trimEnd() + '\n';
  const outputPath = path.join(tempDir, outputFileName);
  fs.writeFileSync(outputPath, normalized, 'utf8');
  return { normalized, outputPath };
}

function buildDiffSnippet(leftText, rightText, limit = 60) {
  const leftLines = leftText.split('\n');
  const rightLines = rightText.split('\n');
  const maxLines = Math.max(leftLines.length, rightLines.length);
  const chunks = [];

  for (let index = 0; index < maxLines; index += 1) {
    const leftLine = leftLines[index] ?? '';
    const rightLine = rightLines[index] ?? '';
    if (leftLine === rightLine) {
      continue;
    }

    chunks.push(
      `linha ${index + 1}\n- baseline: ${leftLine}\n- replay:   ${rightLine}`,
    );

    if (chunks.length >= limit) {
      break;
    }
  }

  return chunks.join('\n\n');
}

function ensureManifestCoverage(manifest) {
  ensureFile(fingerprintSqlPath);

  const baselinePath = normalizePath(manifest.target_baseline);
  ensureFile(baselinePath);

  const seedPath = normalizePath(manifest.supported_replay_window.seed_dump);
  ensureFile(seedPath);

  for (const migrationPath of manifest.supported_replay_window.migrations) {
    ensureFile(normalizePath(migrationPath));
  }
}

function main() {
  ensureFile(manifestPath);
  const manifest = readJson(manifestPath);
  ensureManifestCoverage(manifest);

  console.log('== Drift replay / Sprint 1 ==');
  console.log(`manifest: ${path.relative(repoRoot, manifestPath)}`);
  console.log(`seed dump: ${manifest.supported_replay_window.seed_dump}`);
  console.log(`target baseline: ${manifest.target_baseline}`);
  console.log(`replay window: ${manifest.supported_replay_window.migrations.join(', ')}`);

  recreateDatabase(baselineDb);
  recreateDatabase(replayDb);

  try {
    runSqlFile(baselineDb, normalizePath(manifest.target_baseline), 'load baseline schema_current.sql');
    runSqlFile(replayDb, normalizePath(manifest.supported_replay_window.seed_dump), 'load replay seed dump');

    for (const migrationPath of manifest.supported_replay_window.migrations) {
      runSqlFile(replayDb, normalizePath(migrationPath), `apply ${migrationPath}`);
    }

    const baselineFingerprint = collectFingerprint(baselineDb, 'baseline.fingerprint.txt');
    const replayFingerprint = collectFingerprint(replayDb, 'replay.fingerprint.txt');

    if (baselineFingerprint.normalized !== replayFingerprint.normalized) {
      const diffSnippet = buildDiffSnippet(
        baselineFingerprint.normalized,
        replayFingerprint.normalized,
      );
      throw new Error(
        `drift detectado entre baseline e replay suportado.\n` +
          `baseline fingerprint: ${baselineFingerprint.outputPath}\n` +
          `replay fingerprint: ${replayFingerprint.outputPath}\n\n` +
          diffSnippet,
      );
    }

    console.log('OK   replay suportado reproduz a fingerprint do baseline atual');
  } finally {
    dropDatabase(baselineDb);
    dropDatabase(replayDb);
    cleanupTempDir();
  }
}

try {
  main();
} catch (error) {
  console.error(error.message);
  process.exit(1);
}
