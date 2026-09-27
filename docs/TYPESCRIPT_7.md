# TypeScript 7 baseline

Nexora Commerce 0.8 uses TypeScript 7.0.2.

TypeScript 7 is the native Go implementation and is now the stable `typescript` package. The project uses an explicit `tsconfig.json` because TypeScript 7 changed several defaults, including strict mode, module defaults, rootDir behavior and global `types` discovery.

Vue SFC checking uses vue-tsc 3.3.11, a release after Vue Language Tools moved its workspace/tooling toward the TypeScript 7 native bridge. Release QA must run both `npm run typecheck` and `npm run build`; a TypeScript/Vue tooling update is never accepted based only on package version numbers.
