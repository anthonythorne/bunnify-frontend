# Security Policy

## Supported Versions

Security fixes are provided for the current `1.0.x` release line. Older versions
are not maintained — please update to the latest `1.0.x` before reporting.

| Version | Supported |
| ------- | --------- |
| 1.0.x   | ✅        |
| < 1.0   | ❌        |

## Reporting a Vulnerability

**Please do not report security issues in public GitHub issues, pull requests,
or discussions.** Public disclosure before a fix is available puts users at risk.

Report privately using either of the following:

- **GitHub private security advisory (preferred):** open a report at
  <https://github.com/anthonythorne/bunnify-frontend/security/advisories/new>.
- **Email:** athorne@thecode.co with a subject line beginning `[SECURITY]`.

### What to include

To help us triage quickly, please include as much of the following as you can:

- A clear description of the issue and its potential impact.
- The plugin version, PHP version, and WordPress version affected.
- Steps to reproduce, or a proof of concept.
- Any relevant configuration (e.g. `bunnify_hostname` value, active filters/hooks).
- Suggested remediation, if you have one.

## Response Timeline

- **Acknowledgement:** within 3 business days of your report.
- **Initial assessment:** within 7 business days, including whether the issue is
  accepted and a rough severity.
- **Fix and release:** we aim to ship a patched `1.0.x` release as soon as
  practical, prioritised by severity.

We will keep you informed of progress throughout.

## Coordinated Disclosure

We follow a coordinated disclosure process. Please give us reasonable time to
investigate and release a fix before any public disclosure. Once a fix is
released, we are happy to credit reporters who wish to be acknowledged. Thank
you for helping keep Bunnify Frontend and its users safe.
