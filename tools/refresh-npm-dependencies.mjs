// Resolve newer stable releases within existing major versions, preserving exact pins.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';

const pkg = JSON.parse(readFileSync('package.json', 'utf8'));
let outdated;
try {
  outdated = JSON.parse(execFileSync('npm', ['outdated', '--json'], { encoding: 'utf8' }) || '{}');
} catch (error) {
  if (error.status !== 1 || !error.stdout) throw error;
  outdated = JSON.parse(error.stdout);
  if (outdated.error) throw error;
}
const stable = /^\d+\.\d+\.\d+$/;
const compare = (a, b) => {
  const aa = a.split('.').map(Number);
  const bb = b.split('.').map(Number);
  return aa[0] - bb[0] || aa[1] - bb[1] || aa[2] - bb[2];
};
const plans = [];
for (const section of ['dependencies', 'devDependencies']) {
  for (const [name, current] of Object.entries(pkg[section] ?? {})) {
    const latest = outdated[name]?.latest;
    if (!stable.test(current) || typeof latest !== 'string' || !stable.test(latest)) continue;
    if (current.split('.')[0] !== latest.split('.')[0] || compare(latest, current) <= 0) continue;
    plans.push({ section, name, current, latest });
  }
}
// Tiptap's packages must remain aligned; skip the group if a release is incomplete.
const tiptap = Object.keys(pkg.dependencies).filter(name => name.startsWith('@tiptap/'));
const tiptapPlans = plans.filter(plan => tiptap.includes(plan.name));
const aligned = tiptapPlans.length === tiptap.length && new Set(tiptapPlans.map(plan => plan.latest)).size === 1;
for (const plan of plans) {
  if (tiptap.includes(plan.name) && !aligned) continue;
  pkg[plan.section][plan.name] = plan.latest;
  console.log(plan.name + ': ' + plan.current + ' -> ' + plan.latest);
}
writeFileSync('package.json', JSON.stringify(pkg, null, 2) + '\n');
