import fs from 'node:fs';
import path from 'node:path';

const repoRoot = process.cwd();

const failures = [];
const warnings = [];
const passes = [];

function relativePath(...parts) {
  return path.join(repoRoot, ...parts);
}

function readText(...parts) {
  return fs.readFileSync(relativePath(...parts), 'utf8');
}

function ensureFile(label, ...parts) {
  const filePath = relativePath(...parts);
  if (!fs.existsSync(filePath)) {
    failures.push(`${label}: arquivo ausente em ${path.relative(repoRoot, filePath)}`);
    return false;
  }
  passes.push(`${label}: encontrado`);
  return true;
}

function extractMigrationNumber(fileName) {
  const match = /^(\d{3})_.+\.sql$/.exec(fileName);
  return match ? Number(match[1]) : null;
}

function summarizeList(items, limit = 8) {
  if (items.length <= limit) {
    return items.join(', ');
  }
  const visible = items.slice(0, limit).join(', ');
  return `${visible} ... (+${items.length - limit})`;
}

function summarizeStatuses(items) {
  const counts = new Map();
  for (const item of items) {
    counts.set(item.status, (counts.get(item.status) || 0) + 1);
  }
  return [...counts.entries()]
    .sort((left, right) => left[0].localeCompare(right[0]))
    .map(([status, count]) => `${status}=${count}`)
    .join(', ');
}

const requiredFiles = [
  ['baseline canonico', 'database', 'schema_current.sql'],
  ['historico de migrations', 'database', 'migrations_applied.log'],
  ['historico de dumps', 'database', 'dump_history.log'],
  ['registry historico de migrations', 'database', 'migration_history_registry.json'],
  ['manifesto de replay de drift', 'database', 'drift_replay_manifest.json'],
  ['script oficial de migration', 'database', 'apply_migration.bat'],
  ['script oficial de dump', 'database', 'dump_schema.bat'],
  ['runbook operacional', 'docs', 'runbook_local.md'],
  ['definition of ready', 'docs', 'definition_of_ready_ambiente_v1.md'],
  ['template de pull request', '.github', 'pull_request_template.md'],
  ['script de drift replay', 'scripts', 'ci', 'check_schema_drift_replay.mjs'],
  ['fingerprint de schema', 'scripts', 'ci', 'schema_fingerprint.sql'],
];

for (const [label, ...parts] of requiredFiles) {
  ensureFile(label, ...parts);
}

let migrationHistoryRegistry = null;
let reservedNumbers = new Set();
let documentedLogGaps = new Map();

const migrationHistoryRegistryPath = relativePath('database', 'migration_history_registry.json');
if (fs.existsSync(migrationHistoryRegistryPath)) {
  try {
    migrationHistoryRegistry = JSON.parse(readText('database', 'migration_history_registry.json'));
    passes.push('migration_history_registry.json: JSON valido');

    reservedNumbers = new Set(
      (migrationHistoryRegistry.reserved_numbers || []).map((entry) => entry.number),
    );
    documentedLogGaps = new Map(
      (migrationHistoryRegistry.log_gap_registry || []).map((entry) => [entry.migration, entry]),
    );

    const duplicateRegistryEntries = (migrationHistoryRegistry.log_gap_registry || []).filter(
      (entry, index, allEntries) =>
        allEntries.findIndex((candidate) => candidate.migration === entry.migration) !== index,
    );

    if (duplicateRegistryEntries.length > 0) {
      failures.push(
        `migration_history_registry.json: migrations duplicadas (${summarizeList(duplicateRegistryEntries.map((entry) => entry.migration))})`,
      );
    }

    const missingReasons = (migrationHistoryRegistry.log_gap_registry || []).filter(
      (entry) => typeof entry.reason !== 'string' || entry.reason.trim() === '',
    );

    if (missingReasons.length > 0) {
      failures.push(
        `migration_history_registry.json: entradas sem reason (${summarizeList(missingReasons.map((entry) => entry.migration))})`,
      );
    }
  } catch (error) {
    failures.push(`migration_history_registry.json: JSON invalido (${error.message})`);
  }
}

const databaseDir = relativePath('database');
const databaseEntries = fs.readdirSync(databaseDir, { withFileTypes: true });
const migrationFiles = databaseEntries
  .filter((entry) => entry.isFile() && /^\d{3}_.+\.sql$/.test(entry.name))
  .map((entry) => entry.name)
  .sort((left, right) => {
    const leftNumber = extractMigrationNumber(left);
    const rightNumber = extractMigrationNumber(right);
    if (leftNumber !== rightNumber) {
      return leftNumber - rightNumber;
    }
    return left.localeCompare(right);
  });

if (migrationFiles.length === 0) {
  failures.push('migrations versionadas: nenhuma migration numerada encontrada em database/');
}

const seenNumbers = new Map();
for (const fileName of migrationFiles) {
  const migrationNumber = extractMigrationNumber(fileName);
  if (seenNumbers.has(migrationNumber)) {
    failures.push(
      `migrations versionadas: prefixo ${String(migrationNumber).padStart(3, '0')} duplicado em ${seenNumbers.get(migrationNumber)} e ${fileName}`,
    );
    continue;
  }
  seenNumbers.set(migrationNumber, fileName);
}

if (migrationFiles.length > 0) {
  const highestMigration = migrationFiles[migrationFiles.length - 1];
  passes.push(`migrations versionadas: topo atual ${highestMigration}`);

  const migrationNumbers = migrationFiles.map((fileName) => extractMigrationNumber(fileName));
  const gaps = [];
  for (let index = 1; index < migrationNumbers.length; index += 1) {
    const previous = migrationNumbers[index - 1];
    const current = migrationNumbers[index];
    if (current - previous > 1) {
      for (let missing = previous + 1; missing < current; missing += 1) {
        gaps.push(String(missing).padStart(3, '0'));
      }
    }
  }

  if (gaps.length > 0) {
    const documentedGaps = gaps.filter((gap) => reservedNumbers.has(gap));
    const unexpectedGaps = gaps.filter((gap) => !reservedNumbers.has(gap));

    if (documentedGaps.length > 0) {
      passes.push(`migrations versionadas: gaps historicos documentados (${summarizeList(documentedGaps)})`);
    }

    if (unexpectedGaps.length > 0) {
      warnings.push(`migrations versionadas: gaps historicos detectados (${summarizeList(unexpectedGaps)})`);
    }
  } else {
    passes.push('migrations versionadas: sem gaps numericos');
  }
}

const driftManifestPath = relativePath('database', 'drift_replay_manifest.json');
if (fs.existsSync(driftManifestPath)) {
  let manifest;
  try {
    manifest = JSON.parse(readText('database', 'drift_replay_manifest.json'));
    passes.push('drift_replay_manifest.json: JSON valido');
  } catch (error) {
    failures.push(`drift_replay_manifest.json: JSON invalido (${error.message})`);
  }

  if (manifest?.target_baseline) {
    const targetBaselinePath = relativePath(...manifest.target_baseline.split('/'));
    if (!fs.existsSync(targetBaselinePath)) {
      failures.push(`drift_replay_manifest.json: target_baseline ausente (${manifest.target_baseline})`);
    }
  }

  if (manifest?.supported_replay_window?.seed_dump) {
    const seedDumpPath = relativePath(...manifest.supported_replay_window.seed_dump.split('/'));
    if (!fs.existsSync(seedDumpPath)) {
      failures.push(`drift_replay_manifest.json: seed_dump ausente (${manifest.supported_replay_window.seed_dump})`);
    }
  }

  if (Array.isArray(manifest?.supported_replay_window?.migrations)) {
    const replayMigrations = manifest.supported_replay_window.migrations;
    const missingReplayMigrations = replayMigrations.filter((migrationPath) => {
      const migrationFullPath = relativePath(...migrationPath.split('/'));
      return !fs.existsSync(migrationFullPath);
    });

    if (missingReplayMigrations.length > 0) {
      failures.push(
        `drift_replay_manifest.json: migrations ausentes no replay window (${summarizeList(missingReplayMigrations)})`,
      );
    }

    const latestReplayMigration = replayMigrations.at(-1)?.split('/').at(-1);
    const latestRepoMigration = migrationFiles.at(-1);
    if (latestReplayMigration && latestRepoMigration && latestReplayMigration !== latestRepoMigration) {
      failures.push(
        `drift_replay_manifest.json: topo do replay window desatualizado, esperado ${latestRepoMigration} e encontrado ${latestReplayMigration}`,
      );
    } else if (latestReplayMigration && latestRepoMigration) {
      passes.push(`drift_replay_manifest.json: topo do replay window alinhado com ${latestRepoMigration}`);
    }
  } else {
    failures.push('drift_replay_manifest.json: replay window sem lista de migrations');
  }
}

const logPath = relativePath('database', 'migrations_applied.log');
if (fs.existsSync(logPath)) {
  const logContent = readText('database', 'migrations_applied.log');
  const loggedMigrations = [...logContent.matchAll(/(?:database[\\/])?(\d{3}_[^|\s]+\.sql)/g)].map((match) => match[1]);

  if (loggedMigrations.length === 0) {
    failures.push('migrations_applied.log: nenhuma migration numerada encontrada no log');
  } else {
    const missingLoggedFiles = loggedMigrations.filter((fileName) => !seenNumbers.has(extractMigrationNumber(fileName)));
    if (missingLoggedFiles.length > 0) {
      failures.push(
        `migrations_applied.log: o log referencia arquivos ausentes no repositorio (${summarizeList(missingLoggedFiles)})`,
      );
    } else {
      passes.push(`migrations_applied.log: ${loggedMigrations.length} entradas numeradas reconhecidas`);
    }

    const latestLoggedMigration = loggedMigrations
      .slice()
      .sort((left, right) => extractMigrationNumber(left) - extractMigrationNumber(right))
      .at(-1);
    const latestRepoMigration = migrationFiles.at(-1);

    if (latestLoggedMigration && latestRepoMigration && latestLoggedMigration !== latestRepoMigration) {
      failures.push(
        `migrations_applied.log: topo desalinhado, repo em ${latestRepoMigration} e log em ${latestLoggedMigration}`,
      );
    } else if (latestLoggedMigration && latestRepoMigration) {
      passes.push(`migrations_applied.log: topo alinhado com ${latestRepoMigration}`);
    }

    const trackedWindowStart = extractMigrationNumber(loggedMigrations[0]);
    const untrackedInsideWindow = migrationFiles.filter((fileName) => {
      const migrationNumber = extractMigrationNumber(fileName);
      return migrationNumber >= trackedWindowStart && !loggedMigrations.includes(fileName);
    });

    if (untrackedInsideWindow.length > 0) {
      const documentedEntries = untrackedInsideWindow
        .map((migration) => documentedLogGaps.get(migration))
        .filter(Boolean);
      const unexpectedEntries = untrackedInsideWindow.filter((migration) => !documentedLogGaps.has(migration));

      if (documentedEntries.length > 0) {
        passes.push(
          `migrations_applied.log: lacunas historicas documentadas (${documentedEntries.length}; ${summarizeStatuses(documentedEntries)})`,
        );
      }

      if (unexpectedEntries.length > 0) {
        warnings.push(
          `migrations_applied.log: existem migrations do intervalo rastreado sem entrada no log (${summarizeList(unexpectedEntries)})`,
        );
      }
    } else {
      passes.push('migrations_applied.log: intervalo rastreado sem lacunas aparentes');
    }
  }
}

const applyMigrationPath = relativePath('database', 'apply_migration.bat');
if (fs.existsSync(applyMigrationPath)) {
  const applyMigrationScript = readText('database', 'apply_migration.bat');
  for (const token of ['migrations_applied.log', 'schema_current.sql', 'dump_history.log']) {
    if (!applyMigrationScript.includes(token)) {
      failures.push(`apply_migration.bat: nao atualiza explicitamente ${token}`);
    }
  }
  if (!failures.some((item) => item.startsWith('apply_migration.bat:'))) {
    passes.push('apply_migration.bat: atualiza log, baseline e historico de dump');
  }
}

const dumpSchemaPath = relativePath('database', 'dump_schema.bat');
if (fs.existsSync(dumpSchemaPath)) {
  const dumpSchemaScript = readText('database', 'dump_schema.bat');
  for (const token of ['schema_current.sql', 'dump_history.log']) {
    if (!dumpSchemaScript.includes(token)) {
      failures.push(`dump_schema.bat: nao atualiza explicitamente ${token}`);
    }
  }
  if (!failures.some((item) => item.startsWith('dump_schema.bat:'))) {
    passes.push('dump_schema.bat: atualiza baseline e historico de dump');
  }
}

const schemaRealPath = relativePath('database', 'schema_real.sql');
if (fs.existsSync(schemaRealPath)) {
  const schemaRealContent = readText('database', 'schema_real.sql');
  if (!/DO NOT USE AS BASELINE/i.test(schemaRealContent)) {
    failures.push('schema_real.sql: o aviso de nao uso como baseline nao foi encontrado');
  } else {
    passes.push('schema_real.sql: aviso de nao uso como baseline presente');
  }
}

const runbookPath = relativePath('docs', 'runbook_local.md');
if (fs.existsSync(runbookPath)) {
  const runbookContent = readText('docs', 'runbook_local.md');
  for (const token of ['schema_current.sql', 'apply_migration.bat', 'dump_schema.bat', 'auditoria_prontidao_operacional_2026_04_09.md', 'definition_of_ready_ambiente_v1.md', 'check_schema_drift_replay.mjs', 'drift_replay_manifest.json', 'migration_history_registry.json']) {
    if (!runbookContent.includes(token)) {
      failures.push(`runbook_local.md: referencia obrigatoria ausente para ${token}`);
    }
  }
  if (!failures.some((item) => item.startsWith('runbook_local.md:'))) {
    passes.push('runbook_local.md: referencias canonicas de governanca presentes');
  }
}

const definitionOfReadyPath = relativePath('docs', 'definition_of_ready_ambiente_v1.md');
if (fs.existsSync(definitionOfReadyPath)) {
  const definitionOfReadyContent = readText('docs', 'definition_of_ready_ambiente_v1.md');
  for (const token of ['048_ai_tenant_isolation_hardening.sql', 'check_database_governance.mjs', 'check_schema_drift_replay.mjs', 'drift_replay_manifest.json', 'runbook_local.md', 'progresso18.md']) {
    if (!definitionOfReadyContent.includes(token)) {
      failures.push(`definition_of_ready_ambiente_v1.md: referencia obrigatoria ausente para ${token}`);
    }
  }
  if (!failures.some((item) => item.startsWith('definition_of_ready_ambiente_v1.md:'))) {
    passes.push('definition_of_ready_ambiente_v1.md: gate global e referencias obrigatorias presentes');
  }
}

const pullRequestTemplatePath = relativePath('.github', 'pull_request_template.md');
if (fs.existsSync(pullRequestTemplatePath)) {
  const pullRequestTemplateContent = readText('.github', 'pull_request_template.md');
  for (const token of ['auth', 'organizer scope', 'audit trail', 'idempotencia', 'docs/progresso18.md']) {
    if (!pullRequestTemplateContent.includes(token)) {
      failures.push(`pull_request_template.md: checklist obrigatorio incompleto, faltando ${token}`);
    }
  }
  if (!failures.some((item) => item.startsWith('pull_request_template.md:'))) {
    passes.push('pull_request_template.md: checklist obrigatorio da Sprint 1 presente');
  }
}

console.log('== Governanca de banco / Sprint 1 ==');
for (const message of passes) {
  console.log(`OK   ${message}`);
}
for (const message of warnings) {
  console.log(`WARN ${message}`);
}
for (const message of failures) {
  console.log(`FAIL ${message}`);
}

if (failures.length > 0) {
  console.error(`\nFalhas encontradas: ${failures.length}`);
  process.exit(1);
}

console.log(`\nSem falhas. Warnings: ${warnings.length}`);
