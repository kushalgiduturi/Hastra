---
name: awesome-design-md
description: Reference the design language of a well-known product (Airbnb, Apple, Binance, BMW, Claude, and 70+ others) — colors, type, spacing, motion, voice — when matching or drawing inspiration from a specific brand's look and feel. Use when asked to design something "like X" for a named company, or to check how a known product handles a particular UI pattern.
---

# Awesome Design MD

A curated collection of `DESIGN.md` files, one per company, each describing that
product's visual and interaction design system in prose + tokens: color
palette, typography scale, spacing, motion curves, component conventions, and
voice/tone. Source: https://github.com/upstream/awesome-design-md (bundled
locally in `design-md/`, see `UPSTREAM-README.md` for the full catalog and
license).

## When to use this

- The user asks for something styled "like Airbnb" / "in Apple's style" / etc.
- The user asks how a specific company handles a design decision (e.g. "how
  does Stripe do empty states?") and that company has a `design-md/<name>/`
  entry.
- Before starting an unfamiliar brand-matching task, check if a relevant
  `DESIGN.md` exists here rather than guessing from memory.

## How to use it

1. List available companies: `ls .claude/skills/awesome-design-md/design-md`
2. Read the specific one: `.claude/skills/awesome-design-md/design-md/<company>/DESIGN.md`
3. Pull concrete values (hex codes, font stacks, spacing units, easing
   curves) from it rather than paraphrasing from general knowledge — these
   files are the actual reference, general knowledge is not.
4. Cite which file informed a decision when it materially shaped the output,
   the same way you'd cite any other reference source.

This is reference material, not a template to copy verbatim — a real
product's exact CSS/tokens are still that product's, use them as grounding
for an original design, not as content to reproduce wholesale.
