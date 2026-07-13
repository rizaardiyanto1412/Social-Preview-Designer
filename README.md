# WP Remote OG Images

Contributors: wpremotework
Tags: open graph, social image, dynamic image, rank math
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

## Planning Docs

This folder contains the planning documents for building the **WP Remote Work Dynamic OG Image Plugin**.

## Recommended plugin path

Copy these markdown files into:

```bash
/Users/rizaardiyanto/Cove/Sites/dev.localhost/public/wp-content/plugins/wp-remote-og-plugins
```

Suggested command from your machine after unzipping this bundle:

```bash
cp SPEC.md PLAN.md GOAL.md IMPLEMENTATION_LOG.md ACCEPTANCE_CHECKLIST.md README.md \
  /Users/rizaardiyanto/Cove/Sites/dev.localhost/public/wp-content/plugins/wp-remote-og-plugins/

mkdir -p /Users/rizaardiyanto/Cove/Sites/dev.localhost/public/wp-content/plugins/wp-remote-og-plugins/reference-screens
```

## How to use these files

Use `GOAL.md` as the short instruction prompt for the coding agent.

Use `SPEC.md` as the source of truth for product requirements and architecture decisions.

Use `PLAN.md` as the milestone-by-milestone implementation plan.

Use `IMPLEMENTATION_LOG.md` to record progress, test results, blockers, and deviations.

Use `ACCEPTANCE_CHECKLIST.md` for final QA before considering v1 complete.

Use `/reference-screens/` for screenshots of desired admin/editor UI, if available.

## Important instruction

Do not start coding from memory alone. The implementation should read `SPEC.md` and `PLAN.md` first, then proceed milestone by milestone.
