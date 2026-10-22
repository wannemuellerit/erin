# Contabo CI runner

Regular Linux checks use `CI_RUNNER_LABELS` when the event is trusted. Its value is
`["self-hosted","Linux","X64","ci-vps"]`. Deleting the repository variable restores
GitHub-hosted runners for future regular jobs. Fork pull requests keep their
GitHub-hosted runners; the explicit VPS smoke test is skipped for forks.

Jobs share the VPS with other repositories. A host-side pre-job lock serializes
jobs before service containers start, avoiding port and tool-install conflicts.
The completion hook clears temporary global registry credentials. The
`VPS runner smoke test` workflow checks the connection and Docker explicitly.

All jobs in `tests.yml` and `security.yml` use the VPS for trusted events.
Timeouts allow for the shared host's serial queue. Release-image publication and
Dependabot auto-merge keep their existing GitHub-hosted runners. Deployment and
backup continue to require their separate `erin` and environment labels, which
this CI runner deliberately does not have.

This repository is public. The runner's host-owned pre-job guard independently
checks GitHub's event payload before the shared-host lock or any workflow step.
It accepts same-repository pull requests, branch pushes, manual dispatches and
scheduled runs; it rejects fork pull requests, mismatched repository IDs and
unsupported event types. Merely editing the routing expression in a fork does not
permit execution on this host. The runner registration is repository-scoped.

Host guard reference:
https://docs.github.com/en/actions/how-tos/manage-runners/self-hosted-runners/run-scripts

The reviewed guard source is `scripts/ci/runner-event-guard.py`; its regression
tests are `tests/ci/test_runner_event_guard.py` and run in regular CI. On the VPS
it is installed as root-owned `/usr/local/lib/github-runner/erin-runner-guard.py`,
called by the registration's configured pre-job hook. Editing the repository
copy does not replace that host-owned guard automatically.

A rejected event skips shared credential cleanup. Completion also verifies that
the current worker owns the shared CI slot, so a cancelled job that was still
waiting cannot clear credentials used by a different active job. The setup-php
action is pinned to the same Ubuntu-compatible revision validated on the other
VPS projects.
