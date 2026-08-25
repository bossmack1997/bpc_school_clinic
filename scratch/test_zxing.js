const fs = require('fs');
const code = fs.readFileSync('assets/js/html5-qrcode.min.js', 'utf8');

// Let's inspect ZXing / Html5Qrcode in the script
console.log('Searching ZXing references...');
const zxMatches = code.match(/ZXing[a-zA-Z0-9_]*/g);
console.log('ZXing classes:', Array.from(new Set(zxMatches || [])));

const html5Matches = code.match(/Html5Qrcode[a-zA-Z0-9_]*/g);
console.log('Html5Qrcode classes:', Array.from(new Set(html5Matches || [])));

