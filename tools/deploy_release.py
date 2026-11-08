"""Stage an AEO Ultimate release in the wordpress.org SVN working copy.

    python tools/deploy_release.py 2.33.3
    python tools/deploy_release.py 2.33.3 --dry-run     # change nothing, just report
    python tools/deploy_release.py 2.33.3 --svn D:/svn/twt-aeo

What it does:

0. Runs `svn update` on the working copy first. This is not optional
   housekeeping -- skipping it once silently built a tag from a pre-release
   snapshot. The trunk *directory* entry can sit a revision behind trunk's own
   last commit, and `svn copy trunk tags/<v>` then copies that stale snapshot,
   re-adding files as "A +" and burying the real diff. Pass --no-update only
   if you have just updated by hand.
1. Mirrors the files git tracks in the plugin repo into trunk/: new files
   added, files no longer tracked deleted, and anything in EXCLUDE_FILES /
   EXCLUDE_PREFIXES held back (.gitignore, tests/, tools/).
2. Reverts files whose only change is line endings, so CRLF/LF churn from
   git's checkout does not bury the real diff.
3. Copies trunk/ to tags/<version>/.

It never commits. Review `svn status`, confirm only the files you expect are
listed plus one clean tag copy, then commit yourself:

    svn commit trunk tags/<version> -m "..." --username <you> --non-interactive

Paths: the plugin repo is wherever this script lives (its parent directory).
The SVN working copy comes from --svn, else $TWTAEO_SVN_DIR, else the
DEFAULT_SVN_DIR below.
"""
import argparse
import os
import shutil
import subprocess
import sys

# The plugin repo: the directory above tools/. Derived, not hardcoded, so a
# clone anywhere works.
SRC = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Where the wp.org SVN checkout usually lives on the maintainer's machine.
# Override with --svn or $TWTAEO_SVN_DIR rather than editing this.
DEFAULT_SVN_DIR = os.path.join(os.path.expanduser('~'), 'Desktop', 'twt-aeo-svn')

# Tracked in git but never shipped to wp.org, because they are developer-only:
# the security regression tests under tests/ create posts and poke plugin
# internals, and tools/ holds this script. Keep these in step with the
# directory layout -- if you move either one, update this list or it ships.
EXCLUDE_FILES = {'.gitignore'}
EXCLUDE_PREFIXES = ('tests' + os.sep, 'tools' + os.sep)


def run(args, cwd, check=True):
    return subprocess.run(
        args, cwd=cwd, capture_output=True, text=True,
        encoding='utf-8', errors='replace', check=check,
    ).stdout


def release_files():
    """The files git tracks, minus the developer-only ones."""
    wanted = set()
    skipped = []
    for line in run(['git', 'ls-files'], cwd=SRC).split('\n'):
        if not line:
            continue
        rel = line.replace('/', os.sep)
        if rel in EXCLUDE_FILES or rel.startswith(EXCLUDE_PREFIXES):
            skipped.append(rel)
            continue
        wanted.add(rel)
    return wanted, skipped


def check_paths(work):
    if not os.path.isdir(os.path.join(SRC, '.git')):
        sys.exit('not a git repo: %s\n(expected this script at <repo>/tools/)' % SRC)
    if not os.path.isdir(work):
        sys.exit('SVN working copy not found: %s\n(pass --svn or set TWTAEO_SVN_DIR)' % work)
    for sub in ('trunk', 'tags', '.svn'):
        if not os.path.isdir(os.path.join(work, sub)):
            sys.exit('%s is not a wp.org SVN checkout (no %s/)' % (work, sub))


def main():
    parser = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    parser.add_argument('version', help='release version, e.g. 2.33.3')
    parser.add_argument('--svn', default=os.environ.get('TWTAEO_SVN_DIR', DEFAULT_SVN_DIR),
                        help='the wp.org SVN working copy')
    parser.add_argument('--dry-run', action='store_true',
                        help='report what would be staged and change nothing')
    parser.add_argument('--no-update', action='store_true',
                        help='skip the svn update (only if you just updated by hand)')
    args = parser.parse_args()

    work = os.path.abspath(args.svn)
    trunk = os.path.join(work, 'trunk')
    tag = os.path.join(work, 'tags', args.version)

    check_paths(work)
    print('plugin repo:', SRC)
    print('svn copy   :', work)

    wanted, skipped = release_files()

    if args.dry_run:
        print('\n-- dry run, nothing written --')
        print('release files:', len(wanted))
        print('excluded from release:', len(skipped), sorted(skipped))
        if os.path.exists(tag):
            print('WARNING: tags/%s already exists; a real run would refuse' % args.version)
        present = set()
        for root, dirs, names in os.walk(trunk):
            dirs[:] = [d for d in dirs if d != '.svn']
            for name in names:
                present.add(os.path.relpath(os.path.join(root, name), trunk))
        added = sorted(wanted - present)
        removed = sorted(present - wanted)
        print('would add to trunk   :', len(added), added[:20])
        print('would delete in trunk:', len(removed), removed[:20])
        leaked = sorted(w for w in wanted if w.split(os.sep)[0] in ('tests', 'tools'))
        print('developer files leaking into the release:', leaked if leaked else 'none')
        return

    if os.path.exists(tag):
        sys.exit('tags/%s already exists' % args.version)

    if args.no_update:
        print('skipping svn update (--no-update)')
    else:
        print('svn update:', run(['svn', 'update'], cwd=work).strip().splitlines()[-1])

    # Delete what trunk has that the release no longer does.
    removed = []
    for root, dirs, names in os.walk(trunk):
        dirs[:] = [d for d in dirs if d != '.svn']
        for name in names:
            rel = os.path.relpath(os.path.join(root, name), trunk)
            if rel not in wanted:
                removed.append(rel)
    for rel in removed:
        run(['svn', 'delete', '--force', os.path.join('trunk', rel)], cwd=work)

    # Copy every release file into trunk.
    for rel in wanted:
        dst = os.path.join(trunk, rel)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        shutil.copyfile(os.path.join(SRC, rel), dst)
    run(['svn', 'add', '--force', '--parents', 'trunk'], cwd=work, check=False)

    # Directories emptied by the deletions.
    for root, dirs, names in os.walk(trunk, topdown=False):
        if '.svn' in root.split(os.sep):
            continue
        entries = [e for e in os.listdir(root) if e != '.svn']
        if not entries and root != trunk:
            run(['svn', 'delete', '--force', root], cwd=work, check=False)

    # Line-ending-only changes: revert, so the diff shows real edits only.
    reverted = []
    for line in run(['svn', 'status', 'trunk'], cwd=work).splitlines():
        if line[:1] == 'M':
            path = line[8:].strip()
            if not run(['svn', 'diff', '-x', '--ignore-eol-style', path], cwd=work).strip():
                run(['svn', 'revert', path], cwd=work)
                reverted.append(path)

    run(['svn', 'copy', 'trunk', 'tags/' + args.version], cwd=work)

    counts = {}
    for line in run(['svn', 'status'], cwd=work).splitlines():
        counts[line[:1]] = counts.get(line[:1], 0) + 1
    print('release files:', len(wanted))
    print('excluded from release:', len(skipped), sorted(skipped))
    print('removed from trunk:', len(removed), removed[:20])
    print('line-ending-only files reverted:', len(reverted))
    print('svn status:', counts)
    print('\nreview `svn status`, then commit trunk and tags/%s' % args.version)


if __name__ == '__main__':
    main()
