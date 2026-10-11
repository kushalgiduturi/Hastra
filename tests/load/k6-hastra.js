// Hastra load test (k6: https://k6.io, free and open source).
//
//   k6 run -e BASE=http://localhost/Hastra tests/load/k6-hastra.js
//   k6 run -e BASE=https://staging.example.com -e PROFILE=soak tests/load/k6-hastra.js
//
// Do NOT point this at the free Render + Aiven production stack: a sustained
// run will exhaust its CPU allowance and connection limit for real visitors.
// Use a local copy or a paid staging copy.
//
// Scenarios
//   browse     anonymous visitors on public pages (landing, Labs, legal)
//   auth       the sign-in page and a wrong-password POST, which exercises the
//              CSRF check, the password hash and the IP lockout path (expect
//              429/"too many attempts" once the limiter engages: that is a pass)
//   search     the Ctrl+K index endpoint (MySQL sliding-window limited to
//              60/min per IP, so 429s are expected and counted separately)
//   telemetry  consent-gated analytics and error intake (204, or 429 when limited)
//
// Hastra has no vector database, so there is no vector-search scenario.
import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE = (__ENV.BASE || 'http://localhost/Hastra').replace(/\/$/, '');
const PROFILE = __ENV.PROFILE || 'smoke';

const limited = new Rate('rate_limited');   // share of requests answered 429 (by design)
const pageTtfb = new Trend('page_ttfb', true);

const profiles = {
  smoke: { browse: [{ duration: '30s', target: 5 }], auth: 2, search: 2, telemetry: 2, dur: '30s' },
  load:  { browse: [{ duration: '1m', target: 25 }, { duration: '3m', target: 25 }, { duration: '30s', target: 0 }], auth: 5, search: 5, telemetry: 5, dur: '4m' },
  soak:  { browse: [{ duration: '2m', target: 15 }, { duration: '30m', target: 15 }, { duration: '1m', target: 0 }], auth: 3, search: 3, telemetry: 3, dur: '33m' },
  spike: { browse: [{ duration: '20s', target: 5 }, { duration: '20s', target: 120 }, { duration: '1m', target: 120 }, { duration: '20s', target: 5 }], auth: 10, search: 10, telemetry: 10, dur: '2m' },
};
const P = profiles[PROFILE] || profiles.smoke;

export const options = {
  scenarios: {
    browse:    { executor: 'ramping-vus', stages: P.browse, exec: 'browse' },
    auth:      { executor: 'constant-vus', vus: P.auth, duration: P.dur, exec: 'auth' },
    search:    { executor: 'constant-vus', vus: P.search, duration: P.dur, exec: 'search' },
    telemetry: { executor: 'constant-vus', vus: P.telemetry, duration: P.dur, exec: 'telemetry' },
  },
  thresholds: {
    http_req_failed: ['rate<0.02'],                         // 429s are marked expected below, so they don't count
    'http_req_duration{scenario:browse}': ['p(95)<800'],
    'http_req_duration{scenario:auth}': ['p(95)<1500'],     // password hashing is deliberately slow
    'http_req_duration{scenario:search}': ['p(95)<300'],
    page_ttfb: ['p(95)<600'],
  },
};
http.setResponseCallback(http.expectedStatuses({ min: 200, max: 399 }, 429));

const PAGES = ['/', '/labs/', '/labs/crypto', '/labs/siem', '/labs/syllabus', '/legal/privacy', '/legal/terms', '/signin', '/signup'];

export function browse() {
  const path = PAGES[Math.floor(Math.random() * PAGES.length)];
  const r = http.get(BASE + path, { tags: { name: path } });
  pageTtfb.add(r.timings.waiting);
  check(r, { 'page 200': (x) => x.status === 200, 'has <title>': (x) => /<title>[^<]+<\/title>/.test(x.body || '') });
  sleep(1 + Math.random() * 2);
}

export function auth() {
  group('sign-in', () => {
    const page = http.get(BASE + '/signin', { tags: { name: '/signin' } });
    const csrf = (page.body || '').match(/name="csrf_token" value="([a-f0-9]+)"/);
    if (!csrf) return;
    const r = http.post(BASE + '/signin', {
      csrf_token: csrf[1], email: `loadtest+${__VU}@example.invalid`, password: 'wrong-password-for-load-test', portal: 'client',
    }, { tags: { name: 'POST /signin' }, redirects: 0 });
    limited.add(r.status === 429 || /too many attempts/i.test(r.body || ''));
    check(r, { 'sign-in answered': (x) => x.status < 500 });
  });
  sleep(2);
}

export function search() {
  const r = http.get(BASE + '/api/search_index.php', { tags: { name: 'search_index' } });
  limited.add(r.status === 429);
  check(r, { 'search ok or limited': (x) => x.status === 200 || x.status === 429 });
  sleep(0.5);
}

export function telemetry() {
  const headers = { 'Content-Type': 'application/json' };
  const a = http.post(BASE + '/api/track.php', JSON.stringify({ event: 'pageview', path: '/labs/', utm: { utm_source: 'k6' } }), { headers, tags: { name: 'track' } });
  const e = http.post(BASE + '/api/client_error.php', JSON.stringify({ message: 'k6 synthetic error', location: '/k6.js:1:1' }), { headers, tags: { name: 'client_error' } });
  limited.add(a.status === 429 || e.status === 429);
  check(a, { 'track 204/429': (x) => x.status === 204 || x.status === 429 });
  sleep(1);
}
