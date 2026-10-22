# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Databasement, please report it responsibly:

1. **Do not** open a public GitHub issue for security vulnerabilities
2. Use [GitHub's private vulnerability reporting](https://github.com/David-Crty/databasement/security/advisories/new) to submit the details (include steps to reproduce if possible)
3. Allow reasonable time for a fix before public disclosure

We appreciate your help in keeping Databasement secure.

## Scope

This policy applies to:
- The Databasement application code
- Official Docker images
- Official Helm charts

Third-party dependencies are managed through regular updates but are outside the direct scope of this policy.

Demo mode (`DEMO_MODE`) is also out of scope. It is off by default and exists only to run the public demo instance, where a shared account with known credentials that can download, delete and restore snapshots is intended. Enabling it on a real deployment is not a supported configuration.
