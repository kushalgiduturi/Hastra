# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: the delivery team at a software agency (admins and employees) who live in the portals daily, moving client requirements into projects, tasks, testing, deployments and invoices. Sysadmins run the platform itself (roles, logs, insights, migrations, security).

Secondary: client companies and individual clients, who visit less often to submit or revise requirements, approve milestones, pay invoices and receive deliveries.

Also: visitors to the public landing page, auth pages, legal pages and Hastra Labs (free security learning tools).

## Product Purpose

Hastra gives a software delivery company one connected workspace for client work: versioned requirements, projects and tasks, testing and bugs, deployments, dual-key milestone sign-off, billing, and encrypted one-time handover of credentials. Success means a delivery runs from requirement to signed-off, paid and handed over without details or secrets leaking into chat threads and spreadsheets.

## Positioning

Secure by default. Field-level encryption (AES-256-GCM with blind indexes), a hash-chained tamper-evident audit log, one-time credential dossiers that wipe after viewing, dual-key sign-off, honeytokens and VPN-aware sign-in are part of the core flow, not add-ons. Surfaces should make that protection legible and trustworthy without turning every screen into a security dashboard.

## Operating Context

- Four account paths: full organization, solo developer or studio, client company, individual client. Roles: sysadmin, admin, employee, client (client roles include PM and IT manager).
- Sign-in is email and password plus emailed OTP, or Google. reCAPTCHA on sign-in.
- Server-rendered PHP 8 pages; the visual layer sits over a shared WebGL temple scene that runs behind every themed page.
- Two themes are part of the product: dark (white type, crimson #e50914) and light (black type, cobalt #2563eb).

## Capabilities and Constraints

- Plain PHP 8 + MySQL, no framework, no build step; XAMPP locally, Docker on Render in production. edge/ is a separate Cloudflare Pages + React pilot.
- CSP allows only self and cdn.jsdelivr.net for scripts and styles; fonts are self-hosted (Onest, Share Tech Mono). No new external requests.
- The employee portal folder is spelled `portals/emlpoyee/` and must stay that way.
- Stage: real product, pre-launch. Being hardened and deployed; no paying customers yet.

## Brand Commitments

- Name: Hastra. The temple world (landing-pages/hastra.html scene) is part of the identity.
- Dark crimson and light cobalt are the two brand accents; keep the hue identity.
- No em or en dashes anywhere in copy, comments or docs.

## Evidence on Hand

- Product description: HASTRA_OVERVIEW.md, HASTRA_ARCHITECTURE_AND_EXECUTION_GUIDE.md, README.md.
- No customers, testimonials, usage numbers or case studies exist yet. Do not invent them.

## Product Principles

1. Protection is visible but quiet: make secure states (verified, encrypted, signed off, wiped) clear at a glance, never alarming by default.
2. The delivery team's daily speed comes first: scanability and consistency over spectacle on Operate surfaces.
3. Both themes are first-class and designed together.
4. Nothing leaves the site: no third-party requests, no tracking without consent.

## Accessibility & Inclusion

WCAG 2.1 AA: 4.5:1 text contrast computed against the worst-case backdrop, a visible 2px focus ring, 44px touch targets, skip link, full keyboard parity, and honoured reduced-motion, reduced-transparency and low-performance modes.
