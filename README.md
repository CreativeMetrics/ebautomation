# ebautomation

Automazione sconti regalo per Eventbrite: quando un cliente acquista un biglietto per un evento "trigger", riceve automaticamente via email un codice sconto per uno o più eventi "target" — senza intervento manuale.

Applicazione PHP standalone, zero dipendenze esterne (nessun Composer/npm), pensata per deploy su hosting condiviso via semplice upload della cartella. Storage su SQLite (un solo file), nessun database server da configurare.

## Come funziona

1. Registri l'URL del webhook su Eventbrite.
2. Quando qualcuno acquista un biglietto per un evento trigger, Eventbrite chiama il webhook.
3. L'app crea un codice sconto univoco su Eventbrite per l'evento target e lo invia via email al cliente.
4. Se l'ordine viene rimborsato, il codice sconto viene eliminato automaticamente.

## Requisiti

- **PHP 8.0 o superiore**
- Estensioni PHP: `pdo_sqlite`, `sodium`, `curl`, `mbstring` (quasi sempre già presenti su qualunque hosting PHP moderno)
- **Apache** con supporto `.htaccess` — su Nginx serve una configurazione manuale equivalente per bloccare l'accesso diretto a `database.sqlite`, `*.txt`, ecc. (vedi `ebautomation/.htaccess` per le regole da tradurre)
- Nessun database esterno: SQLite è incluso in PHP, il file si crea da solo al primo avvio

## Installazione

1. **Carica la cartella `ebautomation/`** sul server (FTP, SSH + git clone, o pannello hosting). Non serve installare nulla: niente Composer, niente npm, PHPMailer è già incluso nel repo (`ebautomation/PHPMailer/`).
2. **Verifica i permessi**: la cartella dev'essere scrivibile dal webserver (tipicamente `755`), perché al primo avvio crea da sola `database.sqlite`, `secret.php` (chiave di cifratura), `webhook_log.txt`, `backups/`.
3. **Apri `dashboard.php` nel browser.** Se l'ambiente non è pronto (estensione PHP mancante, permessi sbagliati) l'app lo segnala con un messaggio comprensibile invece di un errore PHP grezzo. Altrimenti parte automaticamente il **wizard di setup**: nome azienda + password (minimo 10 caratteri) → crea l'utente `admin`, genera il token webhook, inizializza il database. Un solo passaggio, nessun file da editare a mano.
4. **Configura Eventbrite e SMTP** (tab *Configurazione*):
   - **Private Token API**: da eventbrite.com → Account → Impostazioni → Chiavi API
   - **Organization ID**: mostrato nel tab *Guida* una volta inserito il token
   - **Credenziali SMTP** per l'invio email (host, porta, cifratura, utente, password)
   - Personalizzazione del template email (oggetto, saluto, testo introduttivo, colore, logo)
5. **Registra il webhook su Eventbrite**: copia l'URL mostrato nel tab *Guida* (già completo di token) e incollalo su Eventbrite → Account → Webhook, selezionando gli eventi:
   - `order.placed`
   - `order.refunded`
   - `order.updated`
6. **Crea le regole sconto** (tab *Regole Sconti*): per ogni evento trigger indichi uno o più eventi target a cui va generato il codice sconto, con percentuale o importo fisso, quantità di utilizzi, scadenza e quantità minima di biglietti trigger — tutti opzionali tranne trigger/target.
7. **Verifica che tutto funzioni**:
   - **Health Check** (tab *Guida*, anche in formato JSON per un monitor esterno: `dashboard.php?action=health&format=json`)
   - **Simulazione Webhook** con un Order ID reale (tab *Guida*): testa l'intero flusso senza aspettare un acquisto vero

### Aggiornamento da un'installazione precedente

Se stai aggiornando da una versione basata su file JSON (`config.json`, `regole_sconti.json`, ecc.), non serve fare nulla di manuale: al primo caricamento della pagina l'app rileva i vecchi file, li importa automaticamente nel database SQLite e li rinomina in `*.migrated` come riferimento. Password, regole e ordini esistenti restano invariati.

## Struttura del progetto

```
ebautomation/
├── dashboard.php            Pannello admin (setup, login, regole, config, log, guida)
├── eventbrite-webhook.php   Endpoint pubblico chiamato da Eventbrite
├── functions.php            Logica condivisa: storage SQLite, cifratura, utenti, retry, ecc.
├── reset-password.php       Recupero password verificando il Private Token API
├── riepilogo.php            Riepilogo configurazione stampabile/PDF
├── sync-events.php          Utility di mappatura eventi per organizer_id
├── PHPMailer/                Libreria email (vendored)
├── .htaccess                 Blocca l'accesso diretto a database.sqlite, log, ecc.
├── database.sqlite           Creato al primo avvio — non versionato
├── secret.php                Chiave di cifratura — creato al primo avvio, non versionato
└── backups/                   Backup automatici (config, regole, database completo)

tests/
├── run.php                   Esegue tutti i casi in tests/cases/
└── cases/                    Test automatizzati (nessuna dipendenza esterna)

.github/workflows/ci.yml      Lint + test suite ad ogni push/PR
```

## Funzionalità principali

- **Regole sconto** con percentuale o importo fisso, quantità utilizzi, scadenza, quantità minima di biglietti trigger, attivazione/disattivazione singola
- **Multi-organizzazione**: un solo token Eventbrite può gestire regole su più organizzazioni — l'app risolve dinamicamente quella corretta per ogni evento target
- **Retry automatico con backoff** su creazione sconti e invio email; ordini non completati finiscono in una coda ritentabile dalla dashboard
- **Gestione `order.updated`**: se un ordine già evaso viene modificato (es. quantità aumentata), l'app rivaluta le regole senza mai revocare sconti già emessi
- **Alert email** all'amministratore quando gli errori superano una soglia configurabile
- **Multi-utente con ruoli**: amministratori (accesso completo) e utenti in sola lettura, ogni azione tracciata in un log di audit
- **Backup automatici**: configurazione, regole e database completo (ogni notte, ultimi 7 conservati)
- **Export/import regole** in JSON o CSV
- **Statistiche giornaliere** ed export CSV degli ordini processati

## Sicurezza

- Password con hash bcrypt, policy minima di robustezza (10 caratteri, blocco password comuni)
- `api_token` e password SMTP cifrati a riposo nel database (libsodium)
- CSRF token su ogni azione della dashboard, rate limiting per IP su login e reset password
- Header di hardening (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy)
- Protezione SSRF sul webhook: accetta solo l'esatto endpoint ordine di Eventbrite
- Timeout di sessione per inattività (30 minuti)
- `.htaccess` blocca l'accesso diretto via browser a database, log e file di configurazione

## Test

```
php tests/run.php
```

Esegue l'intera suite (cifratura, macchina a stati ordini, retry/backoff, migrazione, utenti/ruoli, regole, backup, ecc.), ognuna in un processo PHP isolato con un proprio database temporaneo. Nessuna dipendenza da installare. La stessa suite gira automaticamente in CI (GitHub Actions) ad ogni push.
