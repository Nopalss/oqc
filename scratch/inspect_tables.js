const fs = require('fs');

function inspectTable(filePath, tableName) {
  const content = fs.readFileSync(filePath, 'utf8');
  const regex = new RegExp(`CREATE TABLE\\s+\`?${tableName}\`?\\s*\\(([\\s\\S]*?)\\)\\s*ENGINE=([a-zA-Z0-9_]+)[^;]*;`, 'i');
  const m = content.match(regex);
  if (m) {
    return m[0];
  }
  return null;
}

const tablesToCheck = [
  'daily_inspection_data',
  'did_approval_requests',
  'did_batches',
  'inspection_session_lots',
  'inspection_sessions'
];

for (let t of tablesToCheck) {
  console.log(`\n================== TABLE: ${t} ==================`);
  console.log('--- LOCAL ---');
  console.log(inspectTable('oqc_db2.sql', t));
  console.log('--- SERVER ---');
  console.log(inspectTable('server.sql', t));
}

