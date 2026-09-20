# MediaWiki operations

Commands assume instance ID/release name `mediawiki` in namespace `wiki` and an
explicitly selected `$KUBE_CONTEXT`. Substitute your namespace/resource names.
The application Secret defaults to `mediawiki-app`, the shared backup Secret is
`mediawiki-r2`. Keep application keys and the restic password backed up
independently of the cluster. The PostgreSQL operator manages the database Secret.

This distribution has not yet passed its initial container/database integration
and recovery rehearsal. Before production, verify empty-database installation,
repeat initialization without password changes, rejection of an unmarked nonempty
database, private editing/uploads/PDFs, and a matched database/upload restore.

## Initialization and first deployment

Install the shared `shared/` bundle through SOPS-enabled Flux first, then
install the selected release after creating application Secrets and checking the dependencies
listed in [the README](../README.md).
The Job does not mount LocalSettings at the document root, as MediaWiki 1.43's
installer refuses an existing configuration there. It installs with passwords in
temporary files, loads our extension configuration, assigns the accountcreator
group, then records `cloud_lab.bootstrap` in the database.

The application init container waits for this marker; readiness also checks it.
The Job uses a database advisory lock to serialize attempts. Existing marked
databases are left alone. A nonempty MediaWiki schema without the marker requires
manual investigation: do not drop tables or mark it ready without checking the
schema and administrator.

The image sets `PGOPTIONS=-c role=none` so installation, updates, the application,
and bootstrap checks use the authenticated login role. The operator's default
group role cannot access tables created under MediaWiki's explicit login role.

For a failed first installation with no valuable data, deliberately reset only
that wiki's database schemas before retrying with the corrected image. This deletes
all wiki content and accounts. Normal initialization refuses an unmarked nonempty
schema; there is no automatic recovery or password reset.

Confirm the Job completed and the Deployment is ready:

```sh
kubectl --context "$KUBE_CONTEXT" -n wiki wait --for=condition=complete job -l app=mediawiki-initialize --timeout=20m
kubectl --context "$KUBE_CONTEXT" -n wiki rollout status deployment/mediawiki --timeout=20m
```

Test anonymous page/API access, originals, thumbnails and PDF previews: content
must not be accessible. Login must work. Check admin account creation and verify
a normal account can move, delete, restore and protect pages, and roll back edits,
but cannot create accounts, block users or change groups.
Test source/visual editing, image insertion, search, jobs, and replacing a pod.

The ConfigMap has a stable name; a configuration checksum rolls the application.
Both initialization Jobs have names derived from configuration and Helm release
revision, so upgrades create new Jobs without mutating completed pod templates.
They run as ordinary resources alongside the Deployment, avoiding a post-install
hook deadlock with the application's initialization wait. The completion-marker
guard prevents reinstalling an existing database; schema upgrades remain manual.
Secret contents are not hashed: restart the Deployment after credential changes.
If a failed initialization exhausted retries, fix the cause and delete that failed
Job, then reconcile the HelmRelease to recreate it.

## Widgets

The image includes Widgets 1.7.1 and its Smarty dependency. Compiled templates
live in `/tmp/mediawiki-widgets`, outside the document root, and are regenerated
after container replacement. Widget definitions are wiki pages stored in PostgreSQL.

All logged-in users can create and edit `Widget:` pages and include
them with `{{#widget:WidgetName|parameter=value}}`. Installing the extension does
not install any widget definitions. For Instagram, create a `Widget:Instagram`
definition before using `{{#widget:Instagram|...}}`; its parameters depend on the
definition you choose. After deployment, check `Special:Version` for Widgets and
verify a widget renders as both an administrator and an ordinary editor.

See the [Widgets documentation](https://www.mediawiki.org/wiki/Extension:Widgets)
for widget definitions and parameter escaping.

## Daily upload backups

The shared R2 bucket stays private. Use bucket-scoped S3 credentials. Set
endpoint and bucket in the shared `mediawiki-backup` ConfigMap; `id` determines the repository path and
snapshot host label. For `id: mediawiki`, the chart sets the repository URL to
`s3:https://ACCOUNT.r2.cloudflarestorage.com/BUCKET/mediawiki` and the snapshot
host label to `mediawiki`. Keep the ID stable to retain the same repository
and snapshot selection. No R2 lifecycle rule should independently delete restic objects.

The `mediawiki-backup-initialize` Job automatically opens or initializes the
repository on deployment using the shared `mediawiki-r2` Secret. It creates restic's encrypted
configuration/key structure within the bucket; it does not create the R2 bucket.
An existing repository is verified with the supplied password. Restic refuses to
initialize over existing repository data; wrong passwords and backend failures
cause the Job to fail. Correct the Secret/access problem before retrying.

By default, daily backups are enabled at 04:00 Europe/Paris. Wait for initialization before
triggering an optional immediate backup; rehearse recovery after the first success:

```sh
kubectl --context "$KUBE_CONTEXT" -n wiki wait --for=condition=complete job -l app=mediawiki-backup-initialize --timeout=10m
```

Helm replaces initialization Jobs on upgrades; existing repositories are verified.
After setup, run a manual backup Job and wait for success:

```sh
kubectl --context "$KUBE_CONTEXT" -n wiki create job mediawiki-backup-test --from=cronjob/mediawiki-backup
kubectl --context "$KUBE_CONTEXT" -n wiki wait --for=condition=complete job/mediawiki-backup-test --timeout=2h
```

It runs at 04:00 Europe/Paris when enabled. Required pod affinity places the Job
on the application node because the PVC is ReadWriteOnce. It sets a shared
read-only file and takes an exclusive filesystem lock after existing requests/jobs
release their shared locks. It then records a PostgreSQL UTC timestamp and WAL LSN
and backs up uploads and this metadata. New dynamic requests receive a temporary
503 during the snapshot; the static liveness endpoint remains available.
It resumes editing before retention/pruning. Failed backups do not prune.
Restic retains the most recent snapshot from each of 30 days with successful
backups; missing days cannot be restored. The lock wait is bounded to five minutes; long-running jobs make the backup fail
rather than capture mismatched state. Direct database/file maintenance must also
be stopped during a backup.

If a pod is killed before cleanup, the read-only file and backup-lock directory
may remain. First verify no backup/upgrade is active, then remove those two control
artifacts to resume writes. Do not remove them during an active operation.
Suspend backups and wait for active Jobs before rolling the app to another node.

## Restore

Use an isolated PostgreSQL cluster and a new upload PVC for the rehearsal.
Restore the chosen restic snapshot to a temporary location:

```sh
restic snapshots --host mediawiki --tag uploads
restic restore SNAPSHOT_ID --target /tmp/mediawiki-restore
```

The result contains `data/uploads` and `tmp/backup-metadata/postgres-point.json`.
Use its timestamp as the PostgreSQL point-in-time recovery target, checking that
the required base backup and WAL exist. Select the archive that actually covers the target time; obtain the archive
identity and database restore procedure from your platform administrator.

For wiki-only recovery, restore the physical cluster separately, then use
pg_dump/pg_restore to transfer the mediawiki database (both mediawiki and cloud_lab
schemas) into an isolated test database. Restore object ownership to the
operator-managed role, and restore uploads with UID/GID 33 access. Keep the same
wiki secret keys and matching application version. Do not overwrite other apps'
databases. Verify pages, users, edits, originals and PDF previews before cutover.

Compare database WAL retention with restic's 30 successful daily snapshots,
which can span more than 30 days after failures. Select a snapshot within available database
history. A newer DB restore paired with older files can reference missing uploads.

## Passwords and upgrades

No SMTP is configured. Open an interactive shell; enter the account and password
inside the container so neither becomes part of a Kubernetes exec API argument:

```sh
kubectl --context "$KUBE_CONTEXT" -n wiki exec -it deployment/mediawiki -c web -- bash
```

In that shell:

```bash
read -r -p 'Account name: ' mw_reset_user
read -r -s -p 'New password: ' mw_reset_password
printf '\\n'
php maintenance/run.php changePassword --user "$mw_reset_user" --password "$mw_reset_password"
unset mw_reset_user mw_reset_password
exit
```

The value is briefly present in the maintenance process arguments inside the
container, so perform this with trusted cluster administrators only. Test the
new login. This also recovers the initial admin. Changing the bootstrap Secret
does not change an existing account's password.
[Password maintenance](https://www.mediawiki.org/wiki/Manual:ChangePassword.php)

Database credential rotation requires restarting the web and job containers because
they consume environment variables. Preserve MW_SECRET_KEY across redeployments.

Before an upgrade: suspend backups, wait for active Jobs, create a verified backup,
and stop web writers and job runners. Run `maintenance/run.php update --quick`
once using the new image and the mounted LocalSettings; then start the Deployment
and smoke-test. Do not assume downgrading the image reverses a schema migration:
recover the matched database/files backup if rollback is needed.

Sources: [installation](https://www.mediawiki.org/wiki/Manual:Install.php),
[private images](https://www.mediawiki.org/wiki/Manual:Image_authorization),
[restic retention](https://restic.readthedocs.io/en/stable/060_forget.html).
