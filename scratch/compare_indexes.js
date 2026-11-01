const fs = require('fs');

function getIndexes(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  // Match ALTER TABLE `table` ADD ...
  const regex = /ALTER TABLE\s+`?([a-zA-Z0-9_]+)`?\s+([\s\S]*?);/gi;
  const res = {};
  let match;
  while ((match = regex.exec(content)) !== null) {
    const table = match[1];
    const body = match[2];
    if (!res[table]) res[table] = [];
    const parts = body.split('\n').map(p => p.trim().replace(/,$/, '')).filter(p => p);
    res[table].push(...parts);
  }
  return res;
}

const localIdx = getIndexes('oqc_db2.sql');
const serverIdx = getIndexes('server.sql');

console.log('Comparing ALTER TABLE index definitions:');
const tables = Array.from(new Set([...Object.keys(localIdx), ...Object.keys(serverIdx)]));

for (let t of tables) {
  const l = localIdx[t] || [];
  const s = serverIdx[t] || [];

  const lInS = l.filter(x => !s.includes(x));
  const sInL = s.filter(x => !l.includes(x));

  if (lInS.length > 0) {
    console.log(`\nTable ${t} has definitions in LOCAL not in SERVER:`);
    lInS.forEach(x => console.log('  + ' + x));
  }
  if (sInL.length > 0) {
    console.log(`\nTable ${t} has definitions in SERVER not in LOCAL:`);
    sInL.forEach(x => console.log('  - ' + x));
  }
}

