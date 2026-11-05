// Bounded k6 smoke test. Only an explicit staging hostname is allowed.
// k6 run -e NCG_STAGING_URL=https://stage.example.com/article/ -e NCG_LOAD_APPROVED=STAGING_ONLY tools/staging-load.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
const raw = __ENV.NCG_STAGING_URL || '';
const approved = __ENV.NCG_LOAD_APPROVED === 'STAGING_ONLY';
const host = /^https:\/\/([^/?#:]+)/i.exec(raw)?.[1] || '';
if (!approved || !/(^|[.-])stag(?:e|ing)([.-]|$)/i.test(host)) {
  throw new Error('Load test is staging-only: set NCG_LOAD_APPROVED=STAGING_ONLY and an HTTPS host containing stage or staging');
}
export const options = {
  scenarios: { cache_smoke: { executor: 'constant-vus', vus: 2, duration: '30s', gracefulStop: '5s' } },
  thresholds: { http_req_failed: ['rate<0.02'], http_req_duration: ['p(95)<1500'] },
  discardResponseBodies: true,
  userAgent: 'NCG-Staging-Synthetic-Monitor/1.0',
};
const ttfb = new Trend('ncg_ttfb_ms');
export default function () {
  const r = http.get(raw, { redirects: 0, timeout: '12s', tags: { test: 'anonymous-article' } });
  ttfb.add(r.timings.waiting);
  check(r, { 'HTTP 200': x => x.status === 200, 'no PHP error': x => x.status < 500 });
  sleep(2); // ~1 request/second total; deliberate, non-destructive staging smoke.
}
