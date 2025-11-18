# Release Notes for wallee

## v2.1.7 (2025-11-19)
- Änderungsprotokoll aktualisieren

## v2.1.6 (2025-10-30)
- SDK aktualisiert und neues CA-Bundle hinzugefügt

## v2.1.5 (2025-09-24)
- Plugin-Autor aktualisiert
- Verfügbare Zahlungsmethoden weiter optimiert

## v2.1.4 (2025-09-08)
- Verfügbare Zahlungsmethoden optimiert

## v2.1.3 (2025-06-24)
- Dokumentation aktualisiert: Anleitung zur Durchführung von Teilrückerstattungen

## v2.1.2 (2025-06-18)
- Debugging-Timing-Log entfernt

## v2.1.1 (2025-05-20)
- Debugging-Timing-Log entfernt

## v2.1.0 (2024-10-03)
- Zahlungsmethode „PostFinance Pay“ eingeführt
- Dokumentation aktualisiert

## v2.0.41 (2024-07-10)

## Fixed
- Die Spracheinstellungen bei der Weiterleitung wurden korrigiert, um eine korrekte Lokalisierung zu gewährleisten.

## v2.0.38 (2023-11-28)

### Fixed
- Verschieben Sie die Webhook-Erstellung auf die Plugin-Bereitstellung, um Aufrufe zu minimieren

# Release Notes for wallee

## v2.0.37 (2023-10-24)

### Fixed
- Aktivieren Sie die Konfiguration der Zahlungsmethodenkorrektur, um den Checkout zu beschleunigen

## v2.0.356(2023-09-28)

### Added
- Fügen Sie Debug-Protokolle hinzu, um den Zeitpunkt von API-Aufrufen anzuzeigen

## v2.0.35 (2023-03-09)

### Fixed
- Unnötigen Code entfernt

## v2.0.34 (2023-01-23)

### Fixed
- Stellen Sie die neueste Version des Plugins auf dem Marktplatz zur Verfügung

## v2.0.33 (2023-01-12)

### Fixed
- Verbesserung der Funktion updatePlentyPayment(), um die Eigenschaft *unaccountable* zu prüfen, bevor die Eigenschaft *updateOrderPaymentStatus* aktualisiert wird
- Verbesserung der Funktion updateInvoice(), um die Eigenschaft *unaccountable* zu prüfen, bevor die Eigenschaft *updateOrderPaymentStatus* aktualisiert wird

## v2.0.32 (2023-01-10)

### Fixed
- Rollback der Verbesserungen bei der Webhook-Erstellung

## v2.0.31 (2022-12-13)

### Hinzugefügt
- Weitere Verbesserung von WalleeServiceProviderHelper, die zu stabileren und schnelleren Reaktionszeiten führt, indem die Webhook-Erstellung in die Boot-Funktion verschoben wird
- Header für API-Tracking hinzugefügt
- Französische und italienische Sprachen hinzugefügt

## v2.0.30 (2022-11-29)

### Fixed
- Verbesserung des WalleeServiceProvider, was zu stabileren und schnelleren Reaktionszeiten führt

## v2.0.29 (2022-10-07)

### Fixed
- Dokumentation anpassen (Einrichtungsassistent entfernen)

## v2.0.28 (2022-09-14)

### Fixed
- Duplikat von eindeutigen Werbebuchungen behoben

## v2.0.27 (2022-09-13)

### Fixed
- Duplikat von eindeutigen Werbebuchungen behoben

## v2.0.26 (2022-09-01)

### Fixed
- leere Positionsattributbezeichnung korrigiert (verbessert)

## v2.0.25 (2022-09-01)

### Fixed
- Korrigieren Sie das leere Label des Positionsattributs

## v2.0.24 (2022-08-19)

### Fixed
- Fix für falsche Konstante

## v2.0.23 (2022-08-18)

### Fixed
- Fix für Rechnungserfassung, die nicht synchronisiert wird

## v2.0.22 (2022-07-12)

### Fixed
- Übersetzungen für Label

## v2.0.21 (2022-07-05)

### Fixed
- benutzerdefinierte Zahlungssymbole zulassen

## v2.0.20 (2021-06-21)

### Fixed
- Aktualisieren Sie das SDK auf die neueste Version.

## v2.0.19 (2021-06-21)

### Fixed
- SDK-Update rückgängig machen

## v2.0.17 (2021-06-21)

### Fixed
- Zusätzlicher Filter für Bestellungen, die nicht länger als 3 Monate sind, wurde hinzugefügt, damit IDs nicht dupliziert werden.

## v2.0.16 (2021-02-26)

### Fixed
- Dokumentation aktualisieren.

## v2.0.15 (2021-02-25)

### Fixed
- Aktualisiere plugin.json.

## v2.0.14 (2021-02-16)

### Hinzugefügt
- Einstellung hinzugefügt, um Bestellstatus zu konfigurieren, die es ermöglichen, die Zahlungsmethode zu wechseln.

## v2.0.13 (2020-12-02)

### Fixed
- Längere Positionsnamen zulassen.

## v2.0.12 (2020-05-20)

### Fixed
- Korrigieren Sie den Übersetzungsschlüssel auf der Zahlungsfehlerseite.

## v2.0.11 (2020-05-05)

### Fixed
- Behebung eines Fehlers, der verhindert, dass der Kunde eine andere Zahlungsmethode auswählt, wenn die Zahlung fehlgeschlagen ist.

## v2.0.10 (2020-03-18)

### Fixed
- Vermeiden Sie mehrere Zahlungen für eine Bestellung.

## v2.0.9 (2020-02-10)

### Fixed
- Aktualisieren Sie das SDK auf die neueste Version.
- Korrigieren Sie den falschen Übersetzungsschlüssel.

## v2.0.8 (2019-12-05)

### Fixed
- Aktualisieren Sie das SDK auf die neueste Version.

## v2.0.7 (2019-11-07)

### Fixed
- Fehler in der Webhook-Verarbeitung behoben.

## v2.0.6 (2019-09-05)

### Fixed
- Verwenden Sie die Beträge in der Bestellwährung.

## v2.0.5 (2019-07-31)

### Hinzugefügt
- Legen Sie Bestellartikel-Eigenschaftswerte für Werbebuchungen fest.

## v2.0.4 (2019-07-04)

### Fixed
- Fehler in der Einzelpostenberechnung behoben.

## v2.0.3 (2019-05-14)

### Fixed
- Reparieren Sie die Rückerstattungsverarbeitung.
- Ignorieren Sie Webhooks mit Links zu nicht existierenden Entitäten.

## v2.0.2 (2019-04-18)

### Fixed
- Beheben Sie einen Fehler, der zu einem Fehler bei der Rückerstattung führte.

## v2.0.1 (2019-04-08)

### Fixed
- Verbesserte Zuordnung von Transaktionsstatus zu Zahlungsstatus im plentymarkets Shop.

## v2.0.0 (2019-03-21)

### Hinzugefügt
- Ermöglichen Sie Kunden, die Zahlungsmethode zu ändern, wenn eine Zahlung fehlschlägt.
- Erlauben Sie Kunden, Rechnungsdokumente und Lieferscheine von der Bestellbestätigungsseite herunterzuladen.

### Fixed
- Erstellen Sie eine Bestellung, bevor Sie den Kunden auf die Zahlungsseite umleiten.

## v1.0.23 (2019-03-01)

### Fixed
- Korrigieren Sie die Berechnung der Versandsteuer.

## v1.0.22 (2019-02-15)

### Fixed
- Korrigieren Sie die Berechnung der Versandsteuer.

## v1.0.21 (2019-02-15)

### Fixed
- Teilbeträge rückerstatten lassen.
- Stellen Sie sicher, dass die Transaktionssumme korrekt ist.

## v1.0.20 (2019-01-16)

### Fixed
- Status der Rückerstattungszahlungen aktualisieren.

## v1.0.19 (2018-12-12)

### Fixed
- URL-Einstellungen in Bezug auf abschließende Schrägstriche beachten.

## v1.0.18 (2018-12-07)

### Fixed
- Aktualisieren Sie die Protokollierungsebenen.

## v1.0.17 (2018-11-30)

### Fixed
- Fixe Berechnung der Nettowarenkorbbeträge.

## v1.0.16 (2018-11-22)

### Fixed
- Preisberechnung für Einzelposten korrigiert.

## v1.0.15 (2018-11-07)

### Fixed
- Protokollierung zum Controller für Transaktionsfehler hinzugefügt.

## v1.0.14 (2018-10-23)

### Fixed
- Zeigen Sie dem Kunden den Grund für das Scheitern der Transaktion.

## v1.0.12 (2018-10-16)

### Fixed
- Erstellen Sie eine Zahlung in plentymarkets für Rückerstattungen.

## v1.0.11 (2018-10-15)

### Fixed
- Verarbeiten Sie die Benachrichtigung über einen Cron-Job.

## v1.0.10 (2018-06-19)

### Fixed
- Behobene Fehler.

## v1.0.9 (2018-04-17)

### Fixed
- Filter für ungültige Geburtstagswerte bei Adressen hinzugefügt.
- Produkte als versandfähig kennzeichnen.
- Übergeben Sie das Geschlecht von plentymarkets.

## v1.0.8 (2018-04-16)

### Fixed
- Behobene Fehler.

## v1.0.7 (2018-03-08)

### Fixed
- Kompatibilität für Ceres 2.4.0 hinzugefügt

## v1.0.6 (2018-01-30)

### Fixed
- Korrigieren Sie den Pfad der Bilder der Zahlungsmethode.

## v1.0.3 (2017-12-14)

### Fixed
- Kompatibilität für Ceres 2.0.2 hinzugefügt

## v1.0.2 (2017-09-05)

### Hinzugefügt
- Aktualisierte Beschreibungen und Screenshots
- Aktualisierte URL für die Verarbeitung
