# Security policy

## Supported versions

The latest minor release receives security fixes. Before 1.0, that is the
latest 0.x release.

## Reporting a vulnerability

Please do not open a public issue. E-mail **hello@indianopinion.org** with:

- a description of the problem and its impact,
- a minimal input or script that reproduces it,
- the PHP version, extensions (intl, mbstring) and library version.

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days. We will credit you in the release notes unless
you ask us not to.

## Scope

Examples of what counts: input that makes hashing take far longer than its
size suggests, memory growth beyond linear, a crash, or a way to make
`isNearDuplicate` return true for an empty or too-short input.

Not a vulnerability: the fact that similar texts have similar fingerprints,
or that a short text can be guessed from its fingerprint. That is how simhash
works, and the README says so.
