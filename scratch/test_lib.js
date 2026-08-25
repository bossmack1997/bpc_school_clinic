const fs = require('fs');
const code = fs.readFileSync('assets/js/html5-qrcode.min.js', 'utf8');

// Check what Html5Qrcode uses for scanning and how its constructor works
console.log('Code length:', code.length);
console.log('Includes jsQR:', code.includes('jsQR'));
console.log('Includes zxing:', code.toLowerCase().includes('zxing'));
console.log('Includes BarcodeDetector:', code.includes('BarcodeDetector'));

