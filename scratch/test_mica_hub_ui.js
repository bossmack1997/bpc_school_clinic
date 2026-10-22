const fs=require('fs'),vm=require('vm'),assert=require('assert');
const php=fs.readFileSync('pages/ai_assistant.php','utf8');
const code=php.slice(php.indexOf('<script>')+8,php.lastIndexOf('</script>')).replace(/<\?=[\s\S]*?\?>/g,'"admin"');
new vm.Script(code);
assert(!code.includes('onclick="showActionMonitoring(${JSON.stringify'));
assert(code.includes('if (micaScanRunning) return'));
assert(code.includes(".always(function(){micaScanRunning=false;})"));
assert(code.includes('escapeHtml(a.navigation_url)'));
assert(!code.includes('app-card h-100'));
console.log('PASS hub JavaScript parses; record strings are not inline handlers; scan guard resets; detail URL escaped; content-height cards.');

const escapeSource=code.slice(code.indexOf('function escapeHtml('),code.indexOf('function timeAgo('));
const ctx={}; vm.createContext(ctx);vm.runInContext(escapeSource,ctx);
assert.strictEqual(ctx.escapeHtml(0),'0');
assert.strictEqual(ctx.escapeHtml('<img src=x onerror=alert(1)>'),'&lt;img src=x onerror=alert(1)&gt;');
assert(code.includes('titleText:a.title'));
console.log('PASS zero displays as zero; hostile markup is escaped; modal uses text titles.');
