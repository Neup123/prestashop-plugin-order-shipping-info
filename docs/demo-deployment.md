# Demo module promotion and rollback

This procedure is for an authorized disposable/test PrestaShop shop. It is not a production release authorization, and demo fares are not real freight tariffs.

## Deployment boundary

The Coolify-hosted PrestaShop demo runs the `prestashop/prestashop:9.2.x-apache` image, independently of this module's Git repository. A read-only check on 2026-11-24 confirmed a healthy demo application and no existing scheduled tasks. A Coolify image redeployment alone does **not** install the GitHub module or establish its revision. Do not restart or rebuild the whole shop merely to promote a module ZIP. Exact current module bytes and database state must be checked inside PrestaShop before and after promotion.

Use the normal PrestaShop module upload/upgrade procedure documented in [Install and acceptance](INSTALL-AND-ACCEPTANCE.md). Never uninstall a working module just to update it: uninstall may affect data, carrier configuration or hooks.

## Release checklist

- [ ] Identify the target **main commit SHA**, module version from `grtransportshipping/grtransportshipping.php`, the existing installed version, and any intervening scripts in `grtransportshipping/upgrade/`. Do not promote an unmerged PR head as a release.
- [ ] Confirm all four required GitHub Actions workflows for the target commit: **CI**, **PrestaShop 9.2 install**, **Browser admin acceptance**, **Module ZIP**. Open the actual run and inspect its conclusion and SHA. A passing different commit is not sufficient.
- [ ] Download the `prestashop-grtransportshipping` artifact from the successful Module ZIP run, extract the artifact envelope and retain the inner `grtransportshipping.zip`. Verify the archive contains exactly one root module directory with `grtransportshipping/grtransportshipping.php`. Record the artifact's SHA-256 and the originating commit/run URL; do not use an untraceable local copy.
- [ ] Record current installed module version and the prior verified ZIP/commit, carrier status, representative route/network/order records, and pre-update health. Take a restorable backup of the existing shop database **and** module files/required volumes using the deployment's approved backup procedure. Confirm a restore path before changing anything. Keep backup contents outside Git; never export credentials or customer/order data into issues.
- [ ] Sign into the authorized demo's PrestaShop back office; open **Modules → Module Manager → Upload a module**, select the verified ZIP and follow the module-update/upgrade flow. For an existing installation, verify **Module Manager → Upgrade** has actually applied pending module migrations. If the uploader will not accept an existing module, stop and use the approved complete module-directory replacement path followed by Module Manager Upgrade; never uninstall, replace arbitrary files, or guess container paths. Do not mark success merely because files were uploaded.
- [ ] Verify installed module version, pending upgrades, module hooks/carrier status, and representative existing route/network/branch/order data. In particular verify new schema fields required by intervening migrations and that merchant data/snapshots were not reset.
- [ ] Run the acceptance checks below. On any unexpected failure, stop promotion, record the failed step and use the rollback decision path. Do not treat the old demo's data as disposable unless that specific data is authorized for testing.
- [ ] Record the completed target SHA, artifact checksum, installed version, migration result, acceptance evidence, backup reference (non-secret), time and operator in the deployment record. Recheck Coolify health. Clean up temporary tasks and any isolated test resources.

## Acceptance after update

Use fixtures and records reserved for testing. Preserve any existing merchant/test records not explicitly assigned for mutation.

| Area | Check |
| --- | --- |
| Module status | Configure opens, expected module version and upgrade completion, carrier available only for intended zones/groups |
| Flat routes | Read/edit/save/reload a disposable route; valid destination displays price and pickup notice; invalid destination does not offer transport |
| Network workspace | Select station/connection, place/move disposable station, save/reload, verify visible connection and cancel draft; use column chooser and verify controls |
| Dependency guards | Create disposable stations and link, reject deletion while genuinely linked, delete link then station; refresh create result must not duplicate |
| Map services | Visible basemap, overlays, saved geometry or visible fallback, meaningful routing failure notice; record provider outages separately |
| Simulator / public page | Address or station price, parcel/cart input where appropriate, public quote without JS, optional map pin and FAR confirmation; verify note privacy when that change is merged |
| Checkout / orders | Matched and unmatched Greek test addresses, pickup/FAR notice and frozen historical snapshot; no real payment capture |
| Persistence | Reload changed fixtures and restore/remove only those fixtures; compare representative preexisting records |

Use the checked-in disposable browser suite for repeatable acceptance, **never point CI scripts at a real merchant shop**. A named existing demo regression (for example #161's station) requires its own authorized retest. Archive actual screenshots or logs with the acceptance record; no screenshot means no screenshot claim.

## Temporary Coolify tasks

Prefer Module Manager and existing CI; a scheduled task is not required for ordinary module promotion. If a separately authorized one-off container operation is required, first verify the application and container target, working directory, available PrestaShop command and backup. In the Coolify application dashboard, use **Scheduled Tasks** to create a clearly named temporary task only for that operation, with a non-repeating/manual execution plan (no recurring production schedule). Inspect the generated command before Run now; never place secrets in it or expose output containing private data. Verify the actual execution result, then disable and delete the temporary task and re-list tasks. If the UI cannot create a safe manual task or the command is not confirmed, stop rather than scheduling an uncontrolled repeat. No standing task or privileged shell shortcut is assumed.

## Rollback

Do not assume downgrading module files reverses a database migration. If a release fails before schema changes, restore the prior verified module files/ZIP using the authorized update procedure and check installed version and behavior. If a migration or write changed data/schema, follow the approved database-and-files backup restore path together; review data created after the backup before destructive restore. Restore and verify in an isolated instance first when possible. Re-run the smoke checks and record the actual restored commit/version. Coolify image rollback is for the hosting image, not automatically the module revision or database.

## Evidence and limitations

The repository's Module ZIP workflow and documented PrestaShop installation path are the basis for this runbook. The Coolify read-only inventory verified the image-based deployment boundary and absence of standing scheduled tasks, **not** the exact deployed module hash or a successful upgrade/rollback rehearsal. Close #150 only after the authorized demo promotion and recovery procedure is confirmed in practice; capture any deployment-specific steps without secrets.
