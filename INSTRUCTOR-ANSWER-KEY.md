# Instructor answer key — exercise spoilers

Keep this file off the student VM for a blind exercise. Root access can still reveal the required reset manifest. Resource names and labels are intentionally clear; this exercise teaches investigation and careful remediation, not concealment.

## Establish the expected state

Before seeding, record legitimate administrators/groups, management sources, interface roles, essential services, approved rules, scheduled jobs, and important files. Preserve a powered-off clean snapshot. Complete `VALIDATION.md` on a disposable clone first.

`sh lab.sh status` reveals the eight category results. The current run ID is in `/conf/ccdc-practice/active.json`; its root-only `manifest.json` records the token, two account identities, tracker, interface, port, public key, and file fingerprints. Do not edit the manifest during the exercise.

## Artifact map

Replace `<token>`, `<account>`, and `<tracker>` with actual values. Commands below are read-only inspection examples; avoid pasting angle-bracket placeholders literally.

| Category | Where to inspect | Expected finding | Remediation/completion |
|---|---|---|---|
| Privileged user | System → User Manager → Users; config `system/user` | `ccla_<token>` has direct `page-all`, nonzero UID | Preserve account evidence; remove unauthorized privilege/account through the managed-user workflow. Reset removes its matched config/OS identity. |
| Shell user | User Manager and OS account inventory | `cclk_<token>` has `user-shell-access` | Remove unauthorized shell access or account after evidence. Inventory remains present until the recorded account is removed. |
| SSH public key | User Manager user's authorized keys; `/home/cclk_<token>/.ssh/authorized_keys` | `CCDC-LAB:<token>` with forced false command, `restrict`, and loopback restriction | Remove the managed-user key and regenerate through supported user management, or remove the generated user. The script never changes existing users' keys. |
| Firewall rule | Firewall → Rules → selected interface; active PF output | Description `CCDC-LAB:<token> exercise allow`, any IPv4 source to firewall self, TCP high port | Remove the unauthorized rule and apply changes. Do not delete the interface's legitimate management/service rules. |
| Cron job | Config `cron/item`; `/etc/crontab` | Root logger command every five minutes with the run tag | Remove the tagged configuration entry using a supported native configuration workflow, then regenerate cron. Reset does this. Editing only `/etc/crontab` does not persist across regeneration. |
| Startup hook | `/usr/local/etc/rc.d/99-ccdc-lab-<token>.sh` | Executable shell file, logger only, exits 0 | Preserve metadata/content/hash, then remove the hook. It is not a daemon and has no listener. |
| Fake credentials | `/root/ccdc-lab-<token>-credentials.txt` | Clearly fake training username/password | Preserve evidence, remove the file, explain why plaintext credential-like files need review. These values do not authenticate the new accounts. |
| Web text file | `/usr/local/www/ccdc-lab-<token>.txt` | Plain text marker under the GUI document tree | Preserve and remove; verify the GUI still works. It is not a web shell. Reachability depends on GUI/network configuration. |

Privilege inheritance can affect effective permissions beyond direct `priv` fields. The two accounts have random undisclosed passwords; no working attacker password or private SSH key is supplied. Do not require students to prove login as either account.

## Useful read-only inspection

From the root shell:

```sh
pw usershow -a
ps -axo uid,pid,command
sockstat -46 -l -P tcp
pfctl -vvsr
cat /etc/crontab
ls -la /usr/local/etc/rc.d
ls -la /root
ls -la /usr/local/www
```

For an identified file, use the actual complete path:

```sh
stat /usr/local/etc/rc.d/99-ccdc-lab-ACTUALTOKEN.sh
sha256 /usr/local/etc/rc.d/99-ccdc-lab-ACTUALTOKEN.sh
sed -n '1,100p' /usr/local/etc/rc.d/99-ccdc-lab-ACTUALTOKEN.sh
```

Inspect `/conf/config.xml` in a protected viewer or through native read-only configuration APIs. It contains secrets. Do not paste the full file into a report or edit it with broad text replacements. GUI-managed users/keys/rules should be changed through native management so generated state is synchronized.

If a Cron package is already installed, its supported GUI can expose the scheduled task. The lab does not install it. If no cron UI is present, students can identify and document the entry while the instructor uses `reset --apply` for supported cleanup through this package's native API adapter.

## Working with the earlier first30 script

With the same vault used for the clean baseline:

```sh
env VAULT=/root/lab-learning-01 sh /root/first30-pfsense.sh hunt
env VAULT=/root/lab-learning-01 sh /root/first30-pfsense.sh facts
env VAULT=/root/lab-learning-01 sh /root/first30-pfsense.sh report
```

Use `evidence` with an actual suspicious file path if desired. `user <actual-account-name>` enters the earlier script's managed-user workflow; consult its menu/usage guide before selecting an action. Some user deletion paths can remove homes, so preserve evidence/student files first. The lab's own reset retains generated homes and avoids recursive home deletion.

Do not use a whole-firewall restore as a substitute for selective artifact cleanup during the exercise. It can erase unrelated changes and evidence. If the initial baseline was collected after seeding, a clean diff is not an absence of artifacts.

## Grading

The built-in `grade` is an instructor **presence inventory**, not a full competition score. It reports eight categories, not eight independent objects: deleting the shell account and its key can resolve two categories. Neutralized-but-present artifacts still count as present. The inventory tracks recorded identities/markers; it is not a general compromise detector.

Suggested human rubric:

| Area | Points | Evidence |
|---|---:|---|
| Investigation | 32 | Identify and accurately explain the eight categories; 4 each |
| Evidence handling | 16 | Relevant metadata, hashes, configuration excerpts, and protected evidence paths |
| Remediation reasoning | 16 | Least necessary change; persist managed changes; preserve recovery access |
| Availability | 16 | Before/after tests of the approved management and business services |
| Injects | 12 | Complete, accurate, timely management-access review and scheduled-task timeline |
| Change log/report | 8 | Clear trigger, action, validation, remaining uncertainty, and rollback notes |

Do not award an exfiltration finding for a logger command or a remote-shell finding for the text marker. Reward distinguishing a harmless simulation from the corresponding risky real-world configuration pattern.

## Reset and replay

After collecting the student's evidence:

```sh
cd /root/pfsense-practice-lab
sh lab.sh reset
sh lab.sh reset --apply
sh lab.sh verify-reset
```

Review preserved files/homes in the printed retained locations. Selective reset leaves logs, configuration history, backups, and unrelated student changes. The state manifest remains root-only and should not be removed until you no longer need selective cleanup or retained evidence.

To repeat with the same base network and fresh resource names, run a new seed after successful reset. To reproduce the original machine exactly, revert the clean hypervisor snapshot. If reset refuses an account/tracker/path identity conflict, investigate it instead of changing the manifest to force deletion.
