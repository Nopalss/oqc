const fs = require('fs');

function parseSql(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  const regex = /CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`?([a-zA-Z0-9_]+)`?\s*\(([\s\S]*?)\)\s*ENGINE/gi;
  const tables = {};
  let match;
  while ((match = regex.exec(content)) !== null) {
    const tableName = match[1];
    const body = match[2];
    const lines = body.split('\n')
      .map(l => l.trim().replace(/,$/, ''))
      .filter(l => l && !l.startsWith('--'));
    tables[tableName] = lines;
  }
  return tables;
}

const local = parseSql('oqc_db2.sql');
const server = parseSql('server.sql');

console.log('Local tables count:', Object.keys(local).length);
console.log('Server tables count:', Object.keys(server).length);

const localKeys = Object.keys(local);
const serverKeys = Object.keys(server);

console.log('\nLocal tables:', localKeys);
console.log('\nServer tables:', serverKeys);

const missingInServer = localKeys.filter(k => !serverKeys.includes(k));
const missingInLocal = serverKeys.filter(k => !localKeys.includes(k));
console.log('\nTables in Local but NOT in Server:', missingInServer);
console.log('Tables in Server but NOT in Local:', missingInLocal);

