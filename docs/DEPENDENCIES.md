# Dependency policy

Certified runtime target: PHP 8.4–8.5.

Core policy:
- newest supported stable component compatible with the selected LTS framework line;
- security support and backward compatibility are more important than a short-lived major version used only for a higher number;
- dependencies are isolated behind interfaces where replacement is realistic.

Base:
- Symfony 8.1 stable
- Doctrine DBAL 4.4
- webonyx/graphql-php 15.x
- Google Auth Library for PHP
- Vue 3.5 + TypeScript
- Vite 8
- Pinia
- Chart.js
- Floating UI
- Lucide icons

Optional infrastructure:
- Redis
- Meilisearch or OpenSearch
- libvips preferred for high-throughput image transforms
- gRPC + protobuf for Google clients when supported by deployment
- object storage/CDN

The storefront prefers HTML, CSS and Web Components and loads JavaScript only for interactive islands.
