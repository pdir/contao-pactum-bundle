# Pactum – AVV/DPA für Contao

[English](README.md)

Mit Pactum schließt du **Auftragsverarbeitungsverträge** (AVV, Art. 28 DSGVO)
mit deinen Kunden online ab, direkt in Contao:

1. Vertragsvorlage anlegen und eine Version veröffentlichen (eingefroren mit
   SHA-256-Prüfsumme).
2. Einladung anlegen und den persönlichen Link an den Kunden senden.
3. Der Kunde erfasst die Firmendaten, bestätigt seine E-Mail-Adresse (Double
   Opt-in), liest den Vertrag und schließt ihn ab.
4. Beide Seiten erhalten den Vertrag als PDF. Die Nachweise (Prüfsummen,
   Audit-Log, Zeitstempel, Erklärungstexte) liegen im Backend und lassen sich
   jederzeit prüfen.

> **Rechtlicher Hinweis:** Pactum stellt ausschließlich die technische Abwicklung
> bereit. Es liefert **keine** rechtlich geprüften Vertragstexte und ist keine
> Rechtsberatung. Ob ein AVV erforderlich ist und ob die elektronische Form in
> deinem Fall genügt, musst du selbst oder mit deiner Rechtsberatung klären. Der
> mitgelieferte Mustertext ist deutlich als Muster gekennzeichnet und muss vor der
> Verwendung geprüft und angepasst werden.

**Status:** 0.1.0 (unveröffentlicht) – MVP, noch nicht produktiv im Einsatz.

## Voraussetzungen

- PHP 8.3+ (Contao 6 benötigt PHP 8.4+)
- Contao 5.7 oder 6.0
- MySQL 8 / MariaDB 10.6+ (InnoDB)
- Funktionierender Mailversand (`MAILER_DSN`)

## Installation

```bash
composer require pdir/contao-pactum-bundle
vendor/bin/contao-console contao:migrate
```

oder über den Contao Manager installieren und anschließend die Datenbank
aktualisieren.

### Einrichtung

1. **Einstellungen (Auftragnehmer):** Lege ein Auftragnehmer-Profil (dein
   Unternehmen) an. Name, Anschrift, Vertretung, Datenschutzbeauftragte/r,
   Kontakt- und Absenderadresse sind Pflicht, bevor ein Einladungslink erzeugt
   werden kann. Mehrere Profile sind möglich.
2. **Zielseite:** Lege eine Seite an (z. B. `/avv`) mit dem Inhaltselement
   **„Pactum: Vertragsabschluss“**. Empfehlung: aus Navigation und Sitemap
   ausblenden. Pactum sendet für diese Seite selbst `X-Robots-Tag: noindex` und
   `no-store`.
3. **Vertragsvorlage:** Vorlage anlegen, Auftragnehmer und Zielseite zuordnen,
   eine Version (Sprache, Text, Anlagen) anlegen und veröffentlichen.
   Optional: `vendor/bin/contao-console pactum:sample:import <vorlagen-id>`
   importiert den Mustertext (DE/EN) als **Entwurf**.
4. **Einladung:** Einladung anlegen (Firma, E-Mail, Sprache), dann über
   „Link erzeugen“ per E-Mail versenden oder den Link kopieren.
5. **Cron:** Stelle sicher, dass der Contao-Cron läuft (`contao:cron` oder
   Web-Cron). Er verschickt die Mails aus der Warteschlange und führt die
   tägliche Bereinigung aus.

### Berechtigungen

Der Zugriff wird pro Backend-Modul in den Benutzer-/Gruppeneinstellungen
vergeben: *Vertragsvorlagen*, *Anlagen*, *Einladungen*, *Abschlüsse*,
*Einstellungen*. So kann z. B. der Vertrieb nur *Einladungen* erhalten, während
nur Admins Vorlagen veröffentlichen.

## Konfiguration

Alle Optionen sind optional (`config/config.yaml`):

```yaml
pactum:
    invitation_ttl_days: 30          # Standard-Laufzeit einer Einladung (pro Vorlage überschreibbar)
    optin_ttl_hours: 24              # Gültigkeit des Opt-in-Links
    ip_mode: anonymized              # none | anonymized | full
    store_user_agent: false          # User-Agent beim Abschluss speichern
    ip_retention_days: 90            # IP / User-Agent nach N Tagen aus Abschlüssen entfernen
    cleanup_after_days: 30           # abgelaufene/widerrufene Einladungen nach N Tagen anonymisieren
    agreement_retention_days: ~      # Abschlüsse nach N Tagen löschen (Standard: nie)
    storage_dir: '%kernel.project_dir%/var/pactum'   # PDF-Ablage, nie öffentlich
    download_ttl_minutes: 15         # Gültigkeit signierter Download-Links im Frontend
```

Die Rate-Limits (`pactum_token`, `pactum_submit`, `pactum_optin_mail`) werden in
`framework.rate_limiter` vorbelegt und lassen sich dort überschreiben.

**Deployment:** `storage_dir` enthält die PDFs (Nachweise). Bei Release-basierten
Deployments (z. B. Deployer) `var/pactum` als Shared-Verzeichnis eintragen und in
die Datensicherung aufnehmen.

## Funktionsweise

### Ablauf und Status

`open` → (Kundendaten + Opt-in) → `opted_in` → (Zustimmung) → `completed`.
Einladungen können außerdem `expired` oder `revoked` werden. Jeder Wechsel wird
von einer State-Machine geprüft und als bedingtes Datenbank-Update ausgeführt.
Parallele Anfragen können eine Einladung daher nicht doppelt abschließen
(zusätzlich gesichert durch einen Unique-Index in der Abschluss-Tabelle).

### Sicherheit

- Das Link-Token hat 256 Bit Entropie; gespeichert wird nur der SHA-256-Hash.
  „Erneut senden“ erzeugt ein neues Token, der alte Link wird ungültig.
- Nach dem Öffnen des Links wird das Token in die Session übernommen und auf die
  URL ohne Token umgeleitet.
- Die Opt-in-Bestätigung erfordert Link-Token **und** Opt-in-Kennung.
- Formulare sind CSRF-geschützt (Contao Request-Token), die öffentlichen
  Endpunkte sind rate-limitiert.
- PDFs liegen außerhalb des Webroots. Downloads erfordern eine kurzlebige
  signierte URL **und** die Session, die den Ablauf durchlaufen hat.
- Vertrags-HTML wird beim Veröffentlichen bereinigt (Allow-List), Kundeneingaben
  werden escaped und Insert-Tag-Klammern neutralisiert.
- Keine personenbezogenen Daten in Logs, URLs oder Exception-Meldungen.

### Nachweise

Beim Veröffentlichen wird der gerenderte Versionstext eingefroren und gehasht.
Beim Abschluss speichert Pactum das finale Dokument-HTML samt Hash, das PDF samt
Hash, die angezeigten Erklärungstexte, die Zeitpunkte von Opt-in und Zustimmung
und (je nach Konfiguration) die anonymisierte IP-Adresse. Alle Schritte landen in
einem nur ergänzbaren Audit-Log.

Abschluss prüfen:

```bash
vendor/bin/contao-console pactum:verify <abschluss-id>
```

Dieselbe Prüfung zeigt die Detailansicht eines Abschlusses im Backend.

### Mails

Mails werden über Symfony Mailer mit der Absenderadresse des Auftragnehmers
verschickt. In Contao 5.7/6.0 erfolgt der Versand **asynchron** über den
Messenger (`contao_prio_high`); der Status „versendet“ bedeutet daher „an den
Mailer übergeben“. Die Bestätigung an den Auftragnehmer geht an die BCC-Adresse
des Profils (Fallback: Kontaktadresse).

Mail-Templates lassen sich im Projekt überschreiben, z. B.
`templates/pactum/mail/invitation.html.twig` und
`templates/pactum/mail/text/invitation.txt.twig`.

### Datenschutz

- Der User-Agent wird standardmäßig nicht gespeichert, die IP-Adresse
  standardmäßig anonymisiert und nach `ip_retention_days` entfernt.
- Nicht abgeschlossene Einladungen werden nach `cleanup_after_days`
  anonymisiert.
- Abschlüsse bleiben bis `agreement_retention_days` erhalten (Standard: dauerhaft).
  Lege eine Aufbewahrungsfrist fest, die zu deinen Pflichten passt.
- Die Bereinigung läuft täglich über den Contao-Cron oder manuell:
  `vendor/bin/contao-console pactum:cleanup`.

### Unterauftragsverarbeiter aus YAML importieren

Die Liste einer Anlage vom Typ „Unterauftragsverarbeiter“ lässt sich aus einer
YAML-Datei übernehmen, z. B. wenn die Liste außerhalb von Contao gepflegt wird:

```bash
vendor/bin/contao-console pactum:subprocessor:import <anlagen-id> subunternehmer.yaml --dry-run
vendor/bin/contao-console pactum:subprocessor:import <anlagen-id> subunternehmer.yaml
```

```yaml
subunternehmer:            # oder: subprocessors, oder eine Liste auf oberster Ebene
  - name: "Hosting GmbH"
    anschrift:             # oder: address (Text oder Liste von Zeilen)
      - "Serverweg 1"
      - "12345 Berlin"
    leistung: "Webhosting" # oder: service
    land: "Deutschland"    # oder: country
    hinweis: ""            # oder: transfer_basis, Pflicht bei Drittländern
```

- Aus dem Land wird „EU/EWR“ oder „Drittland“ abgeleitet (optional überschreibbar
  mit `location: eu | third_country`). Das Land wird als letzte Anschriftszeile
  übernommen.
- Die bisherige Liste der Anlage wird vollständig ersetzt (in einer Transaktion).
  `--dry-run` zeigt nur die Änderungen.
- Veröffentlichte Versionen bleiben unverändert. Damit neue Abschlüsse die Liste
  enthalten, eine neue Version veröffentlichen. Neue Unterauftragsverarbeiter
  müssen Bestandskunden in der Regel vorab angekündigt werden.

## Erweitern

- **Events:** `InvitationCreatedEvent`, `OptInConfirmedEvent`,
  `AgreementCompletedEvent`, `VersionPublishedEvent` (Namespace
  `Pdir\PactumBundle\Event`).
- **Platzhalter:** `Pdir\PactumBundle\Rendering\PlaceholderProviderInterface`
  implementieren (wird automatisch registriert).
- **Benachrichtigungen:** Alias von
  `Pdir\PactumBundle\Notification\NotificationDispatcherInterface` ersetzen, z. B.
  für den Versand über Notification Center.
- **Templates:** Alle Frontend-, Mail- und PDF-Templates sind Twig-Templates im
  Namespace `@Contao` (`pactum/…`) und überschreibbar.

## Abweichungen vom ursprünglichen Konzept

- Backend-Rechte nutzen die regulären Modulrechte statt eines eigenen
  Rechtefelds.
- Backend-Module und DCAs werden über `$GLOBALS['BE_MOD']` / `$GLOBALS['TL_DCA']`
  registriert, weil Contao 5.7/6.0 dafür bei DC_Table-Modulen keine Alternative
  bietet.
- Für die Opt-in-Tokens wird der Opt-in-Service des Contao-Cores genutzt, die
  Opt-in-Mail verschickt aber Pactum selbst (HTML + Text, Auftragnehmer als
  Absender) statt über `send()` des Cores.

## Roadmap

**1.1 (über Events/Interfaces vorbereitet, noch nicht umgesetzt)**

- Re-Consent: Kunden mit älterer Version erkennen und per Massenaktion neu
  einladen; Diff-Ansicht alt/neu im Frontend.
- Änderung von Unterauftragsverarbeitern: Informationsmail an betroffene Kunden,
  Widerspruchsfrist, Widerspruch per Link inkl. Protokoll (Art. 28 Abs. 2 DSGVO).
- Automatische Erinnerungen bei offenen Einladungen (Cron).
- Optionale Notification-Center-Anbindung (`terminal42/notification_center`).
- Mehrere Vertragstypen pro Kunde und Kundenstammdaten als eigene Entität.

**Später**

- Kundenportal mit Übersicht aller eigenen Verträge (Mitglieder-Login).
- Webhooks bei Abschluss (signierte Requests, Retry).
- REST-/JSON-API zum Anlegen von Einladungen aus CRM oder Rechnungssoftware.
- Optionale qualifizierte elektronische Signatur über externe Anbieter
  (separates Add-on-Bundle).
- Export (CSV/XLSX) der Abschlüsse für Audits.

## Screenshots

_Screenshots folgen vor dem ersten Release (Backend: Vorlagen, Einladungen,
Abschlussdetail; Frontend: Daten, Opt-in, Vertrag, Abschluss)._

## Entwicklung

```bash
composer install
composer cs          # ECS
composer phpstan     # PHPStan Level 8
composer test        # Unit-Tests
PACTUM_TEST_DATABASE_URL=mysql://user:pass@127.0.0.1:3306/pactum_test composer test-functional
```

Siehe [CONTRIBUTING.md](CONTRIBUTING.md). Sicherheitslücken: siehe
[SECURITY.md](SECURITY.md).

## Lizenz

LGPL-3.0-or-later, siehe [LICENSE](LICENSE). © pdir GmbH
