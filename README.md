# Simple Rest API

## Installazione

### Tramite Composer create-project

```bash
composer create-project codingspook/simple-rest-api nome-progetto
```

### Setup iniziale

1. **Configura il web server** per puntare alla directory `public/` (se non è già configurato)

2. **Configura la connessione al database** in `config/database.php`

3. **Configura il CORS** in `config/cors.php`

4. **Configura le route** in `routes/index.php`

## Struttura del Progetto

```
nome-progetto/
├── config/
│   ├── database.php     # Configurazione database
│   └── cors.php         # Configurazione CORS
├── routes/
│   └── index.php        # Definizione route
├── public/
│   └── index.php        # Entry point
├── src/
│   ├── bootstrap.php    # Bootstrap dell'applicazione
│   ├── Database/
│   ├── ├── DB.php              # Classe DB
│   │   └── JSONDB.php          # Classe JSONDB
│   ├── Models/
│   │   └── BaseModel.php       # Classe BaseModel
│   └── Utils/
│       ├── Request.php         # Classe Request
│       └── Response.php        # Gestione risposte JSON
├── composer.json        # Dipendenze Composer
└── README.md           # Questo file
```

## Comandi Utili

```bash
# Installa dipendenze
composer install

# Aggiorna autoload dopo aggiunta classi
composer dump-autoload

# Avvia server di sviluppo (PHP built-in)
php -S localhost:8080 -t public
```

## Utilizzo da un altro dispositivo (rete locale)

Questa sezione spiega come accedere all'applicativo da un dispositivo diverso (pc/smartphone) nella stessa rete locale.

### 1) Individua l'indirizzo IP del PC server

- Windows: apri PowerShell o Prompt dei comandi e digita:

```bash
ipconfig
```

Annota l'indirizzo IPv4 della rete in uso (es. `192.168.1.20`).

### 2) Avvia il backend in ascolto su tutte le interfacce

Avvia il server PHP integrato esponendolo sulla rete locale (porta 8080):

```bash
php -S 0.0.0.0:8080 -t public
```

Ora l'API è raggiungibile da altri dispositivi all'URL:

- `http://<IP_DEL_SERVER>:8080/api`

Esempio: `http://192.168.1.20:8080/api`

Se necessario, apri la porta 8080 nel firewall di Windows.

### 3) Configura il CORS per consentire l'accesso dal frontend

In [torneo php/config/cors.php](torneo%20php/config/cors.php) imposta l'origine consentita del frontend, ad esempio:

```php
// Esempio minimale
header('Access-Control-Allow-Origin: http://192.168.1.20:5173');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
```

In alternativa, consenti temporaneamente tutte le origini (solo in ambienti di sviluppo):

```php
header('Access-Control-Allow-Origin: *');
```

### 4) Avvia il frontend (torneo_react) puntando al backend

Nel progetto frontend imposta l'URL del backend e avvia Vite esponendolo sulla rete:

```bash
# Passi tipici
cd torneo_react
npm install

# Crea/aggiorna .env con l'URL del backend
echo VITE_BACKEND_URL=http://192.168.1.20:8080/api > .env

# Avvia Vite esponendo l'host sulla rete locale
npm run dev -- --host
```

Il frontend sarà raggiungibile da altri dispositivi all'URL:

- `http://<IP_DEL_SERVER>:5173`

Esempio: `http://192.168.1.20:5173`

Assicurati che il dispositivo client sia nella stessa rete e che non ci siano blocchi firewall sulla porta 5173.

### 5) Suggerimenti per produzione (opzionale)

- Esegui un web server (Nginx/Apache) per il backend invece del server PHP integrato.
- Esegui il build del frontend e servi i file statici:

```bash
cd torneo_react
npm run build
# Servi la cartella dist con un server statico o integrala nel web server
```

Aggiorna `VITE_BACKEND_URL` verso l'URL pubblico del backend.

## Licenza

MIT

## Supporto

Per domande o problemi, consulta la documentazione o apri una issue sul repository.

---

**Buon coding! 🚀**
