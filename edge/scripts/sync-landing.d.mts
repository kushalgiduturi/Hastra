// Types for sync-landing.mjs, so tests/pilot/landing.test.ts compiles under `tsc -b`.
export declare const PHP_APP_URL: string;
export declare const LANDING_ASSETS: string[];
export declare function rewriteLanding(html: string): string;
export declare function syncLanding(): void;
