---
name: Bug report
about: Report unexpected behaviour in this package
title: ''
labels: bug
assignees: ''
---

**Describe the bug**

A clear description of what's wrong and what you expected instead.

**Environment**

- Package version (e.g. `v7.0.0`):
- Laravel version (e.g. `12.64.0`):
- PHP version (e.g. `8.3.30`):
- ODBC driver in use (e.g. Cloudera Hive ODBC driver, version):

The ODBC driver matters here more than it would for most packages: this
package cannot be tested against a real Hive server by its maintainer (see
[`docs/limitations.md`](docs/limitations.md)), so knowing exactly which
driver and version you're connecting through is often the difference between
"this is a bug in the package" and "this is how that driver behaves."

**Generated SQL, if known**

If the issue involves a query or schema statement, please include the SQL
this package generated (enable query logging, or dump the result of
`->toSql()` / the relevant grammar method). If you don't know how to get
this, say so — it's still useful to report without it.

**Minimal reproduction**

A small, self-contained code sample that reproduces the issue — ideally a
query builder call, migration, or `Schema::` call, plus the connection
configuration you're using (with anything sensitive redacted).

```php
// your reproduction here
```

**Additional context**

Anything else that seems relevant (stack trace, Hive version, whether this
worked before, etc.).
