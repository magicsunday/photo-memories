# Test Report

Date: 2025-10-07T19:10:59+00:00

## Command

```
composer ci:test
```

## Result

The aggregate CI run scheitert weiterhin, weil PHPStan mit 913 Meldungen zu deterministischen Typprüfungen abbricht. Auffällig sind redundante `is_*`-Guards, nutzlose Casts und fehlende Value-Typen in Array-PHPDocs quer durch Cluster- und Feed-Module.【cab01a†L1-L11】【b3afa9†L1-L120】
