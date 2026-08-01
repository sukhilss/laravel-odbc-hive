## Description

What does this change do, and why?

## Related issue

Closes #

## Checklist

Please confirm the three local gates pass — all run through
`docker compose run --rm php` (see
[`docs/local-development.md`](docs/local-development.md)):

- [ ] `composer test` passes
- [ ] `composer lint` passes
- [ ] `composer analyse` passes (PHPStan level 6, no baseline — new
      violations must be fixed or suppressed with a written justification)
- [ ] Tests were added or updated to cover this change
- [ ] Any new version-dependent code is confined to one of the four
      permitted sites (see [`CONTRIBUTING.md`](CONTRIBUTING.md)), not a new
      branch elsewhere
- [ ] Documentation was updated if this changes user-facing behaviour
