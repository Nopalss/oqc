const fs = require('fs');

function parseFull(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  
  // Parse Tables
  const tableRegex = /CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-zA-Z0-9_]+)`?\s*\(([\s\S]*?)\)\s*ENGINE=([a-zA-Z0-9_]+)[^;]*;/gi;
  const tables = {};
  let match;
  while ((match = tableRegex.exec(content)) !== null) {
    const tableName = match[1];
    const body = match[2];
    const engine = match[3];
    
    const lines = body.split('\n')
      .map(l => l.trim())
      .filter(l => l && !l.startsWith('--') && !l.startsWith('/*'));
      
    const columns = {};
    const primaryKey = [];
    const keys = [];
    const uniques = [];
    const foreignKeys = [];

    for (let rawLine of lines) {
      let line = rawLine.replace(/,$/, '');
      if (/^`?([a-zA-Z0-9_]+)`?\s+/i.test(line) && !/^(PRIMARY KEY|KEY|UNIQUE KEY|CONSTRAINT|FULLTEXT)/i.test(line)) {
        const colMatch = line.match(/^`?([a-zA-Z0-9_]+)`?\s+(.*)$/);
        if (colMatch) {
          columns[colMatch[1]] = colMatch[2].trim();
        }
      } else if (/^PRIMARY KEY\s*\((.*?)\)/i.test(line)) {
        const pkMatch = line.match(/^PRIMARY KEY\s*\((.*?)\)/i);
        primaryKey.push(pkMatch[1].trim());
      } else if (/^UNIQUE KEY\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i.test(line)) {
        const uqMatch = line.match(/^UNIQUE KEY\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i);
        uniques.push({ name: uqMatch[1], cols: uqMatch[2] });
      } else if (/^(?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i.test(line)) {
        const kMatch = line.match(/^(?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i);
        keys.push({ name: kMatch[1], cols: kMatch[2] });
      } else if (/^CONSTRAINT\s+`?([a-zA-Z0-9_]+)`?\s+FOREIGN KEY/i.test(line)) {
        foreignKeys.push(line);
      }
    }

    tables[tableName] = {
      columns,
      primaryKey,
      uniques,
      keys,
      foreignKeys
    };
  }

  // Also check ALTER TABLE statements (in phpMyAdmin dumps, indexes or constraints are often added via ALTER TABLE at the end!)
  const alterRegex = /ALTER TABLE\s+`?([a-zA-Z0-9_]+)`?\s+([\s\S]*?);/gi;
  while ((match = alterRegex.exec(content)) !== null) {
    const tableName = match[1];
    const alterBody = match[2];
    if (!tables[tableName]) {
      tables[tableName] = { columns: {}, primaryKey: [], uniques: [], keys: [], foreignKeys: [] };
    }
    const lines = alterBody.split('\n').map(l => l.trim().replace(/,$/, '')).filter(l => l);
    for (let line of lines) {
      if (/ADD PRIMARY KEY\s*\((.*?)\)/i.test(line)) {
        const m = line.match(/ADD PRIMARY KEY\s*\((.*?)\)/i);
        tables[tableName].primaryKey.push(m[1].trim());
      } else if (/ADD UNIQUE KEY\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i.test(line)) {
        const m = line.match(/ADD UNIQUE KEY\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i);
        tables[tableName].uniques.push({ name: m[1], cols: m[2] });
      } else if (/ADD (?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i.test(line)) {
        const m = line.match(/ADD (?:KEY|INDEX)\s+`?([a-zA-Z0-9_]+)`?\s*\((.*?)\)/i);
        tables[tableName].keys.push({ name: m[1], cols: m[2] });
      } else if (/ADD CONSTRAINT\s+`?([a-zA-Z0-9_]+)`?\s+FOREIGN KEY/i.test(line)) {
        tables[tableName].foreignKeys.push(line);
      }
    }
  }

  return tables;
}

const local = parseFull('oqc_db2.sql');
const server = parseFull('server.sql');

console.log('Comparing columns and definitions...\n');

const allTables = Array.from(new Set([...Object.keys(local), ...Object.keys(server)])).sort();

for (let tbl of allTables) {
  const lTbl = local[tbl];
  const sTbl = server[tbl];

  if (!lTbl) {
    console.log(`[TABLE] ${tbl} only in SERVER`);
    continue;
  }
  if (!sTbl) {
    console.log(`[TABLE] ${tbl} only in LOCAL`);
    continue;
  }

  // Check columns
  const lCols = Object.keys(lTbl.columns);
  const sCols = Object.keys(sTbl.columns);

  const missingInServer = lCols.filter(c => !sCols.includes(c));
  const missingInLocal = sCols.filter(c => !lCols.includes(c));

  if (missingInServer.length > 0) {
    console.log(`[${tbl}] Columns in LOCAL but MISSING IN SERVER:`);
    missingInServer.forEach(c => console.log(`   + ${c} ${lTbl.columns[c]}`));
  }
  if (missingInLocal.length > 0) {
    console.log(`[${tbl}] Columns in SERVER but NOT IN LOCAL:`);
    missingInLocal.forEach(c => console.log(`   - ${c} ${sTbl.columns[c]}`));
  }

  // Check column definitions difference
  const commonCols = lCols.filter(c => sCols.includes(c));
  for (let c of commonCols) {
    const lDef = lTbl.columns[c].toLowerCase().replace(/\s+/g, ' ');
    const sDef = sTbl.columns[c].toLowerCase().replace(/\s+/g, ' ');
    if (lDef !== sDef) {
      console.log(`[${tbl}.${c}] TYPE/DEF DIFFERENCE:`);
      console.log(`   Local : ${lTbl.columns[c]}`);
      console.log(`   Server: ${sTbl.columns[c]}`);
    }
  }

  // Check Indexes / Keys
  // Compare Keys
  const lKeyNames = lTbl.keys.map(k => k.name);
  const sKeyNames = sTbl.keys.map(k => k.name);
  const keysMissingInServer = lTbl.keys.filter(k => !sKeyNames.includes(k.name));
  const keysMissingInLocal = sTbl.keys.filter(k => !lKeyNames.includes(k.name));

  if (keysMissingInServer.length > 0) {
    console.log(`[${tbl}] INDEXES in LOCAL but MISSING IN SERVER:`);
    keysMissingInServer.forEach(k => console.log(`   + KEY ${k.name} (${k.cols})`));
  }
  if (keysMissingInLocal.length > 0) {
    console.log(`[${tbl}] INDEXES in SERVER but NOT IN LOCAL:`);
    keysMissingInLocal.forEach(k => console.log(`   - KEY ${k.name} (${k.cols})`));
  }

  // Check Uniques
  const lUqNames = lTbl.uniques.map(u => u.name);
  const sUqNames = sTbl.uniques.map(u => u.name);
  const uqMissingInServer = lTbl.uniques.filter(u => !sUqNames.includes(u.name));
  const uqMissingInLocal = sTbl.uniques.filter(u => !lUqNames.includes(u.name));
  if (uqMissingInServer.length > 0) {
    console.log(`[${tbl}] UNIQUE KEYS in LOCAL but MISSING IN SERVER:`);
    uqMissingInServer.forEach(u => console.log(`   + UNIQUE ${u.name} (${u.cols})`));
  }
  if (uqMissingInLocal.length > 0) {
    console.log(`[${tbl}] UNIQUE KEYS in SERVER but NOT IN LOCAL:`);
    uqMissingInLocal.forEach(u => console.log(`   - UNIQUE ${u.name} (${u.cols})`));
  }
}

