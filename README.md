# pfSense CCDC practice lab: seeding and reset

Version 1.0.0 · Prepared November 19, 2026

This package turns a **disposable, isolated pfSense CE VM** into a repeatable defensive investigation exercise. The seed command adds eight categories of documented artifacts. The reset command removes the recorded resources while preserving unrelated configuration changes and retaining exercise files for review.

**Validation status:** the controller has automated tests for seeding, preservation, rollback, conflicts, and reset. Those tests use a simulated host. This package has **not been integration-tested on a running pfSense/FreeBSD VM**. Complete the smoke test below on your disposable clone before using it for a timed practice session. Native pfSense APIs can vary by release; preflight rejects missing APIs rather than modifying generated files as a fallback.

## Package contents

| File | Purpose |
|---|---|
| `lab.sh` | Shell entry point: preflight, seed, inventory, reset |
| `lib/lab.php` | Seeding, manifest, inventory, and selective reset controller |
| `lib/native.php` | Native pfSense configuration/account/cron/PF adapter |
| `STUDENT-EXERCISE.md` | Investigation tasks, evidence checklist, injects |
| `INSTRUCTOR-ANSWER-KEY.md` | Exact artifacts, inspection commands, completion criteria |
| `tests/controller-test.php` | Standalone fixture tests; development machine only |
| `tests/run-*-wasm.mjs`, `tests/package.json` | Optional reproducible WebAssembly/mock test harnesses |
| `VALIDATION.md` | Test scope and required native smoke test |
| `SHA256SUMS` | Package file checksums |

The earlier `first30-pfsense.sh` script is optional and **is not included**. This lab has its own interface. There is no universal `first30-pfsense.sh --apply` command.

## What the seed adds

Each run has a random ten-character hexadecimal token. Resource names include that token, and configuration artifacts use the label `CCDC-LAB:<token>`.

| Category | Exercise artifact | Actual behavior |
|---|---|---|
| Privileged account | A new `ccla_<token>` managed user | Has `page-all` GUI privilege and a nonzero UID; a random password is hashed and discarded |
| Shell account | A new `cclk_<token>` managed user | Has shell privilege, with another random, undisclosed password |
| SSH key | A public key on the new shell account | Loopback-source restriction, forced `/usr/bin/false`, and `restrict`; private half deleted before installation |
| Firewall policy | IPv4 TCP pass rule on the selected internal interface | Any source to the firewall itself, on an unused high TCP port, default `46290`; no listener is started |
| Scheduled task | Root cron entry every five minutes | Writes a labeled system-log message with `logger` |
| Startup hook | Executable `.sh` under `/usr/local/etc/rc.d/` | Writes a labeled log message and exits |
| Fake credentials | A root-only text file under `/root/` | Contains clearly fake credentials that do not authenticate either new account |
| Web-directory artifact | A `.txt` file under `/usr/local/www/` | Plain text only; no executable web code |

This is a **simulation of compromise artifacts**, not a vulnerable image or a malware sample. The accounts are real configuration entries, so use the isolated VM described below. The logging markers do not demonstrate an attacker connection. The extra pass rule may be redundant with an existing LAN allow rule, and it does not create a listening service.

Existing users, password hashes, authorized keys, SSH settings, management ports, NAT, DHCP, DNS configuration, and interface addressing are preserved by the planned changes. Applying a seed or reset regenerates cron and reloads the firewall through pfSense APIs; it does not restart every service or deliberately flush all connection states. Release-specific reload behavior still needs the native smoke test.

## 1. Build the VM and isolate it

1. Install pfSense CE from the [official download page](https://www.pfsense.org/download/). Use a fresh installation or a clone that contains no personal or production secrets.
2. Give it two virtual NICs. Attach WAN to a private virtual switch and LAN to a second private switch. Attach a test workstation to the LAN switch. Use a static IPv4 address on LAN, for example `192.0.2.1/24`, and `192.0.2.10/24` on the workstation. These are example lab addresses; substitute your actual lab subnet in dependent commands.
3. Keep these switches disconnected from physical NICs and your home network. Use VirtualBox **Internal Network**, Hyper-V **Private** switches, or VMware **LAN Segment** / a custom isolated VMnet. A host-only network also exposes the VM to the host; use it only if you deliberately want that connectivity and have checked its isolation. Turn off shared folders/clipboard if you do not need them.
4. If installation needs Internet access, temporarily use a separate update network. Disconnect it before seeding. Do not bridge the seeded firewall to your home network or forward physical-router ports to it.
5. Set your normal administrator password, configure basic LAN access, and verify login from the workstation. SSH is optional for artifact seeding; enable it only if needed for file transfer or investigation in this lab.
6. Open the hypervisor console and verify you can enter the pfSense shell. The examples assume console option **8**, Shell, logged in as root.
7. Power off and take a snapshot named `pfsense-clean-before-lab`. Start the VM and leave the console available. Record the expected users, rules, services, and addressing.

Use your own Windows/Linux client behind the firewall as the business-service test machine. A second Linux VM running an ordinary HTTP service gives you a useful availability check. If you want a continuously reachable WAN-side service, add it to the isolated WAN switch and establish the required normal rules before the clean snapshot. This package does not create a scorer or external service topology.

The script cannot prove hypervisor isolation, snapshot existence, or console readiness. `--lab-confirmed` and `--console-ready` record your acknowledgment for the apply command; they are not automatic checks.

## 2. Copy the package to pfSense

Extract the ZIP on your workstation. Copy the complete `pfsense-practice-lab` directory to `/root/` on the VM using your usual file-transfer method. Keep `lib/` next to `lab.sh`.

For example, if lab-only SSH file transfer is already configured, WinSCP can copy the folder to `/root/`. Use the account and transfer protocol supported by your lab setup. Do not place the package in the firewall web directory.

Alternatively, copy the ZIP and extract it in the shell with the native `tar` utility if ZIP extraction is supported on your release:

```sh
cd /root
tar -xf pfsense-practice-lab-1.0.0.zip
cd /root/pfsense-practice-lab
chmod 700 lab.sh
sh lab.sh help
```

If `tar` cannot read the ZIP, extract it on your workstation and copy the directory. No package installation is required by the exercise.

For a blind exercise, keep `INSTRUCTOR-ANSWER-KEY.md` and the instructor's inventory output off the student VM. A student with root access can still read the on-host reset manifest. This is an honor-based practice exercise, not a tamper-resistant competition engine.

## 3. Run preflight and preview

Use the **pfSense configuration key**, such as `lan` or `opt1`, not a NIC name such as `vtnet1` or a friendly description such as `TRAINING`.

```sh
cd /root/pfsense-practice-lab
sh lab.sh preflight --interface lan --port 46290
sh lab.sh seed --interface lan --port 46290
```

Preflight requires a standalone firewall, native root CLI, expected native APIs/utilities, and an enabled internal interface with static IPv4 and no configured upstream gateway. WAN is refused. It checks for existing TCP listeners on the proposed port and for unsafe state directories. It does not inspect every possible package configuration or prove that the interface is isolated.

`seed` without `--apply` prints a plan. It does not create accounts, artifacts, backups, or a manifest. The shell may create a root-only lock file under `/var/run/`; pfSense library reads may update their normal caches.

If port `46290` is in use, choose another unused high port and use that same port for preflight and seed. The interface must exist already; the script does not create or readdress it.

## 4. Choose the exercise mode

### Learning mode: compare a known clean baseline

If you have the earlier `first30-pfsense.sh`, collect a baseline **before** applying the seed:

```sh
env VAULT=/root/lab-learning-01 sh /root/first30-pfsense.sh all
```

Record the baseline seal/output as instructed by that script's own guide. Use a fresh vault path for this exercise. `all` performs its reconnaissance and backup workflow; it does not apply this lab's seed.

Then apply the lab seed:

```sh
cd /root/pfsense-practice-lab
sh lab.sh seed --interface lan --port 46290 --apply --lab-confirmed --console-ready
```

Afterward, investigate against the same vault:

```sh
env VAULT=/root/lab-learning-01 sh /root/first30-pfsense.sh hunt
```

Read the complete account inventory even if a summary does not flag each new privilege. Also inspect the firewall, cron, startup files, SSH keys, and filesystem. A fingerprint change is evidence to investigate, not an automatic finding of malicious activity.

### Competition mode: inherit an already altered firewall

Have the instructor apply the seed first. Start your timer afterward, then collect an initial baseline in a **new** vault:

```sh
env VAULT=/root/lab-inherited-01 sh /root/first30-pfsense.sh all
env VAULT=/root/lab-inherited-01 sh /root/first30-pfsense.sh hunt
```

Here, the baseline already includes the exercise artifacts. Diffs alone will not identify the original compromise. Compare the machine against an approved account list, service inventory, and policy. Give the learner `STUDENT-EXERCISE.md` and keep the answer key separate.

You can do either mode with ordinary pfSense GUI and shell inspection instead of the earlier script.

## 5. Seed verification and grading

The apply command reports success only after checking that both OS accounts exist with the expected UIDs, the generated account key exists, the rule tracker appears in active PF output, and the scheduled command appears in `/etc/crontab`. Ordinary failures trigger an attempted selective cleanup. Review any errors before handing the VM to a learner.

Instructor commands:

```sh
sh lab.sh status
sh lab.sh grade
```

`status` shows all eight categories and the current run phase. `grade` uses the same inventory, returning exit `2` while recorded artifacts remain and `0` when none are observed. These commands reveal exercise details; save them for the instructor or the end of the session.

This is **artifact-removal grading**. Disabling a user, neutralizing a rule, or restricting a cron job can be valid containment while the inventory still reports the artifact. Explain containment in your incident report, then use reset to remove the remaining exercise resources. A moved/renamed artifact, copied key, or unrelated real compromise is outside this inventory's guarantees.

## 6. Reset after practice

End any sessions or processes running as either generated account. Keep your ordinary root/admin console open. Pause concurrent GUI configuration edits while seed/reset is running.

Preview first:

```sh
cd /root/pfsense-practice-lab
sh lab.sh reset
```

Apply and verify:

```sh
sh lab.sh reset --apply
sh lab.sh verify-reset
```

Reset reads the current configuration. It removes the two recorded username/UID pairs, their group membership references, the exercise-tagged firewall rules and cron commands, and the three generated exercise file paths. It regenerates cron and the PF ruleset, then checks the live inventory. It does **not** restore the entire old configuration file over your work.

Reset also:

- Refuses identity conflicts, such as a recorded username now using a different UID, a UID assigned to another user, or the firewall tracker now lacking its lab label.
- Refuses while a process uses a generated UID. It does not terminate other users or run a blanket kill command.
- Restores `system/nextuid` only if it still equals the value set by the seed; later allocator changes are preserved.
- Saves the current contents of each exercise file under the run's `retained/` directory before removing its original path. This preserves student edits too.
- Deletes generated OS accounts with `pw userdel` **without `-r`**. It secures each generated home directory as root-only and tries to move it into `retained/`. If a cross-filesystem move is unavailable, it leaves the home in place as root-only and prints the path. Contents are preserved without recursive chown or deletion.
- Unlinks a symlink at a recorded exercise-file path without following the target, retaining the link text for review. A changed parent symlink causes refusal.

A normal repeated reset is safe. If someone later reuses an old exercise UID or marker, the conservative ownership checks can block a repeated reset; resolve the conflict or revert the clean VM snapshot rather than bypassing ownership checks.

**Selective reset preserves your unrelated practice changes.** For an exact return to the original machine, power off and revert `pfsense-clean-before-lab`. Snapshot restore also removes your practice changes, retained files, and later activity.

## 7. State, backups, and interrupted runs

State lives under `/conf/ccdc-practice/` with root-only access. On systems where `/conf` is a standard link, the implementation uses its resolved directory. Each run contains:

| Relative path within the state directory | Contents |
|---|---|
| `active.json` | Current run ID |
| `<timestamp>-<token>/manifest.json` | Resource identities, phase, public key, fingerprints |
| `<timestamp>-<token>/config.before.xml` | Sensitive original pfSense configuration |
| `<timestamp>-<token>/master.passwd.reference` | Sensitive original password-hash reference |
| `<timestamp>-<token>/retained/` | Preserved exercise-file contents and account homes |

Keep this directory private. The configuration backup and password-hash reference may contain real secrets from your lab. They are not automatically deleted or automatically restored. Logs and pfSense configuration history also remain after selective reset.

Run phases include `prepared`, `config-writing`, `config-written`, `seeded`, `seed-failed`, `resetting`, `reset-required`, and `reset`. The journal is written before artifact mutation. If the shell is interrupted or the VM reboots during seed/reset:

```sh
cd /root/pfsense-practice-lab
sh lab.sh status
sh lab.sh reset
sh lab.sh reset --apply
sh lab.sh verify-reset
```

Do not delete the manifest to make an error disappear. The program intentionally refuses to infer ownership when the journal is missing or unsafe. Use the clean VM snapshot if state cannot be trusted. A new seed is blocked while the last run is incomplete or still contains recorded live artifacts.

The program serializes its own launcher invocations with `lockf` and checks the configuration digest before writes. That does not make it transactional with independent GUI/PHP edits; leave the GUI idle during changes.

## 8. Troubleshooting

| Message or symptom | Action |
|---|---|
| Native API or utility unavailable | Stop on this clone; record the pfSense version and missing item. Do not substitute raw `/etc/master.passwd`, `/etc/crontab`, or generated PF edits. |
| Interface rejected | Use `lan`/`optN`, confirm enabled static IPv4, remove upstream role from the lab design, and verify isolation. |
| Port already listening | Select an unused high port. Do not stop a required service merely to use the default. |
| An exercise is active | Review `status`, then reset it before creating a fresh run. |
| Configuration changed during planning | Pause other configuration activity and retry. If seed already began, finish reset first. |
| Process under seeded UID | Inspect the processes and close only those lab-account sessions before retrying. |
| Tracker/account identity conflict | Compare the manifest, current config, and evidence. Resolve ownership explicitly or use the snapshot. Do not delete a replacement user/rule by guess. |
| Native verification failed | Read the reported cleanup result, then `status` and `reset --apply`. Complete the smoke test before reusing the package. |
| Parent symlink/state permission refusal | Investigate the changed path or restore the snapshot. Avoid a broad recursive permission fix. |
| Lost management access | Use the hypervisor console. If recovery is uncertain, power off and revert the clean snapshot. |

Exit codes: `0` success; `1` error/incomplete verification; `2` recorded artifacts remain for `grade`/`verify-reset`. `status` returns `0` for a readable inventory even when artifacts remain. `verify-reset` requires a recorded run with phase `reset`; it does not claim success on a machine with no manifest.

## Native design references

These primary sources informed the adapter; they do not replace testing your installed release:

- [Official pfSense installation/download](https://www.pfsense.org/download/).
- [Netgate: boot commands and executable startup scripts](https://docs.netgate.com/pfsense/en/latest/development/boot-commands.html). Startup `.sh` files can also run during interface/gateway events, which is why this lab's hook only logs and exits.
- [pfSense `system_usermanager.php`](https://github.com/pfsense/pfsense/blob/master/src/usr/local/www/system_usermanager.php): managed-user records and `system/nextuid` allocation.
- [pfSense `auth.inc`](https://github.com/pfsense/pfsense/blob/master/src/etc/inc/auth.inc): `local_user_set`, generated authorized keys, and native deletion behavior.
- [pfSense `services.inc`](https://github.com/pfsense/pfsense/blob/master/src/etc/inc/services.inc): `configure_cron` regenerates the scheduled-task file from configuration.
- [pfSense `filter.inc`](https://github.com/pfsense/pfsense/blob/master/src/etc/inc/filter.inc): synchronous ruleset reload API and its state-deletion argument.

The package is independent practice material, not an official CCDC image or Netgate tool.
