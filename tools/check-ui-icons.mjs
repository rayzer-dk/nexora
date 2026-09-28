import fs from 'node:fs';
import path from 'node:path';

const roots = ['themes/default/templates', 'assets'];
const extensions = new Set(['.twig', '.vue', '.js', '.ts']);
const interactivePattern = /<(button|a)\b[^>]*>[\s\S]{0,180}?[◉☰⇄✎⚙▶■●○✕✔✓★☆×＋→↶−][\s\S]{0,180}?<\/\1>/giu;
const violations = [];

function walk(dir) {
  for (const name of fs.readdirSync(dir)) {
    const file = path.join(dir, name);
    const stat = fs.statSync(file);
    if (stat.isDirectory()) walk(file);
    else if (extensions.has(path.extname(file))) {
      const source = fs.readFileSync(file, 'utf8');
      const matches = source.match(interactivePattern);
      if (matches) violations.push({ file, matches: matches.slice(0, 5) });
    }
  }
}

for (const root of roots) walk(root);
if (violations.length) {
  console.error('Text-symbol UI icons found. Use Lucide via ui_icon() or @lucide/vue.');
  for (const item of violations) console.error(`- ${item.file}: ${item.matches.join(' | ')}`);
  process.exit(1);
}
console.log('Lucide-only interactive icon audit: OK');
