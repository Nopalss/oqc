const fs = require('fs');

function checkUniqueAndFk(filePath) {
  const content = fs.readFileSync(filePath, 'utf8');
  const uqRegex = /ADD UNIQUE KEY\s+`?([a-zA-Z0-9_]+)`?\s*\(([\s\S]*?)\)/gi;
  const fkRegex = /ADD CONSTRAINT\s+`?([a-zA-Z0-9_]+)`?\s+FOREIGN KEY[\s\S]*?REFERENCES[\s\S]*?(?=\,|\;)/gi;
  
  let uqs = [];
  let m;
  while ((m = uqRegex.exec(content)) !== null) {
    uqs.push(m[0]);
  }
  let fks = [];
  while ((m = fkRegex.exec(content)) !== null) {
    fks.push(m[0]);
  }
  return { uqs, fks };
}

const localAdv = checkUniqueAndFk('oqc_db2.sql');
const serverAdv = checkUniqueAndFk('server.sql');

console.log('Unique keys diff:');
console.log('Local only:', localAdv.uqs.filter(x => !serverAdv.uqs.includes(x)));
console.log('Server only:', serverAdv.uqs.filter(x => !localAdv.uqs.includes(x)));

console.log('\nFK diff:');
console.log('Local only:', localAdv.fks.filter(x => !serverAdv.fks.includes(x)));
console.log('Server only:', serverAdv.fks.filter(x => !localAdv.fks.includes(x)));

