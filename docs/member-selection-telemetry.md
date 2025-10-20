# Telemetrievertrag der Mitgliederselektion

Dieses Dokument beschreibt den Aufbau der Selektor-Telemetrie, die unter
`member_selection` in Cluster-Drafts abgelegt wird, sowie das JSONL-Format der
Monitoring-Ausgaben des `FileJobMonitoringEmitter`.

## Member-Selection-Parameter

Jeder kuratierte Draft enthält unter `member_selection` Diagnosewerte zum
Selektionslauf. Die Struktur führt jetzt für jeden Ausschlussgrund des
policy-gesteuerten Selektors einen expliziten Zähler. Ein Beispiel:

```json
{
  "profile": "default",
  "counts": {
    "pre": 96,
    "post": 64,
    "dropped": 32
  },
  "spacing": {
    "average_seconds": 1185.3,
    "samples": [420, 960, 1800],
    "rejections": 7
  },
  "near_duplicates": {
    "blocked": 4,
    "replacements": 1
  },
  "per_day_distribution": {"2024-06-10": 5},
  "per_bucket_distribution": {"2024-06-10#slot_3": 2},
  "thresholds": {
    "run_day_count": 7,
    "raw_per_day_cap": 9,
    "base_per_day_cap": 6,
    "day_caps": {"2024-06-10": 7, "2024-06-11": 5},
    "day_categories": {"2024-06-10": "core", "2024-06-11": "peripheral"},
    "max_per_staypoint": 3,
    "phash_min_effective": 10,
    "phash_percentile_ratio": 0.35,
    "phash_percentile_threshold": 8,
    "phash_sample_count": 42,
    "spacing_relaxed_to_zero": false,
    "phash_relaxed_to_zero": false
  },
  "day_segments": {
    "2024-06-10": {
      "score": 0.78,
      "category": "core",
      "duration": 32400,
      "metrics": {
        "tourism_ratio": 0.6,
        "poi_density": 0.3
      }
    },
    "2024-06-11": {
      "score": 0.41,
      "category": "peripheral",
      "duration": 28800,
      "metrics": {
        "tourism_ratio": 0.2,
        "poi_density": 0.1
      }
    }
  },
  "run_metrics": {
    "storyline": "vacation.extended",
    "run_length_days": 7,
    "run_length_effective_days": 6,
    "run_length_nights": 5,
    "core_day_count": 4,
    "peripheral_day_count": 3,
    "core_day_ratio": 0.57,
    "peripheral_day_ratio": 0.43,
    "phash_distribution": {"count": 42, "average": 11.2, "median": 10.0, "p90": 16.0, "p99": 21.0, "max": 24.0},
    "people_balance": {
      "enabled": true,
      "weight": 0.35,
      "unique_people": 5,
      "dominant_share": 0.28,
      "penalized": 3,
      "rejected": 1
    },
    "poi_coverage": {
      "poi_day_count": 5,
      "poi_day_ratio": 0.71,
      "poi_type_count": 8,
      "tourism_hits": 42,
      "poi_samples": 58,
      "tourism_ratio": 0.64
    },
    "selection_profile": {
      "profile_key": "vacation_default",
      "target_total": 60,
      "minimum_total": 36,
      "max_per_day": 6,
      "min_spacing_seconds": 2400,
      "phash_min_hamming": 9,
      "people_balance_weight": 0.35
    },
    "selection_pre_count": 96,
    "selection_post_count": 60
  },
  "metrics": {
    "phash_samples": [4, 7, 9, 12]
  },
  "options": {
    "selector": "…\\PolicyDrivenMemberSelector",
    "target_total": 60,
    "max_per_day": 4,
    "time_slot_hours": 3,
    "min_spacing_seconds": 3600,
    "phash_min_hamming": 11,
    "phash_percentile": 0.8,
    "spacing_progress_factor": 0.5,
    "max_per_staypoint": 2,
    "core_day_bonus": 1,
    "peripheral_day_penalty": 1,
    "cohort_repeat_penalty": 0.1,
    "video_bonus": 0.3,
    "face_bonus": 0.2,
    "selfie_penalty": 0.1,
    "quality_floor": 0.4
  },
  "hash_samples": {"123": "ffeedd"},
  "exclusion_reasons": {
    "time_gap": 9,
    "day_quota": 4,
    "time_slot": 2,
    "phash_similarity": 3,
    "staypoint_quota": 2,
    "orientation_balance": 1,
    "scene_balance": 2,
    "people_balance": 4
  },
  "telemetry": {
    "counts": {"pre": 96, "post": 64, "dropped": 32},
    "rejections": {
      "time_gap": 9,
      "day_quota": 4,
      "time_slot": 2,
      "phash_similarity": 3,
      "staypoint_quota": 2,
      "orientation_balance": 1,
      "scene_balance": 2,
      "people_balance": 4
    },
    "drops": {"selection": {"spacing_rejections": 7}}
  }
}
```

Die Map `exclusion_reasons` entspricht der Selektor-Telemetrie und führt immer
alle bekannten Gründe (`time_gap`, `day_quota`, `time_slot`,
`phash_similarity`, `staypoint_quota`, `orientation_balance`,
`scene_balance`, `people_balance`). Wenn kein Ausschluss stattfand, ist der
Zähler `0`.

Neu ist der Block `mmr`, der die Maximal-Marginal-Relevance-Nachbewertung
aufschlüsselt. Er enthält die verwendeten Parameter (`lambda`,
`similarity_floor`, `similarity_cap`, `max_considered`) sowie `pool_size` und
die final gewählte Reihenfolge (`selected`). Unter `iterations` werden die
einzelnen Auswahlrunden festgehalten. Jede Iteration listet sämtliche
Kandidaten mit ihrem Rohscore (`score`), der gemessenen Ähnlichkeit (`raw_similarity`),
dem nach Floor/Cap wirksamen Wert (`penalised_similarity`), dem daraus
resultierenden Abzug (`penalty`) und dem berechneten MMR-Score (`mmr_score`).
Das Flag `selected` innerhalb einer Evaluation zeigt, welcher Kandidat in der
Iteration in die finale Menge übernommen wurde, während `reference` den am
stärksten ähnlichen, bereits platzierten Nachbarn referenziert. So lassen sich
entdoppelte Frames oder Serienaufnahmen retrospektiv nachvollziehen.

Der Abschnitt `day_segments` beschreibt den Tageskontext, den der
`DefaultVacationSegmentAssembler` vor der Draft-Erstellung berechnet. Jeder Tag
erhält einen Core-Score (`score`), die Einordnung als `core` oder `peripheral`,
eine optional normalisierte Tagesdauer (`duration`) und weitere Kennzahlen
unter `metrics`. Die Informationen werden sowohl in der Score-Berechnung als
auch von Tagesquoten-Policies ausgewertet.

`run_metrics` sammelt Laufzeitkennzahlen für den gesamten Urlaubs-Run. Neben
Run-Länge (`run_length_*`) werden der Anteil von Kern- und Randtagen, die
pHash-Verteilung der kuratierten Auswahl, People-Balance-Indikatoren sowie die
POI-Abdeckung ausgewiesen. Unter `selection_profile` landen die zum Lauf
gehörenden Profil-/Quotenwerte (Targets, Abstände, Personensteuerung), damit
Anpassungen per YAML/ENV rückwirkend nachvollzogen werden können. Alle Werte
werden zusätzlich als Monitoring-Event `cluster.vacation/run_metrics`
ausgegeben.

Der Block `thresholds` zeigt die adaptiven Grenzwerte des Selektionslaufs, z.B.
die auf Basis der Tagesklassifikation berechneten Tageskontingente, das
abgeleitete Staypoint-Limit sowie den dynamisch angehobenen pHash-Schwellwert.
Unter `metrics.phash_samples` legt der Selektor die für die pHash-Percentile
genutzten Stichproben (abgeschnitten nach 50 Werten) ab, damit Analysen die
Rohdaten nachvollziehen können.

## Monitoring-Log-Payload

Der `FileJobMonitoringEmitter` schreibt JSON-Zeilen nach
`%memories.monitoring.log_path%`. Jede Zeile enthält das Feld
`schema_version`, dessen Standardwert über den Parameter
`memories.monitoring.schema_version` konfiguriert wird.

Phasenmetriken werden in folgende Top-Level-Schlüssel aufgefächert:

- `phase_counts`: verschachtelte Zähler pro Phase und Messgruppe.
- `phase_medians`: Medianwerte der aufgezeichneten Stichproben.
- `phase_percentiles`: Perzentilzusammenfassung (derzeit `p90` und `p99`).
- `phase_durations_ms`: Ausführungszeit je Phase in Millisekunden.

Beispiel für einen Log-Eintrag:

```json
{
  "job": "cluster_member_selection",
  "status": "completed",
  "schema_version": "2024-08",
  "algorithm": "vacation",
  "members_pre": 96,
  "members_post": 64,
  "profile": "default",
  "phase_counts": {
    "filtering": {"members": {"input": 96, "loaded": 92}},
    "selecting": {
      "members": {"pre": 92, "post": 64, "dropped": 28},
      "rejections": {"time_gap": 9, "phash_similarity": 3}
    },
    "consolidating": {
      "media_types": {"photos": 58, "videos": 6}
    }
  },
  "phase_medians": {
    "consolidating": {
      "spacing_seconds": 840.0,
      "phash_hamming": 12.0
    }
  },
  "phase_percentiles": {
    "consolidating": {
      "spacing_seconds": {"p90": 1800.0, "p99": 2400.0}
    }
  },
  "phase_durations_ms": {
    "filtering": 8.4,
    "summarising": 12.6,
    "selecting": 25.1,
    "consolidating": 14.8
  },
  "timestamp": "2024-08-20T17:12:45+00:00"
}
```

Zusätzlich emittiert der `VacationScoreCalculator` pro Run das Event
`{"job": "cluster.vacation", "status": "run_metrics"}`. Es führt die oben
beschriebenen `run_metrics`-Felder in flacher Form (`run_length_days`,
`phash_sample_count`, `people_unique_count`, `poi_day_ratio`,
`selection_target_total`, …) auf und erlaubt Downstream-Dashboards, Cluster- und
Profilparameter gezielt zu überwachen.

Downstream-Auswerter sollten `schema_version` nutzen, um Schemaänderungen zu
erken­nen, und die Phaseninformationen aus den `phase_*`-Feldern extrahieren.
