# Feature-Namensräume und Semantik

Die Metadatenfeatures eines Mediums werden nicht mehr als flaches Array gepflegt, sondern als Namensräume im `MediaFeatureBag` serialisiert. Jede Gruppe bündelt fachlich zusammenhängende Werte, womit Kollisionen vermieden und Typsicherheit für die Anreicherung erreicht werden.【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L1-L219】【F:src/Entity/Media.php†L585-L610】

## Strukturübersicht

| Namespace | Zweck | Schlüssel | Datentyp | Quelle / Enricher |
| --- | --- | --- | --- | --- |
| `calendar` | Zeit- und Kalenderkontext | `daypart` | `string` (`morning`, `noon`, `evening`, `night`) | `DaypartEnricher` bestimmt Tagesabschnitte aus der lokalen Aufnahmezeit.【F:src/Service/Metadata/DaypartEnricher.php†L34-L59】 |
| | | `dow` | `int` (1=Montag … 7=Sonntag) | Wird gemeinsam mit `isWeekend` durch `CalendarFeatureEnricher` gesetzt.【F:src/Service/Metadata/CalendarFeatureEnricher.php†L32-L69】 |
| | | `isWeekend` | `bool` | `CalendarFeatureEnricher` und Folgeprozesse nutzen den Wert zur Wochenend-Erkennung.【F:src/Service/Metadata/CalendarFeatureEnricher.php†L32-L69】【F:src/Utility/CalendarFeatureHelper.php†L35-L88】 |
| | | `season` | `string` (`winter`, `spring`, `summer`, `autumn`) | Ermittelt aus Monatszahlen, steht für saisonale Cluster-Heuristiken zur Verfügung.【F:src/Service/Metadata/CalendarFeatureEnricher.php†L32-L69】【F:src/Service/Metadata/HeuristicClipSceneTagModel.php†L120-L160】 |
| | | `isHoliday` | `bool` | Kennzeichnet bundeseinheitliche Feiertage, liefert Grundlage für Holiday-Cluster.【F:src/Service/Metadata/CalendarFeatureEnricher.php†L32-L69】 |
| | | `holidayId` | `string` | Normalisierter Identifier `de-*`, dient Persistenz und UI-Labels.【F:src/Service/Metadata/CalendarFeatureEnricher.php†L32-L69】 |
| `solar` | Sonnenstand-/Himmelsphänomene | `isGoldenHour` | `bool` | `SolarEnricher` bewertet Golden-Hour-Intervalle aus Sonnenauf-/-untergang.【F:src/Service/Metadata/SolarEnricher.php†L70-L120】 |
| | | `isPolarDay` | `bool` | Flaggt Polartage für nördliche/ südliche Regionen.【F:src/Service/Metadata/SolarEnricher.php†L70-L120】 |
| | | `isPolarNight` | `bool` | Kennzeichnet Polarnächte bei fehlendem Sonnenaufgang.【F:src/Service/Metadata/SolarEnricher.php†L70-L120】 |
| `file` | Dateinamenheuristiken | `pathTokens` | `list<string>` | Tokenisierte Pfadbestandteile für Klassifizierer und QA.【F:src/Service/Metadata/FilenameKeywordExtractor.php†L30-L52】【F:src/Service/Metadata/ContentClassifierExtractor.php†L250-L308】 |
| | | `filenameHint` | `string` (`normal`, `pano`, `edited`, `timelapse`, `slowmo`) | Ableitung aus Dateinamenmustern für Klassifizierung und Panorama-Erkennung.【F:src/Service/Metadata/FilenameKeywordExtractor.php†L30-L52】 |
| `classification` | Ergebnisse der Inhaltsklassifizierung | `kind` | `string` (`photo`, `screenshot`, `document`, `map`, `screenrecord`, `other`) | Wird vom `ContentClassifierExtractor` gesetzt und über den Feature-Bag typisiert als `ContentKind` verfügbar gemacht.【F:src/Service/Metadata/ContentClassifierExtractor.php†L121-L148】【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L266-L287】 |
| | | `confidence` | `float` (0.0 – 1.0) | Konfidenz der Klassifizierung; Werte außerhalb des Bereichs werden verworfen.【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L290-L317】 |
| | | `shouldHide` | `bool` | Kennzeichnet Medien, die aufgrund der Klassifikation standardmäßig ausgeblendet werden sollen (z. B. Screenshots).【F:src/Service/Metadata/ContentClassifierExtractor.php†L139-L146】【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L319-L337】 |

## Verwendung im Code

- Entitäten konsumieren ausschließlich den `MediaFeatureBag`, womit neue Namespace-Schlüssel zentral validiert werden.【F:src/Entity/Media.php†L585-L610】
- Enricher aktualisieren Features über `setFeatureBag()`, wodurch beim Persistieren automatisch das Namensraumformat entsteht.【F:src/Service/Metadata/DaypartEnricher.php†L34-L59】【F:src/Service/Metadata/SolarEnricher.php†L70-L120】
- Lesezugriffe in Heuristiken nutzen `getFeatureBag()` für typsichere Abfragen, beispielsweise bei Nightlife- und Golden-Hour-Clustern. Die neuen Klassifikations-Accessor-Methoden liefern `ContentKind`, Konfidenz und Sichtbarkeitsflags für Feed- und QA-Prozesse.【F:src/Clusterer/NightlifeEventClusterStrategy.php†L228-L244】【F:src/Clusterer/GoldenHourClusterStrategy.php†L90-L118】【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L266-L337】
- Validierungen im `MediaFeatureBag` stellen sicher, dass nur skalare Werte, Listen oder verschachtelte Maps persistiert werden; ungültige Payloads lösen Exceptions aus.【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L17-L238】
- Tests prüfen weiterhin konkrete Feldwerte, adressieren jedoch die neuen Namensräume und spiegeln damit das Serialisierungsformat wider.【F:test/Integration/Service/Metadata/CompositeMetadataExtractorClusterFieldsTest.php†L118-L135】【F:test/Unit/Service/Metadata/CalendarFeatureEnricherTest.php†L32-L98】【F:test/Unit/Service/Metadata/Feature/MediaFeatureBagTest.php†L13-L68】
- [x] Validierungsregeln stellen sicher, dass `daypart`, `dow`, `season`, `holidayId` und `filenameHint` ausschließlich zulässige Werte speichern; Unit-Tests decken fehlerhafte Eingaben ab.【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L33-L337】【F:test/Unit/Service/Metadata/Feature/MediaFeatureBagTest.php†L17-L184】
- [x] Typisierte Zugriffe und Dokumentation für den Klassifikations-Namespace (`classification`) ergänzen, damit auch diese Werte zentral validiert werden können.【F:src/Service/Metadata/Feature/MediaFeatureBag.php†L266-L337】【F:test/Unit/Service/Metadata/Feature/MediaFeatureBagTest.php†L120-L184】【F:src/Service/Metadata/ContentClassifierExtractor.php†L121-L146】【F:test/Unit/Service/Metadata/ContentClassifierExtractorTest.php†L24-L135】

