import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('guard', Path(__file__).resolve().parents[2] / 'scripts/ci/runner-event-guard.py')
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)
OWN = {'id': 1304373324, 'full_name': 'wannemuellerit/erin'}


class EventGuardTest(unittest.TestCase):
    def test_same_repository_pull_request(self):
        guard.authorize_event('pull_request', {'repository': OWN, 'pull_request':
                              {'head': {'repo': OWN}, 'base': {'repo': OWN}}},
                              guard.REPOSITORY, 'refs/pull/151/merge')

    def test_fork_and_missing_pull_request_rejected(self):
        for head in ({'id': 42, 'full_name': 'attacker/erin'}, None, {},
                     {'id': 42, 'full_name': guard.REPOSITORY},
                     {'id': guard.REPOSITORY_ID, 'full_name': 'attacker/erin'}):
            with self.subTest(head=head), self.assertRaises(RuntimeError):
                guard.authorize_event('pull_request', {'repository': OWN, 'pull_request':
                                      {'head': {'repo': head}, 'base': {'repo': OWN}}},
                                      guard.REPOSITORY, 'refs/pull/151/merge')

    def test_trusted_automatic_and_manual_events(self):
        for event, payload, ref in [
            ('push', {'repository': OWN, 'ref': 'refs/heads/main'}, 'refs/heads/main'),
            ('push', {'repository': OWN, 'ref': 'refs/heads/ci/contabo-runner'}, 'refs/heads/ci/contabo-runner'),
            ('workflow_dispatch', {'repository': OWN}, 'refs/heads/main'),
            ('schedule', {'repository': OWN}, 'refs/heads/main'),
        ]:
            with self.subTest(event=event):
                guard.authorize_event(event, payload, guard.REPOSITORY, ref)

    def test_repository_mismatch_and_missing_identity_rejected(self):
        for repository, identity in [('attacker/erin', OWN), (guard.REPOSITORY, {}),
                                     (guard.REPOSITORY, {'id': 42, 'full_name': guard.REPOSITORY})]:
            with self.subTest(identity=identity), self.assertRaises(RuntimeError):
                guard.authorize_event('push', {'repository': identity, 'ref': 'refs/heads/main'},
                                      repository, 'refs/heads/main')

    def test_other_events_and_untrusted_refs_rejected(self):
        for event, payload, ref in [
            ('pull_request_target', {'repository': OWN}, 'refs/heads/main'),
            ('workflow_run', {'repository': OWN}, 'refs/heads/main'),
            ('issue_comment', {'repository': OWN}, 'refs/heads/main'),
            ('repository_dispatch', {'repository': OWN}, 'refs/heads/main'),
            ('push', {'repository': OWN, 'ref': 'refs/heads/main'}, 'refs/heads/other'),
            ('push', {'repository': OWN, 'ref': 'refs/tags/v1'}, 'refs/tags/v1'),
            ('workflow_dispatch', {'repository': OWN}, 'refs/pull/151/merge'),
            ('schedule', {'repository': OWN}, 'refs/heads/other'),
        ]:
            with self.subTest(event=event, ref=ref), self.assertRaises(RuntimeError):
                guard.authorize_event(event, payload, guard.REPOSITORY, ref)


unittest.main()
