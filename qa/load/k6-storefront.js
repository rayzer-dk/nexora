import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  vus: Number(__ENV.VUS || 10),
  duration: __ENV.DURATION || '30s',
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<700'],
  },
};

const base = (__ENV.BASE_URL || '').replace(/\/$/, '');
if (!base) throw new Error('BASE_URL is required');

export default function () {
  for (const path of ['/', '/catalog', '/api/v1/catalog/products?limit=24']) {
    const response = http.get(base + path, { redirects: 0, tags: { path } });
    check(response, { 'HTTP status is not 5xx': r => r.status < 500 });
  }
  sleep(0.2);
}
