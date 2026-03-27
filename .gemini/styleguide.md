# Code Review Style Guide

## Project Context

This is a self-hosted update API server for WordPress plugins and themes, compatible with the Plugin Update Checker
library and Composer. It is a fork of `YahnisElsts/wp-update-server` with namespaced classes, versioned packages,
Composer repository support, and license authentication.

## Code Style

- Flag inline comments that merely restate what the code does instead of explaining intent or reasoning.
- Flag commented-out code.
- Do not flag docblocks — these may be required by coding standards even when the function is self-explanatory.
- Flag new code that duplicates existing functionality in the repository.
- Every PHP file must start with `declare(strict_types=1);` after the opening `<?php` tag.
- Prefer post-increment (`$var++`) over pre-increment (`++$var`).
- In namespaced code, fully qualify PHP native functions (`\strlen()`, `\in_array()`) for performance.
- PHP classes use the `Apermo\WpUpdateServer` namespace with PSR-4 autoloading from `src/`.

## File Operations

- Flag files that appear to be deleted and re-added as new files instead of being moved/renamed (losing git history).

## Build & Packaging

- Flag newly added files or directories that are missing from build/packaging configs (`.gitattributes`, CI workflows,
  etc.).

## Testing

- This project uses TDD: tests are written before implementation.
- If tests exist for a changed area, flag missing or insufficient test coverage for new/modified code.
- Unit tests go in `tests/Unit/`, integration tests in `tests/Integration/`.
- Test fixtures (ZIP files, config stubs) go in `tests/fixtures/`.

## Documentation

- If a change affects user-facing behavior, flag missing updates to README, CHANGELOG, or inline docblocks.
- CHANGELOG follows [Keep a Changelog](https://keepachangelog.com/) format.

## Commits

- This project uses Conventional Commits with a 50-char subject / 72-char body limit.
- Each commit should address a single concern.
- Types: feat, fix, docs, style, refactor, test, chore, perf.
