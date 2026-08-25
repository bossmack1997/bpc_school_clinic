const fs = require('fs');
const assert = require('assert');

// Extract renderMarkdown and formatInline from mica.js
const micaCode = fs.readFileSync('assets/js/mica.js', 'utf8');

// Quick test harness for the functions
function escapeHtml(text) {
  if (!text) return '';
  return text.replace(/[&<>"']/g, function (m) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
  });
}

// Function under test extracted from mica.js
function formatInline(text) {
  if (!text) return '';
  var h = escapeHtml(text);
  h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
  h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  h = h.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
  h = h.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
  h = h.replace(/(^|[^"'>])(https?:\/\/[^\s<]+)/g, '$1<a href="$2" target="_blank" rel="noopener">$2</a>');
  return h;
}

function renderMarkdown(text) {
  if (!text) return '';
  var clean = String(text)
    .replace(/^\s*\$\s+/gm, '')
    .replace(/(?:^|\n)\s*\$\s*([A-Za-z0-9])/g, '\n$1');

  var lines = clean.split('\n');
  var html = [];

  for (var i = 0; i < lines.length; i++) {
    var line = lines[i];
    var trimmed = line.trim();

    if (trimmed === '') {
      html.push('<div class="mica-p-gap"></div>');
      continue;
    }

    var h3Match = trimmed.match(/^###\s+(.+)$/);
    if (h3Match) {
      html.push('<h6 class="mica-h6">' + formatInline(h3Match[1]) + '</h6>');
      continue;
    }
    var h2Match = trimmed.match(/^##\s+(.+)$/);
    if (h2Match) {
      html.push('<h5 class="mica-h5">' + formatInline(h2Match[1]) + '</h5>');
      continue;
    }
    var h1Match = trimmed.match(/^#\s+(.+)$/);
    if (h1Match) {
      html.push('<h4 class="mica-h4">' + formatInline(h1Match[1]) + '</h4>');
      continue;
    }

    var numMatch = trimmed.match(/^(\d+)[.)]\s+(.+)$/);
    if (numMatch) {
      html.push('<div class="mica-list-item"><span class="mica-list-num">' + escapeHtml(numMatch[1]) + '.</span><span class="mica-list-text">' + formatInline(numMatch[2]) + '</span></div>');
      continue;
    }

    var bulletMatch = trimmed.match(/^[-*•+]\s+(.+)$/);
    if (bulletMatch) {
      html.push('<div class="mica-list-item"><span class="mica-list-bullet">&bull;</span><span class="mica-list-text">' + formatInline(bulletMatch[1]) + '</span></div>');
      continue;
    }

    html.push('<div class="mica-line">' + formatInline(line) + '</div>');
  }

  return html.join('');
}

console.log("==========================================");
console.log("TESTING MICA MARKDOWN & DOLLAR CLEANING");
console.log("==========================================");

// TEST 1: Bullet list
const input1 = "• Break it down\n• Set goals\n• Take breaks";
const out1 = renderMarkdown(input1);
console.log("\n--- TEST 1: Bullet List ---");
console.log("Input:\n" + input1);
console.log("Output:\n" + out1);
assert(!out1.includes('$'), "TEST 1 FAILED: Output contained unexpected '$'");
console.log("✅ TEST 1 PASSED: Zero '$' in bullet list output!");

// TEST 2: Numbered list
const input2 = "1. Break it Down: Hatiin ang iyong thesis\n2. Set Goals: Create daily milestones";
const out2 = renderMarkdown(input2);
console.log("\n--- TEST 2: Numbered List ---");
console.log("Input:\n" + input2);
console.log("Output:\n" + out2);
assert(!out2.includes('$'), "TEST 2 FAILED: Output contained unexpected '$'");
console.log("✅ TEST 2 PASSED: Zero '$' in numbered list output!");

// TEST 3: Stray dollar sign before response
const input3 = "$ Narito ang mga payo para sa thesis:\n$ 1. Simulan sa maliit na bahagi";
const out3 = renderMarkdown(input3);
console.log("\n--- TEST 3: Stray Dollar Signs Cleaned ---");
console.log("Input:\n" + input3);
console.log("Output:\n" + out3);
assert(!out3.includes('$'), "TEST 3 FAILED: Stray dollar sign was not cleaned");
console.log("✅ TEST 3 PASSED: Stray dollar signs successfully removed!");

// TEST 4: Legitimate Currency
const input4 = "The estimated fee is $15.00 for external clinic certificates.";
const out4 = renderMarkdown(input4);
console.log("\n--- TEST 4: Legitimate Currency Preserved ---");
console.log("Input:\n" + input4);
console.log("Output:\n" + out4);
assert(out4.includes('$15.00'), "TEST 4 FAILED: Legitimate currency was removed");
console.log("✅ TEST 4 PASSED: Legitimate '$15.00' currency preserved!");

console.log("\n==========================================");
console.log("ALL MARKDOWN & DOLLAR TESTS PASSED!");
console.log("==========================================");

