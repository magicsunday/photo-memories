# Upgrade guide

## PHPUnit 12 structured metadata assertions

- **Breaking change:** `MagicSunday\\Memories\\Service\\Metadata\\StructuredMetadataSection::get()` has been removed. Sections expose their values as read-only properties to align with PHPUnit 12's property assertions.
- Adjust custom tests and scripts to access values via property chains (e.g. `$meta->camera->summary`, `$meta->exposure->aperture_text`).
- Snapshot and fixture suites for EXIF metadata versions 1.0 through 3.0 remain unchanged; only the access pattern within assertions shifts to the new property syntax (`$meta->…->…`).
- When porting legacy assertions, verify that nullable keys still resolve to `null` and that list-valued keys return arrays identical to their stored snapshots.
