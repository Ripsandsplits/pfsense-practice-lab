# Validation and release limits

## Automated controller tests

`tests/controller-test.php` uses isolated temporary filesystem fixtures and a simulated host. It does not call pfSense APIs or a live firewall. Run it in a Unix development environment with PHP 8.1+ and permission to set fixture ownership:

```sh
php tests/controller-test.php
```

The release check used PHP 8.3 in WebAssembly because this workspace does not run FreeBSD or native pfSense PHP. The optional reproducible harness uses Node.js 24 and pinned development dependencies:

```sh
cd tests
npm install
npm test
```

Run that only on a development workstation. It executes the controller suite in a virtual filesystem and an additional native-adapter suite with mocked configuration APIs and OS commands. For the adapter test, platform guards are replaced only in an in-memory copy of the native source; the shipped production file remains guarded. Thus those tests do not establish real FreeBSD utility behavior or integration with your installed pfSense release.

Do not run either fixture suite on the firewall. The direct PHP suite creates temporary test directories, and development dependencies are not needed by the lab.

Covered behavior includes preview without mutations; acknowledgment/option checks; interface/port/HA refusal; distinct nonzero UID allocation; preservation of existing passwords, managed and unmanaged keys, NAT, aliases, DHCP, interfaces and GUI settings; eight-category inventory; selective reset; preservation of student changes and file/home contents; repeated reset; fresh replay; allocator preservation; account/process/tracker conflicts; failed reload/config commit/account generation recovery; reset retry; symlink handling; private state; missing-manifest verification; concurrent-edit preservation.

Shell syntax and unsupported-platform refusal are checked separately. Test counts and exact runtime results are included in `TEST-RESULTS.txt` when packaged.

## Required pfSense VM smoke test

The following is an operator acceptance test, **not a claim that this release already passed it**:

1. Create a clean isolated pfSense CE clone with direct console. Record the full release/version, PHP version, virtual NIC names, interface keys, and hypervisor. Verify root-only console access and take a powered-off snapshot.
2. Record existing managed/OS users, password-hash/key fingerprints, firewall rules, cron jobs, interface/DHCP/DNS/NAT settings, GUI and optional SSH access, and the business-service availability checks. Keep sensitive outputs protected.
3. Run `sh lab.sh help`, `preflight --interface lan`, and the seed preview. Confirm no practice accounts/config entries/files/state directory appear in preview. The launcher's lock file and native read caches are normal exceptions.
4. Apply the seed with both acknowledgment flags. Confirm exit `0`, phase `seeded`, and all eight categories present. Confirm both generated accounts have distinct UIDs >=2000, and that the existing users/keys/settings match their earlier fingerprints.
5. Inspect the exact generated key restrictions. Confirm the package retained no private key, the rule is limited to IPv4 TCP firewall-self on the chosen high port, and `sockstat` shows no listener on that port. Check native cron contains the single labeled logger job.
6. Wait for a cron interval if testing log generation. Execute the startup file once only if you want an immediate logger check; it logs and exits. Rebooting is optional for native event/boot persistence validation. Recheck management and business services.
7. Make an unrelated harmless GUI change and add a note inside an exercise file/new account home. Preview reset; confirm it has made no artifact changes.
8. Apply reset, then `verify-reset`. Confirm exit `0`, phase `reset`, all eight categories absent from live configuration/runtime, ordinary users/keys/rules/jobs preserved, unrelated GUI change kept, and modified exercise files/home notes present in retained locations.
9. Repeat reset to confirm normal idempotence. Seed once more to confirm a fresh run/token, then reset again.
10. Test console recovery by restoring the clean snapshot. Do not simulate a destructive production outage.

If the native saved-config comparison or runtime verification fails, inspect the error and cleanup state from the console. Stop using that clone for timed exercises until it passes. Do not weaken the guards to accommodate an unknown API shape.

## Limits

- Intended for a standalone current pfSense CE installation with the checked APIs. No pfSense Plus, HA, historical-release, or appliance-specific integration certification is claimed.
- Internal API definitions in the linked upstream source can differ from the installed release.
- Configuration digest checks and launcher locking do not coordinate every independent GUI/PHP writer. Use a quiet change window.
- An OS crash can interrupt an operation. The saved manifest supports a later selective reset; a hypervisor snapshot remains the exact recovery mechanism.
- A forced interruption during temporary key generation can leave an unused root-only temporary key directory under `/tmp/ccdc-lab-key-*`. The public key is not installed until private-key deletion has completed. Inspect any such leftover from the console after an interrupted run.
- Inventory looks for this run's recorded objects and markers, not arbitrary persistence or a copied/renamed artifact. Reset does not restore a genuinely compromised machine to known-good state.
- Logs, config history, retained homes/files, and sensitive backups remain. Do not export them with a public lab image.
- This exercise has no live adversary, exploit, listener, score service, external callback, or package download stage.
