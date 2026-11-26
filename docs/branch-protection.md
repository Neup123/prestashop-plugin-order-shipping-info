# Protect the verified main branch

This procedure is for a repository administrator. The CI workflow files create checks but do **not** enforce required review or prevent direct pushes on their own.

## Observed status and evidence

On 26 November 2026, GitHub's public branch API reported `main.protected=false`, and the repository rulesets list was empty. The connected automation does not have permission to configure branch protection: its administrative endpoint returned 403, and there is no branch-protection write action available. **The settings described here have not been applied.** Issue [#142](../issues/142) stays open until an administrator applies and independently verifies them.

At current `main`, the following workflows run on both `pull_request` and pushes to `main`:

| Workflow | File | Required check context |
| --- | --- | --- |
| CI (JavaScript syntax) | `.github/workflows/ci.yml` | `javascript-syntax` |
| CI (PHP 8.2) | `.github/workflows/ci.yml` | `php (8.2)` |
| CI (PHP 8.4) | `.github/workflows/ci.yml` | `php (8.4)` |
| Module ZIP | `.github/workflows/package.yml` | `package` |
| PrestaShop 9.2 install | `.github/workflows/prestashop-install.yml` | `install` |
| Browser admin acceptance | `.github/workflows/browser-admin.yml` | `browser` |

The **latest SHA of the PR under review** must carry successful results for all six contexts, not merely an earlier SHA or a different branch. The four workflow names are not the same as the six job contexts.

## Administrator action — GitHub UI

1. Open the repository **Settings → Rules → Rulesets** (or **Settings → Branches → Branch protection rules** on accounts without repository rulesets). Create a branch ruleset targeting exactly `main`.
2. Require a pull request before merging, with at least one independent approval. Dismiss stale approvals when new commits are pushed; do not allow self-approval to satisfy the independent-review requirement.
3. Require status checks to pass before merge. Select the six **job contexts** above, as reported by a recent GitHub Actions PR. Use the expected GitHub Actions source if GitHub offers a source selector.
4. Require the PR to be up to date with the protected branch (or use an explicitly verified merge queue). Avoid weakening checks through a broad bypass list.
5. Block direct pushes and force pushes to `main`; block deletion of `main`. Restrict bypass actors to a separately reviewed emergency procedure and audit use of any bypass. Ensure the rule is **active/enforced**, not Evaluate-only.
6. Preserve existing CI workflow permissions (`contents: read`) and keep release/deployment access separate from merge verification.

## Independent verification (required before closing #142)

- Re-read GitHub's branch/ruleset view: the rule must target `main` and be **active**, with the six exact checks, required review and restricted direct pushes. A ruleset listed but in evaluation mode is not enforcement.
- Use a disposable test PR: a branch whose required check is deliberately pending or failing must be **blocked** from merging; after checks succeed and required review exists, merge should be allowed. Do not deliberately break the live `main` branch.
- Confirm `CI`, `Module ZIP`, `PrestaShop 9.2 install` and `Browser admin acceptance` all run and succeed again on the resulting `main` commit. Do not infer that a green PR alone protects `main`.
- Record rule identifier, non-sensitive screenshots/settings evidence, test PR link, and exact run URLs in issue #142. Only then close it.

## Scope limits

This file documents **how** to enforce gates; it is not a substitute for GitHub administration rights. CI success does not certify live freight tariffs, merchant integrations, original demo state or real payment handling. Never bypass required checks simply because a change is documentation-only.
