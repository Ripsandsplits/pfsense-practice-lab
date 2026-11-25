# Student exercise: inherited firewall

You have inherited a pfSense firewall at the start of a CCDC-style session. Keep the approved network functions available, investigate unauthorized changes, and document what you find. Your instructor supplies the approved administrator list, expected network diagram, service inventory, and management workstation addresses.

Time budget: **45–60 minutes**, adjustable by your instructor. No exploitation or external attack is necessary. Work only on the isolated practice network.

## Your deliverables

1. An initial inventory of interfaces, firewall policy, users/privileges, SSH access, scheduled tasks, startup hooks, and essential services.
2. A finding list explaining each suspicious item, its location, its effect, supporting evidence, and your confidence.
3. A change log recording what you changed, why, how you tested it, and how to undo it.
4. A short incident report and a completed business inject.

## Investigation sequence

**First 10 minutes:** confirm console recovery, record the expected addressing, export an initial configuration backup, inventory accounts and privileges, and test management plus the instructor's business services. Treat the inherited configuration as evidence, not proof that every setting is approved.

**Next 15–25 minutes:** inspect user-level and group-inherited privileges, configured SSH keys and generated key files, rules that allow access to the firewall itself, root scheduled jobs, executable startup files, unexpected files under the root home, and unexpected web-directory content. Capture a file hash and relevant metadata before removing an item. A change-detection script helps when you have a trusted earlier baseline; manual policy review remains necessary.

**Next 10–15 minutes:** contain and remediate the suspicious resources. Make one explainable change at a time and retest normal access. Preserve evidence before deletion. Do not restart every service or log out your own recovery session as a routine first action.

**Final 10 minutes:** complete the report, respond to the inject, and have the instructor run the inventory check. Be ready to explain an item you contained but left present for evidence.

If you use the previously supplied `first30-pfsense.sh`, read its guide first. `all`, `hunt`, `evidence`, `note`, and `report` can support the workflow. Read the complete account inventory and inspect privileges yourself. An already-compromised initial baseline may produce few useful differences.

## Finding worksheet

| ID | Item/location | Expected behavior | Observed behavior | Evidence | Action and validation |
|---|---|---|---|---|---|
| F-01 | | | | | |
| F-02 | | | | | |
| F-03 | | | | | |
| F-04 | | | | | |

Do not copy real password hashes, private keys, or full sensitive configuration into a business report. Cite the protected evidence location and include only necessary excerpts.

## Inject A: management access review

At minute 15, the business manager asks:

> Provide a list of accounts that can administer the firewall, explain whether each is approved, and recommend how administrator access should be controlled. Keep the current operations team able to manage the network. Submit within 15 minutes.

Submit a one-page Markdown or PDF report with the account/privilege table, your recommendation, changes already made, and validation results. Distinguish direct privileges from inherited group privileges. Do not include passwords or private keys.

## Inject B: scheduled-task incident

At minute 30, the manager reports recurring unexplained system-log entries and requests a brief incident timeline.

Within 10 minutes, identify the producing mechanism, explain what it actually does, preserve a relevant configuration/file excerpt, describe remediation, and show that approved scheduled jobs still work. Do not claim data exfiltration or remote control without evidence.

## Availability checks

Record before/after results for GUI access, approved SSH access if enabled, DHCP if used, DNS resolution, required routing/NAT, and the instructor's HTTP or other business service. Use known lab endpoints; Internet access is not required for this exercise.

Do not open the answer key or the seeding manifest during the blind phase. Root can read these, so this boundary depends on the agreed exercise rules. The instructor runs `grade` after your investigation; the exercise's selective reset follows after evidence review.
