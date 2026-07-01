# Blueprints

Blueprints are design documents for work that is larger than a single change —
significant refactors, architectural decisions, and proposed future
enhancements. They let us see what is planned (and why) before code is written.

## Structure

```
blueprints/
├── 0001-enterprise-restructure/   # numbered: a significant, decided undertaking
├── enhancements/                  # proposed future work, one folder each
│   ├── di-container-service-layer/
│   ├── full-test-coverage/
│   ├── rest-controller-completion/
│   ├── data-driven-settings/
│   ├── base-framework-standards/
│   └── wporg-runtime-autoloader/
└── README.md
```

- **Numbered blueprints** (`000N-title/`) capture a substantial piece of work or
  a decision that has been made and (usually) carried out. Start one for any
  multi-step effort so the intent and trade-offs are recorded.
- **[`enhancements/`](enhancements/)** holds proposals that are designed but not
  yet scheduled. Each is a self-contained folder with a `README.md`.

## Conventions

Each blueprint is a folder containing at least a `README.md`. A blueprint states
its **Status** (`Proposed` → `Accepted` → `Implemented`), the problem, the
proposed approach, migration/compatibility, risks, testing, and a rollout plan.
Cross-reference related blueprints with `[[slug]]` links.

## Index

| Blueprint | Status | About |
| --- | --- | --- |
| [0001 — Enterprise restructure](0001-enterprise-restructure/README.md) | Implemented | Repo reorg, tooling, packaging, standards pass (this branch). |
| [Enhancements →](enhancements/README.md) | Proposed | Roadmap of six designed-but-unscheduled improvements. |
