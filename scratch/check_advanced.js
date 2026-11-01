const fs = require('fs');

function analyzeFile(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  console.log(`=== ANALYZING ${filePath} ===`);
  
  // Triggers
  const triggers = content.match(/CREATE\s+(?:DEFINER=[^\s]+\s+)?TRIGGER[\s\S]*?END/gi) || [];
  console.log('Triggers count:', triggers.length);
  
  // Views
  const views = content.match(/CREATE\s+(?:OR REPLACE\s+)?(?:ALGORITHM=[^\s]+\s+)?VIEW[\s\S]*?;/gi) || [];
  console.log('Views count:', views.length);
  
  // Stored procedures / functions
  const procs = content.match(/CREATE\s+(?:DEFINER=[^\s]+\s+)?PROCEDURE[\s\S]*?END/gi) || [];
  console.log('Procedures count:', procs.length);

  // Foreign keys
  const fks = content.match(/CONSTRAINT\s+`?[a-zA-Z0-9_]+`?\s+FOREIGN KEY[\s\S]*?REFERENCES[\s\S]*?(?=\,|\)|\;)/gi) || [];
  console.log('Foreign keys count:', fks.length);
}

analyzeFile('oqc_db2.sql');
analyzeFile('server.sql');

