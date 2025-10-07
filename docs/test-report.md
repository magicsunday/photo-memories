# Test Report

Date: 2025-10-07T15:26:08Z

## Command

```
composer ci:test
```

## Result

The aggregate CI run still fails because PHPStan aborts with 897 type-safety violations across multiple cluster and geocoding classes. The error log highlights uninitialised readonly properties as well as redundant type checks reported by level 9 rules.【20521e†L1-L88】【20521e†L214-L232】
