# Pull request preview plugin

Every open pull request gets an installable Community Applications preview at:

```text
https://raw.githubusercontent.com/unraid/community.applications/pr-previews/pr/<PR_NUMBER>/community.applications.plg
```

Paste that URL into **Plugins > Install Plugin** on a test Unraid server. The
preview keeps the normal `community.applications` plugin identity, so it upgrades
the existing installation in place and preserves CA settings. Installing the
released plugin again returns the server to the stable build.

The build runs with read-only repository access. A separate trusted workflow
downloads the completed artifact without executing pull-request code and
publishes it to the `pr-previews` branch. A closed pull request removes its
published files automatically.

Preview builds are test artifacts, not releases. Use them only on a server where
an in-place Community Applications upgrade is acceptable.
