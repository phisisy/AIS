# Engineering operating guide

## Scope and current project state

This file governs engineering and code review work throughout this workspace.
Read any more specific `AGENTS.md` files before changing files in their directories.

Initial inspection on 2026-11-17 found an empty workspace with no Git repository,
source files, dependency manifests, coding conventions, or automated tests.
No framework, architecture, package manager, or test command is established yet.
Update this guide with verified project details as the project takes shape; do
not treat a proposed technology or command as an existing project convention.

## Operating rules

- Follow the existing project architecture. Before editing, inspect the relevant
  modules, entry points, interfaces, configuration, and nearby implementations.
  Preserve established boundaries, naming, formatting, and dependency patterns.
  Where no architecture exists, agree on the necessary foundation as part of the
  approved feature rather than inventing a broader system.
- Implement only approved features. Treat an explicit user request as approval
  for its stated scope and necessary implementation details. Ask for clarification
  when requirements are ambiguous or a change would materially expand that scope.
  Avoid unrelated refactors, dependency upgrades, or speculative functionality.
- Delegate independent tasks to specialized subagents when beneficial. Give each
  agent a bounded task, relevant context, clear file ownership, and acceptance
  criteria. The coordinating engineer remains responsible for integration,
  reviewing all changes, and validating the combined result.
- Follow secure programming practices. Validate untrusted input, enforce
  authorization at trust boundaries, use least privilege and parameterized
  queries, and handle errors without exposing sensitive information. Never commit
  credentials or log secrets. Review new dependencies and use the project's
  established dependency and lockfile workflow once one exists.
- Preserve existing functionality and public contracts. Inspect callers and
  affected tests before changing behavior. Keep compatibility unless a breaking
  change is explicitly approved, and document migration needs when applicable.
- Run relevant automated tests after implementing code. Use verified commands
  from project documentation, manifests, or CI configuration. Run applicable
  lint, type, and build checks where configured. Add focused tests for new behavior
  and bug fixes when meaningful; do not add tests merely to mirror implementation.
- Never delete production data or run destructive commands without explicit
  approval for the specific action and target. This includes destructive database
  operations, broad file deletion, discarding local changes, and rewriting shared
  Git history. Explain the impact and recovery approach before seeking approval.
- Use separate Git branches or worktrees for independent changes. Inspect Git
  status first, preserve unrelated work, and coordinate integration to avoid
  overlapping edits. If Git is not initialized, report that limitation; do not
  claim branch isolation or initialize version control solely to edit this guide.
- Report modified files, test results, identified risks, and incomplete work.
  Distinguish verified outcomes from assumptions and checks that could not run.

## Feature implementation workflow

1. **Analyze requirements.** Identify the approved scope, acceptance criteria,
   constraints, and unresolved questions. Resolve blocking ambiguity before
   implementing dependent behavior.
2. **Inspect relevant source files.** Read applicable guidance, project structure,
   dependency manifests and lockfiles, relevant code, callers, tests, and CI
   configuration. Determine the actual coding conventions and validation commands.
3. **Plan the smallest maintainable change.** Identify affected components,
   compatibility and security concerns, test coverage, and any migration needs.
   Establish branch or worktree isolation and delegate independent work when useful.
4. **Implement the feature.** Make focused changes within the approved scope,
   following existing architecture and conventions. Preserve unrelated user work.
5. **Run automated tests.** Run relevant tests and configured checks, resolve
   failures caused by the change, and record results. If tests or tooling are
   unavailable, state what could not be verified and why; never report unrun tests
   as passing.
6. **Review for security and regressions.** Inspect the complete diff, including
   delegated changes. Check trust boundaries, sensitive data handling, error paths,
   compatibility, dependency changes, and accidental edits or data loss.
7. **Summarize the result.** Explain the resulting behavior, list modified files,
   report checks and their outcomes, and identify remaining risks and incomplete
   work. Note any required follow-up or approval without claiming completion of
   work that remains outstanding.
