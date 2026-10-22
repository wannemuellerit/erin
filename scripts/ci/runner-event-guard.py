#!/usr/bin/env python3
"""Reject untrusted public-repository jobs before any workflow code runs."""
import json
import os
from pathlib import Path

REPOSITORY = 'wannemuellerit/erin'
REPOSITORY_ID = 1304373324
EVENT_DIRECTORY = Path('/opt/github-runner/erin/_work/_temp/_github_workflow')


def own_repository(value):
    return (isinstance(value, dict)
            and value.get('id') == REPOSITORY_ID
            and value.get('full_name') == REPOSITORY)


def authorize_event(event_name, payload, repository, ref):
    if repository != REPOSITORY or not own_repository(payload.get('repository')):
        raise RuntimeError('Repository identity does not match this CI runner')
    if event_name == 'pull_request':
        pull_request = payload.get('pull_request', {})
        if (not own_repository(pull_request.get('head', {}).get('repo'))
                or not own_repository(pull_request.get('base', {}).get('repo'))):
            raise RuntimeError('Fork pull requests are not permitted on this CI runner')
    elif event_name == 'push':
        if not ref.startswith('refs/heads/') or payload.get('ref') != ref:
            raise RuntimeError('Only repository branch pushes are permitted')
    elif event_name == 'workflow_dispatch':
        if not ref.startswith('refs/heads/'):
            raise RuntimeError('Manual CI runs must target a repository branch')
    elif event_name == 'schedule':
        if ref != 'refs/heads/main':
            raise RuntimeError('Scheduled CI must use the default branch')
    else:
        raise RuntimeError('This event type is not permitted on the public CI runner')


def main():
    event_path = Path(os.environ['GITHUB_EVENT_PATH']).resolve(strict=True)
    if event_path.parent != EVENT_DIRECTORY or event_path.name != 'event.json':
        raise RuntimeError('Unexpected GitHub event payload path')
    payload = json.loads(event_path.read_text())
    authorize_event(os.environ['GITHUB_EVENT_NAME'], payload,
                    os.environ['GITHUB_REPOSITORY'], os.environ['GITHUB_REF'])
    print('Public-repository CI event accepted; fork execution is blocked.', flush=True)
    os.execv('/usr/bin/python3', ['/usr/bin/python3',
                                '/usr/local/lib/github-runner/runner-job-lock.py'])


if __name__ == '__main__':
    main()
