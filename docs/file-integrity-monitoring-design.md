# File Integrity Monitoring and Software Update Design

Status: **draft for review** — 2026-08-20

This document designs three cooperating deliverables:

1. **A core console command** that generates a signed per-release file manifest
   (run manually today, by release CI once that pipeline exists).
2. **A FIM module** (`oe-module-file-integrity`) that verifies an installation
   against the manifest for its exact version, notifies admins of available
   updates — flagging security releases and CVEs — and manages a curated
   allowlist for intentional local modifications.
3. **An update module** (`oe-module-software-update`) that performs the actual
   software update from the CLI as an elevated (non-web) user.

The split is deliberate. Verification is read-only and must run everywhere,
including as the web-server user inside the official Docker containers. Updating
requires write access to the code tree and is therefore CLI-only, run as a user
that is *not* the web-server user. Keeping them in separate modules keeps the
privilege boundary visible in the architecture, not just in documentation.

## Background and prior art

No existing OpenEMR mechanism covers this ground: there is no update check
(the only outbound calls are product registration and telemetry to
`reg.open-emr.org`), no file-hash manifest, and no generic admin security
notice surface. The closest analogue elsewhere is WordPress's
`wp core verify-checksums`, which downloads per-version checksums from
wordpress.org and compares them to disk without loading the application. That
model — vendor-published baseline, verified locally — is what we adopt, with
two hardening improvements: manifests are **signed** (ed25519, verified with
the bundled `ext-sodium`), and local modifications are handled by **pinning a
site-curated hash** rather than by skipping the file.

Host-based FIM tools (AIDE, Tripwire, Wazuh/OSSEC) solve a different problem:
they baseline whatever is on disk at initialization time, cannot answer "does
this file match what release X shipped," and assume root plus a host-level
install that is unavailable in container deployments. They remain valuable as
a defense-in-depth layer for hardened bare-metal sites and are addressed in
the threat-model section, but they are not the engine here.

## Threat model

What the FIM module can and cannot claim depends entirely on who can write
where. State this honestly, in the module's own documentation as well as here.

**Detected:** modification, deletion, or addition of files in the shipped tree
relative to the signed manifest — webshell drops, tampered core files,
incomplete upgrades, bit rot, well-meaning-but-forgotten manual patches.

**The web-user limitation:** a process running as the web-server user can be
defeated by an attacker who has fully compromised that same user — they can
patch the checker, poison its cache, or suppress its alerts. Mitigations,
in increasing order of strength:

1. **Signed manifests with pinned keys** close the "feed the checker a forged
   baseline" path. The attacker must tamper with the checker itself, which is
   a larger, noisier footprint — and one the elevated mode detects.
2. **Off-box reporting with a dead-man switch.** Alerts go out via email or
   webhook, and the *absence* of an expected heartbeat is itself an alert. A
   disabled checker must not look identical to a healthy quiet one.
3. **Overlay changes are reportable events.** The local allowlist is
   web-user-writable state; every addition or change to it goes through the
   same off-box alert channel (see "Local overrides").
4. **Elevated CLI mode.** The same verify command run from cron as a
   *dedicated non-root file-owner user* (see below) is outside the web user's
   write reach. Recommended for regulated deployments, alongside a
   read-only-code-tree filesystem layout where operationally possible.
5. **External tooling for the highest tier.** Wazuh (with its HIPAA FIM
   mapping) or AIDE watching the OpenEMR tree from the host, and `docker
   diff` / image-digest pinning for container installs. A stretch goal for
   the FIM module is emitting an AIDE rule file scoped to the OpenEMR tree so
   both layers agree on what they watch.

**On "elevated" and root:** `bin/console` refuses to run as root
(`RootCliGuard::assertNotRoot()`), and that is the right call — the elevated
mode is a dedicated OS user that owns the code tree (the "deploy user"), with
the web user holding read-only access. That layout is what the update module
requires anyway, so the two modules push deployments toward the same target
posture.

## Component 1: manifest generator (core command)

### Placement and invocation

A Symfony console command in `src/Common/Command/`, auto-discovered by
`SymfonyCommandRunner` like its siblings (`ReleasePrepCommand`,
`PatchPrepCommand` — this command belongs to the same release-tooling family):

```bash
bin/console --skip-globals openemr:create-file-manifest \
    --tree=/path/to/extracted-release \
    --output=./openemr-file-manifest-v8_3_0.json.gz \
    --sign-key=/secure/path/manifest-signing.key
```

It runs under `--skip-globals`: no database, pure filesystem. This matters for
CI (no stack needed) and for generating back-catalog manifests from extracted
release packages.

Supporting classes live in core at `src/FileManifest/` (namespace
`OpenEMR\FileManifest`): the manifest value objects, canonical serializer,
signer, and signature verifier. Core placement is deliberate — the FIM module
and the update module both consume these classes, and shipping the verifier in
core means every installation can verify a manifest without either module
present.

### Filesystem access: Flysystem

All filesystem traversal and hashing — in the generator and in the FIM
module's scanner — goes through [League Flysystem](https://flysystem.thephpleague.com/docs/),
not raw PHP filesystem functions. OpenEMR core is migrating to Flysystem as
its filesystem abstraction layer, and `league/flysystem` (^3.33) plus
`league/flysystem-local` are already core dependencies, so nothing new is
vendored and the module consumes them through the core autoloader.

- The `FileManifest` walker/hasher takes a `League\Flysystem\FilesystemOperator`
  in its constructor. Callers today construct
  `new Filesystem(new LocalFilesystemAdapter($installRoot))`; when core
  exposes its own filesystem service, the module switches to injecting that
  service with no change to the library — the migration path is the
  constructor parameter.
- Traversal uses `listContents('', deep: true)` restricted to files; per-file
  metadata comes from `fileSize()` / `lastModified()`; hashing uses
  `readStream()` fed through an incremental `hash_update_stream()` (or
  Flysystem's `checksum()` where the adapter provides it), so large files
  never load fully into memory.
- **Symlink policy:** `LocalFilesystemAdapter` must be constructed with an
  explicit link policy. We use `SkipLinks` and the scanner reports any
  symlink encountered inside the shipped tree as its own finding bucket
  (`symlink`) — a link inside core directories is unusual enough to surface,
  and silently following links would let a link-swap redirect the scan.
- Manifest paths are Flysystem-normalized (relative, forward-slash) — the
  same normalization the generator records, so generator and verifier can
  never disagree on path syntax across platforms.
- Tests use `league/flysystem-memory` (already a dev dependency) so the
  walker, hasher, and scanner logic are isolated-testable with no disk
  fixtures.

### What gets hashed

The manifest describes the **shipped release package**, not the raw git tree.
Release packages include `vendor/` and built frontend assets that git does
not, and the package is what actually lands on disk in both bare-metal and
Docker installs. Consequences:

- CI generates the manifest **after** the release package is built, from the
  package contents.
- Back-catalog manifests are generated from the official release packages
  (downloaded and extracted), not from `git archive` of the tag.
- The `--tree` option points at the root of an extracted package; the command
  reads `version.php` from that tree to stamp the manifest, with
  `--version-override` available for edge cases.

**Patch releases:** `$v_realpatch` increments when a patch overlays files onto
a production release, so a manifest exists per `(version, realpatch)` pair.
The release process generates the patched manifest from a tree with the patch
applied. The FIM module selects the manifest matching both values from
`VersionService`.

### Manifest format

Gzipped JSON with a detached signature over the compressed bytes:

```
openemr-file-manifest-v8_3_0.json.gz        # the manifest
openemr-file-manifest-v8_3_0.json.gz.sig    # ed25519 detached signature
openemr-file-manifest-v8_3_0-patch2.json.gz # realpatch 2 of the same release
```

```json
{
    "schemaVersion": 1,
    "openemr": {
        "version": "8.3.0",
        "realpatch": 0,
        "tag": "v8_3_0"
    },
    "source": "release-package",
    "algorithm": "sha256",
    "excluded": ["sites/**"],
    "files": {
        "interface/main/tabs/main.php": { "h": "9f2c…", "s": 24118 },
        "library/options.inc.php":      { "h": "a01b…", "s": 187332 }
    }
}
```

Notes:

- Paths are relative to the install root, forward-slash, sorted — the file is
  byte-reproducible for a given tree, so two independent runs over the same
  package produce identical bytes (useful for third-party attestation).
- `h` is the sha256 hex digest; `s` is size in bytes. Size lets the scanner
  cheap-skip obviously changed files and sanity-check its cache.
- A full tree is on the order of 100k files; gzip brings the manifest to a
  few MB, acceptable as a release asset and a one-time download per version.
- `excluded` records what the generator skipped, so the verifier and the
  generator can never silently disagree about scope.

**Exclusions are minimal by design:** `sites/**` (site data, uploads,
`sqlconf.php`) and nothing else. Bundled modules under
`interface/modules/custom_modules/` that ship in the release package *are*
hashed — they are shipped code. Files present on disk but absent from the
manifest are the scanner's problem to classify (see "Findings taxonomy"), not
the generator's problem to ignore.

### Signing

- Algorithm: ed25519 via `sodium_crypto_sign_detached()` — `ext-sodium` is
  bundled with PHP, so verification needs no extra dependency anywhere.
- The signature covers the **uncompressed canonical JSON bytes**, not the
  `.json.gz` container: gzip output is not byte-stable across zlib versions
  and compression levels, so signing the payload keeps the manifest
  byte-reproducible and lets mirrors recompress without invalidating
  signatures. Verifiers decompress, then verify.
- `--sign-key` reads a secret key from a file path or an environment
  variable; `--no-sign` produces an unsigned manifest for local/dev use.
- **Key custody is phased.** First iteration: the signing key lives on a
  maintainer machine, and manifest generation + signing is a manual step in
  the release checklist (the command is the same either way). Once the CI
  pipeline exists, signing moves to a CI secret and the manual step
  disappears. The maintainer-era public key is simply the first entry in the
  pinned-key list; if the CI era starts a fresh key, both stay pinned until
  every maintainer-signed release is out of support.
- The **public** key is published in the repo (and pinned in the FIM module —
  see below). Key rotation is supported by the verifier accepting a list of
  pinned public keys; a new key is added a release before the old one stops
  signing.

### Distribution

Canonical location: **GitHub release asset** on the corresponding tag,
i.e. `https://github.com/openemr/openemr/releases/download/v8_3_0/openemr-file-manifest-v8_3_0.json.gz`
plus the `.sig`. Optionally mirrored on project infrastructure later
(`reg.open-emr.org` already exists as a precedent) — the signature makes the
transport untrusted anyway, so mirrors are a availability question, not a
security one.

## Component 2: FIM module (`oe-module-file-integrity`)

A standard custom module following the established skeleton
(`openemr.bootstrap.php`, `ModuleManagerListener.php`, `moduleConfig.php`,
`table.sql`, `src/Bootstrap.php`, `version.php` — the
`oe-module-healthlink` layout).

**Distribution trajectory:** the module starts life as an independently
distributed custom module while the code goes through project-admin review.
The end state is **bundled into core releases** — that is not just a
convenience goal but a security property: once bundled, the checker's own
files are covered by the signed release manifest, closing the
"who watches the watcher" gap for the web-user execution mode. The module is
written to core coding standards (strict types, PHPStan level 10, DI, PSR-3
logging) from day one so the review-and-adopt step is a move, not a rewrite.

### Manifest providers

```
src/Manifest/
    ManifestProviderInterface        # getManifest(SoftwareVersion, int $realpatch): ?SignedManifest
    LocalDirectoryManifestProvider   # interim + air-gapped installs
    GitHubReleaseManifestProvider    # canonical, once CI publishes assets
    ChainManifestProvider            # ordered fallback: local, then GitHub
    ManifestRepository               # verified-manifest cache (DB)
```

**Local provider (built first).** Reads
`openemr-file-manifest-<tag>[.patchN].json.gz` from a configured directory.
Default: `sites/<site>/documents/fim-manifests/`, which is pragmatic
(web-user-readable, survives Docker container recreation via the sites
volume) but web-user-writable — the module config allows pointing it at a
root-owned path outside the docroot for hardened installs, and the signature
requirement (below) is what makes the writable default acceptable.

**GitHub provider.** Resolves the installed version to the tag name
(`8.3.0` → `v8_3_0`, realpatch → `-patchN` suffix), downloads asset + sig
over TLS, verifies, caches in `ManifestRepository`. Unauthenticated download
URLs; no API token required for release assets.

**Signature policy** (module config):

- `require_signed` — default **on** once CI signs releases. Unsigned manifests
  are rejected outright.
- `allow_unsigned_local` — interim escape hatch for the pre-CI period and for
  self-generated manifests; when enabled, the report page and every alert
  email carry a visible "baseline is unsigned" caveat. Flipping this off is
  part of the CI-era rollout (see Phasing).
- Pinned public keys ship in module code; an additional site-supplied key can
  be configured for self-signed local manifests (a site can generate and sign
  its own back-catalog manifests today with the core command).

### Scanner

Design constraints: ~100k files including `vendor/`, PHP execution limits, and
the scan must run as the web user without disturbing the running application.

- **Batched, resumable scanning** driven by the background-service tick.
  Each tick processes a bounded batch (time- and count-budgeted, e.g. 20s or
  5k files, configurable), persisting a cursor. A full pass completes across
  ticks; the run record tracks progress.
- **Flysystem throughout:** the scanner operates on the same
  `FilesystemOperator` abstraction as the manifest library (see "Filesystem
  access: Flysystem" above) — no raw `stat`/`hash_file`/`readdir` calls in
  module code. Today that operator is a `LocalFilesystemAdapter` rooted at
  the install root; when core ships its Flysystem-based filesystem service,
  the module injects that instead.
- **Change-detection cache:** `mod_fim_file_state` stores per-file
  `(path, size, mtime, hash, last_verified)`. Steady-state passes check
  `fileSize()`/`lastModified()` for every file (cheap metadata reads) and
  re-hash only entries whose size/mtime changed — plus a slow rolling
  re-hash of the remainder so an mtime-preserving tamper (`touch -r`) is
  still caught within a configurable window (default: full re-hash coverage
  every 7 days).
- Registered via `BackgroundServiceRegistry` following the
  healthlink `BackgroundServiceRegistration` pattern (register/unregister on
  module install/enable lifecycle in `ModuleManagerListener`), so it runs
  under the existing cron/ajax background executor with admin enable/disable
  in the background-services report.
- **CLI entry point:** the module registers commands through
  `CommandRunnerFilterEvent` (the hook `SymfonyCommandRunner` already
  dispatches): `fim:verify` (full or incremental pass, exit code reflects
  findings — cron-friendly), `fim:status`, `fim:accept` (scripted override
  creation). CLI runs bootstrap the database (needed for state and overrides)
  and are the vehicle for elevated mode.

### Findings taxonomy

Every monitored path resolves to exactly one state:

| State | Meaning |
|---|---|
| `ok` | hash matches the release manifest |
| `override_ok` | differs from release, matches a pinned local override |
| `modified` | differs from release and from any override — **finding** |
| `missing` | in manifest, absent on disk — **finding** |
| `added` | on disk under a shipped directory, absent from manifest — **finding**, reported in its own bucket |
| `override_stale` | override exists but was pinned under a previous `(version, realpatch)` — **needs review** |
| `unreadable` | scanner lacks read permission — **finding** (suspicious in itself when running as the web user) |
| `symlink` | a symbolic link inside the shipped tree — **finding** (links are skipped, never followed; see the Flysystem symlink policy) |

`added` gets its own bucket because it is simultaneously the highest-value
signal (webshells are *added* files in web-reachable directories) and the
highest false-positive source (admins drop files in-tree). Additions under
`interface/modules/custom_modules/` for directories not present in the
manifest are classified as `added_module` and summarized rather than itemized
— installing a module is an expected act, but it is still surfaced.

### Local overrides (the allowlist)

Principle: **pin a hash, don't punch a hole.** The default acknowledgment
path keeps the file fully monitored against a site-curated expected hash;
pattern-based skipping exists but is the explicit exception.

- **Acknowledgment workflow:** from the report page (or `fim:accept`), an
  admin accepts a `modified` finding. The module records path, current hash,
  the accepting user, timestamp, and a **required reason**, writes an audit
  log entry, and transitions the finding to `override_ok`. Optionally the
  unified diff against the release version is stored for text files — after
  an upgrade this becomes "here is the patch you need to re-apply."
- **Version-scoped:** every override records the `(version, realpatch)` it
  was accepted under. On version change, all overrides flip to
  `override_stale` and require re-review. This is the rpmsave/rpmnew idea:
  post-upgrade, the module hands the admin the exact list of local
  customizations to re-apply or retire.
- **Overlay changes are alerts:** every override created, changed, or removed
  is included in the next off-box notification digest. An attacker who
  modifies a file *and* self-approves it still produces an outbound signal.
- **Pattern exclusions** (`mod_fim_exclusion`, glob syntax) for cases where
  per-file curation is impractical. Creating one requires the same
  reason-plus-audit trail, the report page permanently lists active
  exclusions, and each alert digest states the exclusion count.

### Update and advisory notifier

A second, lightweight background task (daily by default):

- **Releases:** poll the GitHub releases API, compare the newest production
  tag against `VersionService::getSoftwareVersion()` + realpatch.
- **Advisories:** poll `GET /repos/openemr/openemr/security-advisories`
  (public, unauthenticated, structured GHSA/CVE data with affected-version
  ranges — verified working). Match ranges against the installed version.
- The two combine into a status: *up to date* / *update available* /
  **security update available**, with the applicable CVE/GHSA list attached.
  Security status escalates the notification channel (immediate email rather
  than digest) and is the headline of the report page.
- Failure to reach either endpoint for N consecutive days is itself surfaced
  (air-gapped sites set a config flag to acknowledge and suppress this).

### Notification surfaces

- **Admin report page** under Reports (modeled on
  `audit_log_tamper_report.php`): current status headline, findings by
  bucket, override/exclusion registry, scan progress and history, update and
  advisory status.
- **Email:** immediate on new findings and security advisories; a periodic
  digest that always includes the heartbeat, the override delta, and the
  exclusion count. Delivered through the existing notification
  infrastructure.
- **Dead-man switch:** the digest is sent even when everything is green. Its
  absence is the tamper signal no on-box attacker can suppress. Documentation
  tells admins to alarm on missing digests; a stretch goal is an opt-in
  heartbeat to project infrastructure that emails the admin from *outside*
  when heartbeats stop.
- There is no generic admin-notice surface in core today; the login-time
  banner for security findings is a candidate **core contribution** (small
  event/hook in `interface/main/tabs/main.php`) rather than a module hack.

### Database schema (module `table.sql`)

| Table | Purpose |
|---|---|
| `mod_fim_manifest` | verified manifests: version, realpatch, source, signer key id, verified-at, blob/path |
| `mod_fim_file_state` | per-file scan cache: path, size, mtime, hash, state, last_verified |
| `mod_fim_finding` | findings lifecycle: path, state, first/last seen, resolution |
| `mod_fim_override` | pinned overrides: path, hash, version, realpatch, accepted_by, reason, diff, stale flag |
| `mod_fim_exclusion` | glob patterns, created_by, reason |
| `mod_fim_run` | scan runs: cursor, budgets, started/finished, totals — doubles as heartbeat source |

## Component 3: update module (`oe-module-software-update`)

CLI-only, by design and permanently. There is no web-triggered update path:
an in-app updater requires web-user write access to the code tree, which
directly destroys the FIM posture (and on Docker would rewrite ephemeral
container files that revert on recreate). The module registers its commands
via `CommandRunnerFilterEvent`; it ships no UI beyond the FIM report page
linking to its documentation when an update is available.

**Execution identity:** the deploy user — owns the code tree, is not root
(`RootCliGuard` enforces this), is not the web-server user. Commands verify
they can write the tree before starting and refuse with guidance otherwise.

**Docker detection:** when a containerized environment is detected, all
file-mutating commands refuse and print the image-based upgrade procedure
(pull new image digest, run the container upgrade flow). `update:check` still
works everywhere.

### Commands and flows

```
update:check          # releases + advisories, human/machine output; runs anywhere
update:download       # fetch release/patch artifact, verify signature + manifest presence
update:apply-patch    # phase 4: patch-overlay updates (realpatch increments)
update:upgrade        # phase 5: full version upgrades
```

**`update:apply-patch`** (built first — file overlay, no schema migration in
the general case, bounded risk):

1. Pre-flight: FIM verify against the *current* manifest; refuse (or
   `--force` with an explicit report) when unresolved findings exist — never
   overwrite evidence, and surface local modifications the overlay would
   clobber, with stored diffs where available.
2. Download the patch package and the *target* manifest; verify signatures.
3. Back up every file the overlay touches to a timestamped directory under a
   configured backup path.
4. Apply the overlay; run `sql_patch.php`; update the realpatch via
   `VersionService`.
5. Post-verify against the target manifest — the install ends on a proven
   clean baseline, and stale overrides are flagged for re-review.

**`update:upgrade`** (later phase) wraps the same skeleton around the full
procedure: maintenance mode, database backup, staged extraction of the new
release alongside the current tree, tree swap, `sql_upgrade.php` +
`acl_upgrade.php`, post-verify, rollback path (restore tree + database
backup) documented and tested. This inherits the risk profile of the manual
upgrade procedure it automates; it ships only after the patch flow has
soaked.

**Shared code:** all manifest and signature logic comes from core
`OpenEMR\FileManifest`; the update module additionally depends on the FIM
module for pre/post-flight verification (a hard dependency, declared in the
module metadata — an updater without integrity verification is the wrong
product).

## Interim workflow: manifests before CI exists

The core command works today against any extracted release package. To cover
the back catalog (`v8_3_0`, `v8_2_0`, …):

```bash
# once per tag, from any machine with the target OpenEMR codebase available
wget <official release package for v8_3_0>
tar -xzf openemr-8.3.0.tar.gz -C /tmp/openemr-8.3.0
bin/console --skip-globals openemr:create-file-manifest \
    --tree=/tmp/openemr-8.3.0/openemr \
    --output=manifests/openemr-file-manifest-v8_3_0.json.gz \
    --sign-key=./site-manifest.key        # or --no-sign
```

Drop the results into the FIM module's local manifest directory. Sites (or
we, during development) can sign with a self-generated key added to the module
config, or run unsigned with `allow_unsigned_local` and its visible caveat.
Because generation is byte-reproducible, independently generated manifests
for the same package are comparable — a useful property for establishing
trust in the back-catalog set before official signatures exist.

**CI integration (when built):** a step in the release workflow, after the
package is assembled — run the command against the package contents, sign
with an organization secret, upload both files as release assets. A one-time
back-fill job attaches manifests to existing release tags using the same
tooling.

## Phasing

| Phase | Deliverable | Notes |
|---|---|---|
| 0 | Core `OpenEMR\FileManifest` library + `openemr:create-file-manifest` | Flysystem-based, isolated-testable (flysystem-memory), no DB; includes signer/verifier; maintainer-machine signing |
| 1 | FIM module MVP | local provider, batched scanner, findings report page, email alerts, `fim:verify` CLI |
| 2 | Overrides + notifier | acknowledgment workflow, version-scoped overrides, update/CVE notifier, dead-man digest |
| 3 | GitHub provider + CI | release-asset publishing, signing key moves from maintainer machine to CI secret, back-fill, flip `require_signed` on by default |
| 4 | Update module: `update:check` / `update:download` / `update:apply-patch` | patch overlays only |
| 5 | `update:upgrade` | full version upgrades, rollback story |

Phases 0–1 have no external dependencies and can start immediately; the
interim manifest workflow makes the module useful from phase 1 onward.

## Resolved decisions

- **Signing key custody:** maintainer machine for the first iteration (manual
  step in the release checklist), migrating to a CI secret when the CI
  pipeline is built. Both public keys stay pinned through the transition.
- **Module distribution:** the FIM module lives as an independent custom
  module until project admins review and accept the code, then gets bundled
  into core releases — at which point the checker's own files fall under the
  signed manifest.
- **Filesystem abstraction:** League Flysystem for all traversal and hashing
  in both the core library and the module — no raw PHP filesystem calls in
  scan paths. `league/flysystem` + `flysystem-local` are already core
  dependencies; the module constructs a `LocalFilesystemAdapter` today and
  migrates to core's Flysystem-based filesystem service when that lands.
- **Notifier feed:** direct GitHub APIs (releases + repo security
  advisories). If real-world use hits rate limiting, an aggregated signed
  JSON feed on project infrastructure is the designed fallback — the client
  is behind a small interface so swapping the transport is contained.
- **Admin notification surface:** the FIM report page ships first and is the
  authoritative surface. For a login-time banner, evaluate the existing
  OpenEMR admin login-banner module on Packagist before building our own;
  either way the banner is an add-on consumer of FIM status, not a
  dependency of it.
- **`vendor/` scan depth:** all of `vendor/` is hashed — it is shipped code
  and a real attack surface. Phase 1 performance testing on realistic
  hardware validates the scan budgets; the per-package rollup idea remains a
  contingency only if testing shows the full scan is untenable on low-end
  hosts, not a configuration we plan to ship.
